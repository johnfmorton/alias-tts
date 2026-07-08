<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Check the password without establishing the session — accounts with 2FA
        // must clear the second step first.
        if (! Auth::validate($credentials)) {
            return back()
                ->withErrors(['email' => 'The provided credentials do not match our records.'])
                ->onlyInput('email');
        }

        $user = Auth::getLastAttempted();

        // Don't establish a session (or a 2FA challenge) for a suspended account.
        if ($user->isSuspended()) {
            return back()
                ->withErrors(['email' => 'This account has been suspended.'])
                ->onlyInput('email');
        }

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('login.2fa.id', $user->id);
            $request->session()->put('login.2fa.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
