<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * SSO via Socialite. One pair of routes serves two intents, decided by whether a
 * user is already signed in: an authenticated user *connects* a provider to their
 * account; a guest *signs in* through a provider they previously connected. This
 * is invite-only — SSO never creates accounts, it only links/authenticates
 * existing ones. Providers with no configured credentials stay dormant: the routes
 * refuse with a friendly message instead of erroring.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'github'];

    /** Guest starts an SSO sign-in (login page). Connecting is a separate, password-
     *  gated route — see startConnect() — so this always carries the "login" intent. */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        if (! $this->configured($provider)) {
            return $this->notConfigured($provider);
        }

        $request->session()->put('oauth.intent', 'login');

        return Socialite::driver($provider)
            ->redirectUrl(route('oauth.callback', $provider))
            ->redirect();
    }

    /**
     * Signed-in user starts connecting a provider to their account. Adding a sign-in
     * method is a credential change, so it's gated on the current password — a
     * session-only attacker can't silently plant an SSO backdoor.
     */
    public function startConnect(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        if (! $this->configured($provider)) {
            return redirect()->route('admin.account.index')
                ->with('error', ucfirst($provider).' sign-in isn’t configured on this server yet.');
        }

        $request->validate(['password' => ['required', 'current_password']]);

        $request->session()->put('oauth.intent', 'connect');

        return Socialite::driver($provider)
            ->redirectUrl(route('oauth.callback', $provider))
            ->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        if (! $this->configured($provider)) {
            return $this->notConfigured($provider);
        }

        $intent = $request->session()->pull('oauth.intent', 'login');

        try {
            $oauthUser = Socialite::driver($provider)
                ->redirectUrl(route('oauth.callback', $provider))
                ->user();
        } catch (\Throwable) {
            return $this->fail($intent, "We couldn't complete sign-in with ".ucfirst($provider).'.');
        }

        return $intent === 'connect'
            ? $this->connect($request, $provider, $oauthUser)
            : $this->signIn($provider, $oauthUser);
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        $request->user()->connectedAccounts()->where('provider', $provider)->delete();

        return redirect()->route('admin.account.index')->with('success', ucfirst($provider).' disconnected.');
    }

    /** Authenticated user links this provider identity to their account. */
    private function connect(Request $request, string $provider, $oauthUser): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $existing = ConnectedAccount::where('provider', $provider)->where('provider_id', $oauthUser->getId())->first();
        if ($existing && $existing->user_id !== $user->id) {
            return redirect()->route('admin.account.index')
                ->with('error', 'That '.ucfirst($provider).' account is already linked to another user.');
        }

        ConnectedAccount::updateOrCreate(
            ['provider' => $provider, 'provider_id' => $oauthUser->getId()],
            [
                'user_id' => $user->id,
                'name' => $oauthUser->getName(),
                'email' => $oauthUser->getEmail(),
                'avatar' => $oauthUser->getAvatar(),
            ],
        );

        return redirect()->route('admin.account.index')->with('success', ucfirst($provider).' connected.');
    }

    /** Guest signs in through a provider identity that's already linked. */
    private function signIn(string $provider, $oauthUser): RedirectResponse
    {
        $account = ConnectedAccount::where('provider', $provider)->where('provider_id', $oauthUser->getId())->first();

        if (! $account || ! $account->user) {
            return redirect()->route('login')
                ->with('error', 'No account is linked to that '.ucfirst($provider).' sign-in. Connect it from your Account page first, or ask an admin.');
        }

        $user = $account->user;

        if ($user->isSuspended()) {
            return redirect()->route('login')->with('error', 'This account has been suspended.');
        }

        // SSO proves the third-party identity, but a locally-enabled second factor is
        // independent — require the TOTP challenge too, exactly like password login,
        // so SSO can't be used as a 2FA-free side door.
        if ($user->hasTwoFactorEnabled()) {
            request()->session()->put('login.2fa.id', $user->id);
            request()->session()->put('login.2fa.remember', false);

            return redirect()->route('two-factor.challenge');
        }

        // Don't silently create a persistent "remember me" session the user never asked for.
        Auth::login($user, false);
        request()->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    private function assertProvider(string $provider): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
    }

    private function configured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"));
    }

    private function notConfigured(string $provider): RedirectResponse
    {
        return $this->fail(
            request()->user() ? 'connect' : 'login',
            ucfirst($provider).' sign-in isn’t configured on this server yet.',
        );
    }

    private function fail(string $intent, string $message): RedirectResponse
    {
        return redirect()
            ->route($intent === 'connect' ? 'admin.account.index' : 'login')
            ->with('error', $message);
    }
}
