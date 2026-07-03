<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set your password — Mimic TTS</title>
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
            <h1 class="text-lg font-semibold">Set your password</h1>
            <p class="mt-1 text-sm text-zinc-400">Choose a password for <span class="text-zinc-200">{{ $user->email }}</span> to finish signing in.</p>

            @if($errors->any())
                <div class="mt-4 rounded-lg border border-bad/30 bg-bad/10 px-3 py-2 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('invite.store', $user) }}" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required autofocus
                           class="w-full rounded-lg border border-white/12 bg-inset px-3 py-2 text-sm focus:border-accent/50 focus:ring-2 focus:ring-accent/30 focus:outline-none">
                </div>
                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-sm font-medium">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                           class="w-full rounded-lg border border-white/12 bg-inset px-3 py-2 text-sm focus:border-accent/50 focus:ring-2 focus:ring-accent/30 focus:outline-none">
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-accent-on transition hover:bg-accent/90">
                    Set password &amp; sign in
                </button>
            </form>
        </div>
    </div>
</body>
</html>
