<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\CreditTransaction;
use App\Models\Speech;
use App\Models\User;
use App\Services\Credit\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * SuperAdmin-only user management (design 2B). Everything here is gated by
 * EnsureUserIsSuperAdmin at the route level. The screen is a table + a detail
 * drawer; the drawer is server-rendered when `?user=` is present so its actions
 * are plain, robust form POSTs that reopen the drawer on the acted user.
 */
class UserController extends Controller
{
    public function __construct(private readonly CreditService $credit) {}

    public function index(Request $request): View
    {
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

        $keyCounts = ApiKey::query()
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id as uid, count(*) as c')
            ->pluck('c', 'uid');

        $selected = $request->filled('user')
            ? $users->firstWhere('id', $request->integer('user'))
            : null;

        return view('admin.users.index', [
            'users' => $users,
            'gens' => $gens,
            'keyCounts' => $keyCounts,
            'selected' => $selected,
            'activeCount' => $users->where('status', User::STATUS_ACTIVE)->count(),
            'invitedCount' => $users->where('status', User::STATUS_INVITED)->count(),
            // Drawer-only credit detail: lifetime charged-vs-actual sums and
            // the last few ledger rows (this page is SuperAdmin-gated, so the
            // actual provider cost is fine to show).
            'creditTotals' => $selected ? $this->credit->chargedTotals($selected) : null,
            'creditRecent' => $selected
                ? CreditTransaction::where('user_id', $selected->id)->latest('id')->limit(10)->get()
                : collect(),
        ]);
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

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $temp,
            'is_super_admin' => $data['role'] === 'SuperAdmin',
            'status' => User::STATUS_ACTIVE,
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "Created {$data['email']}. Share their temporary password below.")
            ->with('reveal_label', "Temporary password for {$data['email']}")
            ->with('reveal_value', $temp);
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

        return redirect()->route('admin.users.index')
            ->with('success', "Invited {$data['email']}. Send them this link to set a password.")
            ->with('reveal_label', "Invite link for {$data['email']}")
            ->with('reveal_value', $this->setPasswordLink($user));
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

        $user->update(['is_super_admin' => $makeSuper]);

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

        $user->update([
            'status' => $user->isSuspended() ? User::STATUS_ACTIVE : User::STATUS_SUSPENDED,
        ]);

        return $this->backToUser($user, success: $user->isSuspended()
            ? $user->name.' has been suspended.'
            : $user->name.' has been reactivated.');
    }

    /** Invalidate the user's password and hand back a signed set-password link. */
    public function forceReset(Request $request, User $user): RedirectResponse
    {
        $user->update([
            'password' => Str::random(40),
            'status' => $user->isSuspended() ? User::STATUS_SUSPENDED : User::STATUS_ACTIVE,
        ]);

        return $this->backToUser(
            $user,
            success: "{$user->name}'s password was reset. Share this link so they can set a new one.",
        )->with('reveal_label', "Set-password link for {$user->email}")
            ->with('reveal_value', $this->setPasswordLink($user));
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
        return URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $user->id]);
    }

    private function superAdminCount(): int
    {
        return User::where('is_super_admin', true)->count();
    }

    private function activeSuperAdminCount(): int
    {
        return User::where('is_super_admin', true)->where('status', User::STATUS_ACTIVE)->count();
    }

    /** Redirect back to the index with the drawer reopened on this user. */
    private function backToUser(User $user, ?string $success = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route('admin.users.index', ['user' => $user->id]);

        return $success ? $redirect->with('success', $success) : $redirect->with('error', $error);
    }
}
