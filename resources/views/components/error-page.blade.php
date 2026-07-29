{{-- Shared shell for the HTTP error pages (errors/*.blade.php). Standalone like
     the login page — no app layout, since errors must render for guests and
     half-broken requests alike. The status code is presented as one of the
     app's own pronunciation-dictionary rows: code → respelling. --}}
@props(['code', 'respelling', 'title'])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Alias TTS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('alias-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css'])
    @include('partials.analytics')
</head>
<body class="h-full bg-zinc-950 text-zinc-100 antialiased">
    <div class="mx-auto flex min-h-full max-w-xl flex-col items-center justify-center px-6 py-12 text-center">
        <a href="{{ route('landing') }}" class="mb-10 flex items-center justify-center gap-2 font-semibold">
            <img src="{{ asset('alias-icon-on-dark.svg') }}" alt="" class="h-8 w-8">
            Alias TTS
        </a>

        {{-- The status code as a dictionary entry, styled like a review row. --}}
        <div class="inline-flex flex-wrap items-center justify-center gap-3 rounded-xl border border-zinc-800 bg-zinc-900/50 px-5 py-4">
            <span class="font-mono text-2xl font-bold text-zinc-100">{{ $code }}</span>
            <span class="text-zinc-500" aria-hidden="true">&rarr;</span>
            <span class="text-2xl text-cyan-400">&ldquo;{{ $respelling }}&rdquo;</span>
        </div>

        <h1 class="mt-8 text-lg font-semibold">{{ $title }}</h1>
        <p class="mt-2 max-w-md text-sm leading-relaxed text-zinc-400">{{ $slot }}</p>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
            @auth
                <a href="{{ route('admin.dashboard') }}"
                   class="rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-medium text-zinc-950 transition hover:bg-cyan-400">Go to the Dashboard</a>
            @else
                <a href="{{ route('landing') }}"
                   class="rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-medium text-zinc-950 transition hover:bg-cyan-400">Go to the home page</a>
            @endauth
            <a href="javascript:history.back()" class="text-sm text-zinc-400 hover:text-zinc-200">&larr; Go back</a>
        </div>
    </div>
</body>
</html>
