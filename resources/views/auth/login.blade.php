<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — Alias TTS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('alias-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css'])
    @include('partials.analytics')
</head>
<body class="h-full bg-zinc-950 text-zinc-100 antialiased">
    <div class="mx-auto flex min-h-full max-w-sm flex-col justify-center px-6 py-12">
        <a href="{{ route('landing') }}" class="mb-8 flex items-center justify-center gap-2 font-semibold">
            <img src="{{ asset('alias-icon-on-dark.svg') }}" alt="" class="h-8 w-8">
            Alias TTS
        </a>

        <div class="rounded-2xl border border-zinc-800 bg-zinc-900/50 p-6">
            <h1 class="text-lg font-semibold">Sign in</h1>
            <p class="mt-1 text-sm text-zinc-400">Access the control panel.</p>

            @if($errors->any())
                <div class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif
            @if(session('error'))
                <div class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300">
                    {{ session('error') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.submit') }}" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                           class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
                </div>
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium">Password</label>
                    <input id="password" name="password" type="password" required
                           class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
                </div>
                <label class="flex items-center gap-2 text-sm text-zinc-400">
                    <input type="checkbox" name="remember" class="rounded border-edge bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
                    Remember me
                </label>
                <button type="submit"
                        class="w-full rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-medium text-zinc-950 transition hover:bg-cyan-400">
                    Sign in
                </button>
            </form>

            @php
                $ssoProviders = collect(['google' => 'Google', 'github' => 'GitHub'])
                    ->filter(fn ($label, $key) => filled(config("services.{$key}.client_id")) && filled(config("services.{$key}.client_secret")));
            @endphp
            @if($ssoProviders->isNotEmpty())
                <div class="my-5 flex items-center gap-3 text-xs text-zinc-600">
                    <span class="h-px flex-1 bg-zinc-800"></span>or<span class="h-px flex-1 bg-zinc-800"></span>
                </div>
                <div class="space-y-2">
                    @foreach($ssoProviders as $key => $label)
                        <a href="{{ route('oauth.redirect', $key) }}"
                           class="flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-700 px-4 py-2.5 text-sm text-zinc-200 transition hover:bg-zinc-800">
                            Continue with {{ $label }}
                        </a>
                    @endforeach
                </div>
            @endif

            @php
                // Password recovery is human-mediated (an admin issues a signed
                // reset link from the Users page) — point the locked-out user at
                // a person, with the email pre-drafted when a contact is set.
                $supportEmail = (string) config('tts.support_email');
                $mailto = $supportEmail === '' ? null : 'mailto:'.$supportEmail
                    .'?subject='.rawurlencode('Alias TTS password reset request')
                    .'&body='.rawurlencode(
                        "Hi,\n\nI've forgotten my password for the Alias TTS panel at ".config('app.url')
                        .". Could you send me a password-reset link?\n\nMy account email is: ".(old('email') ?: '')."\n\nThanks!"
                    );
            @endphp
            <p class="mt-5 border-t border-zinc-800 pt-4 text-center text-xs text-zinc-500">
                Forgot your password?
                @if($mailto)
                    <a href="{{ $mailto }}" class="text-cyan-400 hover:text-cyan-300">Email the administrator</a>
                    and they'll send you a reset link.
                @else
                    Ask an administrator to send you a reset link from the Users page.
                @endif
            </p>
        </div>
    </div>
</body>
</html>
