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

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('login.2fa.id', $user->id);
            $request->session()->put('login.2fa.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Land on the headline Genblaze page when it's live (the first thing a
        // judge should see); otherwise the dashboard.
        $home = config('tts.genblaze.runner_url') ? 'admin.studio.genblaze' : 'admin.dashboard';

        return redirect()->intended(route($home));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
