<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The second login step for accounts with 2FA. AuthController::login stashes the
 * verified-password user in the session ("login.2fa.*") and redirects here without
 * establishing the session; a valid TOTP or recovery code completes the login.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.2fa.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    /** After this many wrong codes, drop the pending login and force step 1 again. */
    private const MAX_ATTEMPTS = 5;

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = User::find($request->session()->get('login.2fa.id'));

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return $this->abandon($request);
        }

        // A user suspended between step 1 and step 2 must not complete login.
        if ($user->isSuspended()) {
            return $this->abandon($request)->with('error', 'This account has been suspended.');
        }

        if (! $this->passes($user, trim($request->input('code')))) {
            // Rate limiting also guards this route (see routes/web.php); this caps
            // guesses against a single pending login and forces a fresh password entry.
            if ($request->session()->increment('login.2fa.attempts') >= self::MAX_ATTEMPTS) {
                return $this->abandon($request)->with('error', 'Too many attempts. Please sign in again.');
            }

            return back()->withErrors(['code' => 'That code was not valid. Try again or use a recovery code.']);
        }

        $remember = (bool) $request->session()->get('login.2fa.remember');
        $this->clearPending($request);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    private function clearPending(Request $request): void
    {
        $request->session()->forget(['login.2fa.id', 'login.2fa.remember', 'login.2fa.attempts']);
    }

    private function abandon(Request $request): RedirectResponse
    {
        $this->clearPending($request);

        return redirect()->route('login');
    }

    /** A valid TOTP code, or a one-time recovery code (which is then consumed). */
    private function passes(User $user, string $code): bool
    {
        if ($this->twoFactor->verify($user->two_factor_secret, $code)) {
            return true;
        }

        $codes = $user->two_factor_recovery_codes ?? [];
        if (in_array($code, $codes, true)) {
            $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$code]))])->save();

            return true;
        }

        return false;
    }
}
