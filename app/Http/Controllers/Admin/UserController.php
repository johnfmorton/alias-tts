<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\InvitationController;
use App\Models\CreditTransaction;
use App\Models\Speech;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\Credit\CreditService;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SuperAdmin-only user management. Everything here is gated by
 * EnsureUserIsSuperAdmin at the route level. The screen is a table; each row
 * opens a full detail page (design "Ledger-first" 2A) — one filterable
 * timeline of grants, usage, and account events, with the controls in a
 * right rail. Actions are plain form POSTs that land back on the detail page.
 */
class UserController extends Controller
{
    /** Timeline page size; "View older" grows the window by this much. */
    private const TIMELINE_PAGE = 30;

    public function __construct(private readonly CreditService $credit) {}

    public function index(Request $request): View|RedirectResponse
    {
        // The detail used to be a drawer opened with `?user=`; keep old links
        // (bookmarks, flashed redirects) working by forwarding to the page.
        if ($request->filled('user')) {
            $user = User::find($request->integer('user'));

            if ($user) {
                return redirect()->route('admin.users.show', $user);
            }
        }

        $users = User::query()
            ->orderByDesc('is_super_admin')
            ->orderBy('name')
            ->get();

        // One query each for the per-user counts, keyed by user id — no N+1.
        $gens = Speech::query()
            ->join('api_keys', 'speeches.api_key_id', '=', 'api_keys.id')
            ->whereNotNull('api_keys.user_id')
            ->groupBy('api_keys.user_id')
            ->selectRaw('api_keys.user_id as uid, count(*) as c')
            ->pluck('c', 'uid');

        // Lifetime spend per user in ONE grouped query (the list version of
        // chargedTotals): `billed` = marked-up micro the user was charged,
        // `actual` = the provider cost to the owner. Keyed by user id.
        $spend = CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_CHARGE)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id as uid, COALESCE(SUM(-amount_micro), 0) as billed, COALESCE(SUM(actual_cost_micro), 0) as actual')
            ->get()
            ->keyBy('uid');

