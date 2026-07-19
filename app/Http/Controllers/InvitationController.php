<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The set-password flow behind an invite link or a SuperAdmin "force password
 * reset". The GET link is signed (see UserController::setPasswordLink); visiting a
 * valid signature authorizes this session to set exactly that user's password, so
 * the POST needn't carry the signature — it checks the session instead.
 *
 * The link is single-use: it carries a fingerprint of the password it was issued
 * against (see linkFingerprint). Setting a password re-hashes it, so the fingerprint
 * no longer matches and the link is dead — the same way issuing a newer link (which
 * re-randomizes the password) retires any older one.
 */
class InvitationController extends Controller
{
    private const SESSION_KEY = 'set_password_for';

    /**
     * A fingerprint of the user's current password hash, mixed into the signed link.
     * It changes the instant the password changes — when the link is used to set one,
     * or when a newer link re-randomizes it — so a stale link stops matching.
     */
    public static function linkFingerprint(User $user): string
    {
        return hash('sha256', 'set-password'.$user->password);
    }

    public function show(Request $request, User $user): View|RedirectResponse
    {
        // The signature (enforced by the 'signed' middleware) only proves the link is
        // authentic and unexpired; the fingerprint proves it hasn't already been spent
        // on setting a password (or superseded by a newer link).
        if (! hash_equals(self::linkFingerprint($user), (string) $request->query('fp'))) {
            return redirect()->route('login')->withErrors([
                'email' => 'That set-password link has already been used or is no longer valid. Ask an admin for a new one.',
            ]);
        }

        // A valid signed visit authorizes the POST for this user id only.
        $request->session()->put(self::SESSION_KEY, $user->id);

        return view('auth.set-password', ['user' => $user]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->session()->get(self::SESSION_KEY) === $user->id, 403);

        // A suspended account must not be reactivated (or signed in) by completing a
        // set-password link that was issued before — or still valid across — the
        // suspension. Setting a password is harmless; regaining access is the control
        // being enforced (see EnsureAccountIsActive and forceReset, which both
        // deliberately preserve a suspended status).
        if ($user->isSuspended()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been suspended.']);
        }

        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update([
            'password' => $request->input('password'),
            'status' => User::STATUS_ACTIVE,
        ]);

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('success', 'Your password is set. Welcome!');
    }
}
