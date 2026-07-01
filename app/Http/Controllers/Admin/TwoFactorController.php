<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Two-factor setup from the Account screen (2A). Enabling stores a secret in a
 * "pending" state; the user must enter a code from their authenticator to confirm
 * it, at which point recovery codes are issued (shown once). Disabling and
 * regenerating recovery codes require the account password.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    /** Begin setup: generate a secret and drop into the pending state. */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => $this->twoFactor->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('admin.account.index')->with('success', 'Scan the QR code, then enter a code to finish.');
    }

    /** Confirm the pending secret with a code, then issue recovery codes (shown once). */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->hasTwoFactorPending() || ! $this->twoFactor->verify($user->two_factor_secret, $request->input('code'))) {
            return back()->with('error', 'That code did not match. Try again.');
        }

        $codes = $this->twoFactor->recoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return redirect()->route('admin.account.index')
            ->with('success', 'Two-factor authentication is on. Save your recovery codes now.')
            ->with('recovery_codes', $codes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        $wasEnabled = $user->hasTwoFactorEnabled();

        // Turning off *confirmed* 2FA needs the password; canceling a half-finished
        // setup (a pending secret, never confirmed) does not.
        if ($wasEnabled) {
            $request->validate(['password' => ['required', 'current_password']]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('admin.account.index')
            ->with('success', $wasEnabled ? 'Two-factor authentication turned off.' : 'Setup canceled.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 400);

        $codes = $this->twoFactor->recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return redirect()->route('admin.account.index')
            ->with('success', 'New recovery codes generated. Your old codes no longer work.')
            ->with('recovery_codes', $codes);
    }
}
