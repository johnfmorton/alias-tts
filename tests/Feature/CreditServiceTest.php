<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credit\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The credit engine itself: integer micro-dollar money math, the
 * NULL-balance = unlimited convention, and the ledger/balance invariants
 * (every charge is a row; only limited users are decremented; grants and
 * make-unlimited leave audit rows). Flow-level charging lives in
 * CreditChargingTest; enforcement in CreditEnforcementTest.
 */
class CreditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.models.chatterbox.cost_per_1k_chars' => 0.025,
            'tts.credit.markup' => 1.0,
        ]);
    }

    private function service(): CreditService
    {
        return app(CreditService::class);
    }

    private function userWithBalance(?int $micro): User
    {
        $user = User::factory()->create();
        $user->forceFill(['credit_balance_micro' => $micro])->save();

        return $user;
    }

    public function test_micro_conversions(): void
    {
        $this->assertSame(5_000_000, CreditService::toMicro('5'));
        $this->assertSame(5_000_000, CreditService::toMicro('5.00'));
        $this->assertSame(10_000, CreditService::toMicro(0.01));
        $this->assertSame(-2_500_000, CreditService::toMicro('-2.5'));
    }

    public function test_format_micro(): void
    {
        $this->assertSame('Unlimited', CreditService::formatMicro(null));
        $this->assertSame('$4.38', CreditService::formatMicro(4_380_000));
        $this->assertSame('-$0.02', CreditService::formatMicro(-20_000));
        $this->assertSame('$0.00', CreditService::formatMicro(0));
    }

    public function test_cost_micro_prices_by_model_rate(): void
    {
        // $0.025/1k = exactly 25 micro per character — integer-exact.
        $this->assertSame(25_000, CreditService::costMicro(1000, 'chatterbox'));
        $this->assertSame(25, CreditService::costMicro(1, 'chatterbox'));
    }

    public function test_cost_micro_rounds_up_once_per_charge(): void
    {
        config(['tts.models.chatterbox.cost_per_1k_chars' => 0.0333]);

        // 1 char = 33.3 micro → ceil to 34; 100 chars = 3330 exactly, so a
        // single ceil per charge never compounds per-character rounding.
        $this->assertSame(34, CreditService::costMicro(1, 'chatterbox'));
        $this->assertSame(3330, CreditService::costMicro(100, 'chatterbox'));
    }

    public function test_markup_is_clamped_to_at_least_one(): void
    {
        config(['tts.credit.markup' => 0.5]);
        $this->assertSame(1.0, $this->service()->markup());

        config(['tts.credit.markup' => 2.0]);
        $this->assertSame(2.0, $this->service()->markup());
    }

    public function test_can_spend_truth_table(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->canSpend(null));                            // ownerless work
        $this->assertTrue($svc->canSpend($this->userWithBalance(null)));    // unlimited
        $this->assertTrue($svc->canSpend($this->userWithBalance(1)));       // any positive
        $this->assertFalse($svc->canSpend($this->userWithBalance(0)));      // drained
        $this->assertFalse($svc->canSpend($this->userWithBalance(-5_000))); // overshot
    }

    public function test_charge_debits_a_limited_user_and_records_both_sides(): void
    {
        config(['tts.credit.markup' => 2.0]);
        $user = $this->userWithBalance(1_000_000);

        $this->service()->charge($user->id, 1000, 'chatterbox', 'api', 'speech', 'abc');

        $tx = CreditTransaction::sole();
        $this->assertSame(CreditTransaction::TYPE_CHARGE, $tx->type);
        $this->assertSame(25_000, $tx->actual_cost_micro); // the owner's provider cost
        $this->assertSame(-50_000, $tx->amount_micro);     // 2× marked up, negative
        $this->assertSame(1000, $tx->characters);
        $this->assertSame('chatterbox', $tx->model);
        $this->assertSame(['speech', 'abc'], [$tx->reference_type, $tx->reference_id]);

        $this->assertSame(950_000, $user->fresh()->credit_balance_micro);
    }

    public function test_charge_records_but_never_decrements_an_unlimited_user(): void
    {
        $user = $this->userWithBalance(null);

        $this->service()->charge($user->id, 1000, 'chatterbox', 'api');

        $this->assertSame(1, CreditTransaction::count());
        $this->assertNull($user->fresh()->credit_balance_micro);
    }

    public function test_charge_can_push_a_balance_negative(): void
    {
        // The pre-generation gate is the only stop — a render that started
        // under budget finishes and the balance legitimately goes negative.
        $user = $this->userWithBalance(10_000);

        $this->service()->charge($user->id, 1000, 'chatterbox', 'api');

        $this->assertSame(10_000 - 25_000, $user->fresh()->credit_balance_micro);
    }

    public function test_zero_rate_books_nothing_at_all(): void
    {
        config(['tts.models.chatterbox.cost_per_1k_chars' => 0]);
        $user = $this->userWithBalance(1_000_000);

        $this->service()->charge($user->id, 1000, 'chatterbox', 'api');

        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame(1_000_000, $user->fresh()->credit_balance_micro);
    }

    public function test_grant_starts_metering_an_unlimited_account(): void
    {
        $user = $this->userWithBalance(null);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $tx = $this->service()->grant($user, 5_000_000, $admin, 'trial');

        $this->assertSame(CreditTransaction::TYPE_GRANT, $tx->type);
        $this->assertSame($admin->id, $tx->created_by);
        $this->assertSame('trial', $tx->note);
        $this->assertSame(5_000_000, $user->fresh()->credit_balance_micro);
    }

    public function test_a_negative_grant_is_an_adjustment(): void
    {
        $user = $this->userWithBalance(5_000_000);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $tx = $this->service()->grant($user, -2_000_000, $admin);

        $this->assertSame(CreditTransaction::TYPE_ADJUSTMENT, $tx->type);
        $this->assertSame(3_000_000, $user->fresh()->credit_balance_micro);
    }

    public function test_make_unlimited_clears_the_balance_and_leaves_an_audit_row(): void
    {
        $user = $this->userWithBalance(1_000_000);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->service()->makeUnlimited($user, $admin);

        $this->assertNull($user->fresh()->credit_balance_micro);
        $tx = CreditTransaction::sole();
        $this->assertSame(CreditTransaction::TYPE_ADJUSTMENT, $tx->type);
        $this->assertSame(0, $tx->amount_micro);
    }

    public function test_charged_totals_sums_both_sides_of_the_markup(): void
    {
        config(['tts.credit.markup' => 2.0]);
        $user = $this->userWithBalance(1_000_000);
        $svc = $this->service();

        $svc->charge($user->id, 1000, 'chatterbox', 'api');
        $svc->charge($user->id, 500, 'chatterbox', 'api');
        $svc->grant($user, 1_000_000, User::factory()->create()); // grants excluded

        $this->assertSame(
            ['charged_micro' => 75_000, 'actual_micro' => 37_500],
            $svc->chargedTotals($user),
        );
    }
}
