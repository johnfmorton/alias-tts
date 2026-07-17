<?php

namespace App\Services\Credit;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\GenerationCost;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of users.credit_balance_micro and the credit ledger.
 *
 * Money is integer micro-dollars (1,000,000 = $1) so charges stay exact:
 * at the default $0.025/1k rate a character costs exactly 25 micro. A charge
 * rounds UP once per charge event (per take / per segment / per run) — never
 * per character — so billing never undercharges and never compounds rounding.
 *
 * The balance lives in the MARKED-UP domain: a $5 grant buys $5 of
 * marked-up generation, and every charge row stores both the marked-up
 * amount (what the user sees) and the actual provider cost (what the owner
 * pays). Unlimited users (NULL balance) still get charge rows — the owner
 * can always see what an account consumed — but no decrement and no
 * enforcement. Enforcement is a pre-generation gate only ({@see canSpend});
 * work that started under budget finishes even if it pushes the balance
 * negative, which is why the column is signed.
 */
final class CreditService
{
    public const MICRO_PER_DOLLAR = 1_000_000;

    /** Shared by every enforcement surface (middleware, panel 402s, job failures). */
    public const OUT_OF_CREDIT_MESSAGE = 'This account is out of credit. New speech generation is paused until an administrator adds more — existing audio can still be played and downloaded.';

    /**
     * User-facing price multiplier over the actual per-model provider rates.
     * Env-only (TTS_CREDIT_MARKUP) and clamped here to >= 1.0 — deliberately
     * NOT a Settings-page key, since users edit their own settings.
     */
    public function markup(): float
    {
        return max(1.0, (float) config('tts.credit.markup', 1.0));
    }

    /**
     * May this user START new generation? NULL user (ownerless internal work)
     * and NULL balance (unlimited) always may; otherwise the balance must be
     * positive. Zero or negative blocks new work only — finished audio stays
     * usable everywhere.
     */
    public function canSpend(?User $user): bool
    {
        if ($user === null || $user->credit_balance_micro === null) {
            return true;
        }

        return $user->credit_balance_micro > 0;
    }

    /**
     * Record one billable provider render and debit the owner. Always writes
     * the ledger row (unlimited users included, for visibility); only limited
     * users are decremented, atomically, guarded by whereNotNull so a
     * concurrent make-unlimited can't resurrect a balance. Call AFTER a
     * successful provider call — a failed or fail-fast render never charges.
     * A zero-rate install (all cost_per_1k_chars = 0) books nothing at all.
     */
    public function charge(
        ?int $userId,
        int $characters,
        string $model,
        string $source,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): void {
        if ($characters <= 0) {
            return;
        }

        $actual = self::costMicro($characters, $model);
        $charged = (int) ceil($actual * $this->markup());

        if ($charged <= 0) {
            return;
        }

        CreditTransaction::create([
            'user_id' => $userId,
            'type' => CreditTransaction::TYPE_CHARGE,
            'source' => $source,
            'amount_micro' => -$charged,
            'actual_cost_micro' => $actual,
            'characters' => $characters,
            'model' => $model,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        if ($userId !== null) {
            User::whereKey($userId)
                ->whereNotNull('credit_balance_micro')
                ->decrement('credit_balance_micro', $charged);
        }
    }

    /**
     * Grant (positive) or manually adjust (negative) a user's balance, in
     * marked-up micro-dollars. Granting to an unlimited user starts metering
     * from zero — that's the switch from unlimited to limited.
     */
    public function grant(User $user, int $amountMicro, User $actor, ?string $note = null): CreditTransaction
    {
        return DB::transaction(function () use ($user, $amountMicro, $actor, $note) {
            $fresh = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $transaction = CreditTransaction::create([
                'user_id' => $fresh->getKey(),
                'type' => $amountMicro >= 0 ? CreditTransaction::TYPE_GRANT : CreditTransaction::TYPE_ADJUSTMENT,
                'source' => $amountMicro >= 0 ? 'grant' : 'adjustment',
                'amount_micro' => $amountMicro,
                'note' => $note,
                'created_by' => $actor->getKey(),
            ]);

            $fresh->forceFill([
                'credit_balance_micro' => ($fresh->credit_balance_micro ?? 0) + $amountMicro,
            ])->save();

            $user->credit_balance_micro = $fresh->credit_balance_micro;

            return $transaction;
        });
    }

    /** Clear the balance back to NULL (unlimited), leaving an audit row. */
    public function makeUnlimited(User $user, User $actor): void
    {
        CreditTransaction::create([
            'user_id' => $user->getKey(),
            'type' => CreditTransaction::TYPE_ADJUSTMENT,
            'source' => 'adjustment',
            'amount_micro' => 0,
            'note' => 'Balance cleared — account set to unlimited',
            'created_by' => $actor->getKey(),
        ]);

        $user->forceFill(['credit_balance_micro' => null])->save();
    }

    /**
     * Lifetime charge sums for one user: what they were billed (marked-up)
     * vs what their usage actually cost the owner.
     *
     * @return array{charged_micro: int, actual_micro: int}
     */
    public function chargedTotals(User $user): array
    {
        $row = CreditTransaction::query()
            ->where('user_id', $user->getKey())
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->selectRaw('COALESCE(SUM(-amount_micro), 0) AS charged, COALESCE(SUM(actual_cost_micro), 0) AS actual')
            ->first();

        return [
            'charged_micro' => (int) ($row->charged ?? 0),
            'actual_micro' => (int) ($row->actual ?? 0),
        ];
    }

    /** Actual provider cost of a render in micro-dollars, rounded up once. */
    public static function costMicro(int $characters, string $model): int
    {
        // rate is $ per 1,000 chars; ×1000 converts straight to micro/char
        // sums. The epsilon keeps binary float representation (0.0333 × 100 ×
        // 1000 = 3330.0000000000005) from ceiling an exact product up a micro.
        return (int) ceil(max(0, $characters) * GenerationCost::ratePer1k($model) * 1000 - 1e-7);
    }

    /** "$5" / "5.00" / 5.0 (dollars) → micro-dollars. */
    public static function toMicro(float|string $dollars): int
    {
        return (int) round((float) $dollars * self::MICRO_PER_DOLLAR);
    }

    /** NULL → "Unlimited"; otherwise "$4.38" / "-$0.02". */
    public static function formatMicro(?int $micro): string
    {
        if ($micro === null) {
            return 'Unlimited';
        }

        $sign = $micro < 0 ? '-' : '';

        return $sign.'$'.number_format(abs($micro) / self::MICRO_PER_DOLLAR, 2);
    }
}
