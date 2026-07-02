# Single sign-on (Google & GitHub)

The Account screen can link Google and GitHub sign-ins (via [Laravel Socialite](https://laravel.com/docs/socialite)). SSO is **invite-only**: it never creates accounts — a user connects a provider to an account that already exists, then can sign in with it. Two-factor auth (TOTP) is separate and needs no configuration.

Each provider stays **dormant** until its credentials are set: the provider row shows *Not configured*, its **Connect** button is disabled, and the OAuth routes refuse with a friendly message instead of erroring. So you can ship without SSO and turn it on later.

## Callback URL

Both providers call back to:

```
<APP_URL>/oauth/<provider>/callback
```

e.g. `https://tts.example.com/oauth/google/callback` and `.../oauth/github/callback`. Locally that's `https://tts.ddev.site/oauth/google/callback`.

The redirect URI is fixed by the app (the controller always uses this route), so the `GOOGLE_REDIRECT_URI` / `GITHUB_REDIRECT_URI` keys in `config/services.php` are inert — don't set them expecting a change.

## Google

1. [Google Cloud Console](https://console.cloud.google.com/) → APIs & Services → **Credentials** → **Create credentials → OAuth client ID**.
2. Application type **Web application**.
3. Add the callback URL above under **Authorized redirect URIs**.
4. Copy the client ID/secret into `.env`:

```env
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
```

## GitHub

1. GitHub → Settings → Developer settings → **OAuth Apps** → **New OAuth App**.
2. Set **Authorization callback URL** to the callback URL above.
3. Copy the client ID/secret into `.env`:

```env
GITHUB_CLIENT_ID=...
GITHUB_CLIENT_SECRET=...
```

## After configuring

Run `php artisan config:clear` (or `ddev artisan config:clear`) if config is cached. The Account screen's **Connect** buttons and the login page's **Continue with …** buttons activate automatically once the credentials are present.

## How it works

- **Connect** (signed in): links the provider identity to your account (`connected_accounts` table; unique per provider identity and per user). You'll confirm your current password before the OAuth redirect — a deliberate security measure.
- **Sign in** (guest): finds the account linked to that provider identity and logs it in. If nothing is linked, it's refused — connect it from the Account page first, or have an admin create/invite the user.
- **Disconnect**: removes the link. Accounts always keep their password, so disconnecting never locks anyone out.