        return view('admin.users.index', [
            'users' => $users,
            'gens' => $gens,
            'spend' => $spend,
            'activeCount' => $users->where('status', User::STATUS_ACTIVE)->count(),
            'invitedCount' => $users->where('status', User::STATUS_INVITED)->count(),
        ]);
    }

    /**
     * The user detail page — an account statement (design 2A): every grant,
     * charge, and account event interleaved into one filterable timeline,
     * with the three controls (credit / account / danger) in a right rail.
     */
    public function show(Request $request, User $user): View
    {
        $filter = $request->query('filter');
        $filter = in_array($filter, ['grants', 'usage', 'account'], true) ? $filter : 'all';

        $limit = max(self::TIMELINE_PAGE, min(1000, $request->integer('limit') ?: self::TIMELINE_PAGE));

        [$events, $total] = $this->timeline($user, $filter, $limit);

        return view('admin.users.show', [
            'u' => $user,
            'events' => $events,
            'filter' => $filter,
            'limit' => $limit,
            'total' => $total,
            'creditTotals' => $this->credit->chargedTotals($user),
            'isSelf' => $user->is($request->user()),
            // Guards the rail renders as explanatory disabled states.
            'lastSuperAdmin' => $user->isSuperAdmin() && $this->superAdminCount() <= 1,
            'lastActiveSuperAdmin' => $user->isSuperAdmin()
                && $user->status === User::STATUS_ACTIVE
                && $this->activeSuperAdminCount() <= 1,
        ]);
    }

    /**
     * Merge the credit ledger and account events into one newest-first list.
     * Each entry is a display-ready array: kind (grant|usage|account), title,
     * detail, amount_micro (null for non-money rows), and the timestamp.
     *
     * @return array{0: Collection, 1: int}
     */
    private function timeline(User $user, string $filter, int $limit): array
    {
        $money = collect();
        $account = collect();

        if ($filter !== 'account') {
            $types = match ($filter) {
                'grants' => [CreditTransaction::TYPE_GRANT, CreditTransaction::TYPE_ADJUSTMENT],
                'usage' => [CreditTransaction::TYPE_CHARGE],
                default => null,
            };

            $money = CreditTransaction::query()
                ->where('user_id', $user->id)
                ->when($types, fn ($q) => $q->whereIn('type', $types))
                ->with('creator:id,name')
                ->latest('id')
                ->limit($limit + 1)
                ->get()
                ->map(fn (CreditTransaction $tx) => $this->moneyEvent($tx));
        }

        if ($filter === 'all' || $filter === 'account') {
            $account = $user->events()
                ->with('actor:id,name')
                ->latest('id')
                ->limit($limit + 1)
                ->get()
                ->map(fn (UserEvent $event) => $this->accountEvent($event));

            // Users predating the audit table have no 'created' row — derive
            // one from the account itself so every statement has a first line.
            $hasOrigin = $user->events()
                ->whereIn('kind', [UserEvent::KIND_CREATED, UserEvent::KIND_INVITED])
                ->exists();

            if (! $hasOrigin) {
                $account->push([
                    'kind' => 'account',
                    'title' => 'Account created',
                    'detail' => null,
                    'amount_micro' => null,
                    'at' => $user->created_at,
                ]);
            }
        }

        $merged = $money->concat($account)->sortByDesc('at')->values();

        return [$merged->take($limit), $merged->count()];
    }

    /** @return array{kind: string, title: string, detail: ?string, amount_micro: ?int, at: CarbonInterface} */
    private function moneyEvent(CreditTransaction $tx): array
    {
        $by = $tx->creator ? 'by '.$tx->creator->name : null;

        if ($tx->type === CreditTransaction::TYPE_CHARGE) {
            $detail = collect([
                $tx->characters ? number_format($tx->characters).' chars' : null,
                $tx->model,
            ])->filter()->implode(' · ');

            return [
                'kind' => 'usage',
                'title' => $tx->source,
                'detail' => $detail ?: null,
                'amount_micro' => $tx->amount_micro,
                'at' => $tx->created_at,
            ];
        }

        // Grants and adjustments. The zero-amount adjustment is the ledger's
        // "cleared back to unlimited" marker — money-adjacent, so it stays in
        // the Grants lane but reads as an act, not an amount.
        if ($tx->type === CreditTransaction::TYPE_ADJUSTMENT && $tx->amount_micro === 0) {
            return [
                'kind' => 'grant',
                'title' => 'Made unlimited',
                'detail' => $by,
                'amount_micro' => null,
                'at' => $tx->created_at,
            ];
        }

        $detail = collect([
            $tx->note ? '"'.$tx->note.'"' : null,
            $by,
        ])->filter()->implode(' · ');

        return [
            'kind' => 'grant',
            'title' => $tx->amount_micro < 0 ? 'Credit adjusted' : 'Credit granted',
            'detail' => $detail ?: null,
            'amount_micro' => $tx->amount_micro,
            'at' => $tx->created_at,
        ];
    }

    /** @return array{kind: string, title: string, detail: ?string, amount_micro: null, at: CarbonInterface} */
    private function accountEvent(UserEvent $event): array
    {
        $meta = $event->meta ?? [];

        [$title, $kind] = match ($event->kind) {
            UserEvent::KIND_ROLE_CHANGE => ['Role changed to '.($meta['to'] ?? '?'), 'change'],
            UserEvent::KIND_STATUS_CHANGE => [($meta['to'] ?? '') === 'Inactive' ? 'Account deactivated' : 'Account reactivated', 'change'],
            UserEvent::KIND_PASSWORD_RESET => ['Password reset forced', 'account'],
            UserEvent::KIND_INVITED => ['Account created', 'account'],
            default => ['Account created', 'account'],
        };

        $detail = $event->kind === UserEvent::KIND_INVITED
            ? ($event->actor ? 'invited by '.$event->actor->name : 'invited')
            : ($event->actor ? 'by '.$event->actor->name : null);

        return [
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'amount_micro' => null,
            'at' => $event->created_at,
        ];
    }

    /**
     * Grant credit (positive dollars) or adjust it (negative). The balance is
     * denominated in what USERS are charged (marked-up), so "$5" buys $5 of
     * their pricing. Granting to an unlimited account starts metering it.
     */
    public function grantCredit(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-10000,10000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->credit->grant(
            $user,
            CreditService::toMicro($data['amount']),
            $request->user(),
            $data['note'] ?? null,
        );

        $verb = (float) $data['amount'] > 0 ? 'Added' : 'Adjusted by';

        return $this->backToUser($user, success: sprintf(
            '%s %s — %s\'s balance is now %s.',
            $verb,
            CreditService::formatMicro(CreditService::toMicro($data['amount'])),
            $user->name,
            CreditService::formatMicro($user->credit_balance_micro),
        ));
    }

    /** Clear the balance back to unlimited (NULL); the ledger keeps an audit row. */
    public function unlimitedCredit(Request $request, User $user): RedirectResponse
    {
        if (! $user->hasLimitedCredit()) {
            return $this->backToUser($user, error: $user->name.'\'s account is already unlimited.');
        }

        $this->credit->makeUnlimited($user, $request->user());

        return $this->backToUser($user, success: $user->name.'\'s account is now unlimited.');
    }

    /** Create a user with a temporary password shown once to the admin. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['User', 'SuperAdmin'])],
        ]);

        $temp = Str::password(16);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $temp,
            'is_super_admin' => $data['role'] === 'SuperAdmin',
            'status' => User::STATUS_ACTIVE,
        ]);

        UserEvent::record($user, UserEvent::KIND_CREATED, $request->user());

        // Two ways in: the friend can click the set-password link and choose their
        // own password, or sign in with the temporary password as a fallback.
        return redirect()->route('admin.users.index')
            ->with('success', "Created {$data['email']}. Send them the set-password link, or share the temporary password as a fallback.")
            ->with('reveals', [
                ['label' => "Set-password link for {$data['email']}", 'value' => $this->setPasswordLink($user)],
                ['label' => "Temporary password for {$data['email']}", 'value' => $temp],
            ]);
    }

    /** Invite a user by email — they set their own password via a signed link. */
    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['User', 'SuperAdmin'])],
        ]);

        $user = User::create([
            'name' => Str::before($data['email'], '@'),
            'email' => $data['email'],
            'password' => Str::random(40), // unusable until they accept the invite
            'is_super_admin' => $data['role'] === 'SuperAdmin',
            'status' => User::STATUS_INVITED,
        ]);

        UserEvent::record($user, UserEvent::KIND_INVITED, $request->user());

        return redirect()->route('admin.users.index')
            ->with('success', "Invited {$data['email']}. Send them this link to set a password.")
            ->with('reveals', [
                ['label' => "Invite link for {$data['email']}", 'value' => $this->setPasswordLink($user)],
            ]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $request->validate(['role' => ['required', Rule::in(['User', 'SuperAdmin'])]]);
        $makeSuper = $request->input('role') === 'SuperAdmin';

        if ($user->is($request->user())) {
            return $this->backToUser($user, error: 'Use another SuperAdmin account to change your own role.');
        }

        if (! $makeSuper && $user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            return $this->backToUser($user, error: 'At least one SuperAdmin must remain.');
        }

        if ($makeSuper === $user->isSuperAdmin()) {
            return $this->backToUser($user, success: $user->name.' is already a '.$user->roleLabel().'.');
        }

        $from = $user->roleLabel();
        $user->update(['is_super_admin' => $makeSuper]);

        UserEvent::record($user, UserEvent::KIND_ROLE_CHANGE, $request->user(), [
            'from' => $from,
            'to' => $user->roleLabel(),
        ]);

        return $this->backToUser($user, success: $user->name.' is now a '.($makeSuper ? 'SuperAdmin' : 'User').'.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return $this->backToUser($user, error: "You can't suspend your own account.");
        }

        if (! $user->isSuspended() && $user->isSuperAdmin() && $this->activeSuperAdminCount() <= 1) {
            return $this->backToUser($user, error: 'At least one active SuperAdmin must remain.');
        }

        $wasSuspended = $user->isSuspended();

        $user->update([
            'status' => $wasSuspended ? User::STATUS_ACTIVE : User::STATUS_SUSPENDED,
        ]);

        // The UI vocabulary is Active/Inactive (design 2A); 'suspended' stays
        // the stored value so existing rows and checks keep working.
        UserEvent::record($user, UserEvent::KIND_STATUS_CHANGE, $request->user(), [
            'from' => $wasSuspended ? 'Inactive' : 'Active',
            'to' => $wasSuspended ? 'Active' : 'Inactive',
        ]);

        return $this->backToUser($user, success: $user->isSuspended()
            ? $user->name.' has been deactivated.'
            : $user->name.' has been reactivated.');
    }

    /** Invalidate the user's password and hand back a signed set-password link. */
    public function forceReset(Request $request, User $user): RedirectResponse
    {
        $user->update([
            'password' => Str::random(40),
            'status' => $user->isSuspended() ? User::STATUS_SUSPENDED : User::STATUS_ACTIVE,
        ]);

        UserEvent::record($user, UserEvent::KIND_PASSWORD_RESET, $request->user());

        return $this->backToUser(
            $user,
            success: "{$user->name}'s password was reset. Share this link so they can set a new one.",
        )->with('reveals', [
            ['label' => "Set-password link for {$user->email}", 'value' => $this->setPasswordLink($user)],
        ]);
    }

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return $this->backToUser($user, error: "You're already signed in as yourself.");
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            return $this->backToUser($user, error: 'Only active users can be impersonated.');
        }

        $request->session()->put('impersonator_id', $request->user()->id);
        Log::info('impersonation.start', ['by' => $request->user()->id, 'target' => $user->id]);

        Auth::login($user);

        return redirect()->route('admin.dashboard')
            ->with('success', "You are now signed in as {$user->name}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return $this->backToUser($user, error: 'Delete your own account from the Account screen.');
        }

        if ($user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            return $this->backToUser($user, error: 'At least one SuperAdmin must remain.');
        }

        $email = $user->email;
        $user->delete();

        return redirect()->route('admin.users.index')->with('success', "Deleted {$email}.");
    }

    /**
     * Stop impersonating and return to the original SuperAdmin. Routed in the
     * general admin group (not the SuperAdmin one) so the impersonated user — who
     * may be a plain User — can always get back.
     */
    public function leaveImpersonation(Request $request): RedirectResponse
    {
        $originalId = $request->session()->pull('impersonator_id');

        if (! $originalId) {
            return redirect()->route('admin.dashboard');
        }

        Log::info('impersonation.stop', ['restored' => $originalId, 'was' => $request->user()?->id]);
        Auth::loginUsingId($originalId);

        return redirect()->route('admin.users.index')->with('success', 'Back to your account.');
    }

    private function setPasswordLink(User $user): string
    {
        // The fingerprint makes the link single-use: it dies once this password is
        // changed (by using the link) or re-randomized (by issuing a newer one).
        return URL::temporarySignedRoute('invite.accept', now()->addDays(7), [
            'user' => $user->id,
            'fp' => InvitationController::linkFingerprint($user),
        ]);
    }

    private function superAdminCount(): int
    {
        return User::where('is_super_admin', true)->count();
    }

    private function activeSuperAdminCount(): int
    {
        return User::where('is_super_admin', true)->where('status', User::STATUS_ACTIVE)->count();
    }

    /** Redirect back to this user's detail page with the outcome flashed. */
    private function backToUser(User $user, ?string $success = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route('admin.users.show', $user);

        return $success ? $redirect->with('success', $success) : $redirect->with('error', $error);
    }
}
