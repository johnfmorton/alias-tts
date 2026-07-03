<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor — Mimic TTS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('mimic-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-app text-zinc-100 antialiased">
    <div class="mx-auto flex min-h-full max-w-sm flex-col justify-center px-6 py-12">
        <a href="{{ route('landing') }}" class="mb-8 flex items-center justify-center gap-2 font-semibold">
            <img src="{{ asset('mimic-icon-on-dark.svg') }}" alt="" class="h-8 w-8">
            Mimic TTS
        </a>

        <div class="rounded-2xl border border-white/8 bg-panel p-6">
            <h1 class="text-lg font-semibold">Two-factor authentication</h1>
            <p class="mt-1 text-sm text-zinc-400">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>

            @if($errors->any())
                <div class="mt-4 rounded-lg border border-bad/30 bg-bad/10 px-3 py-2 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="code" class="mb-1.5 block text-sm font-medium">Code</label>
                    <input id="code" name="code" inputmode="text" autocomplete="one-time-code" required autofocus
                           class="w-full rounded-lg border border-white/12 bg-inset px-3 py-2 font-mono tracking-widest focus:border-accent/50 focus:ring-2 focus:ring-accent/30 focus:outline-none">
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-accent-on transition hover:bg-accent/90">
                    Verify
                </button>
            </form>

            <a href="{{ route('login') }}" class="mt-4 block text-center text-xs text-zinc-500 hover:text-zinc-300">Cancel and return to sign in</a>
        </div>
    </div>
</body>
</html>
