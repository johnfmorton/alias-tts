@props(['title' => 'Dashboard', 'description' => null])
@php
    $navItems = [
        ['route' => 'admin.dashboard', 'pattern' => 'admin.dashboard', 'label' => 'Dashboard'],
        // Studio must NOT highlight on the nested Genblaze route (admin.studio.genblaze),
        // which its wildcard pattern would otherwise match — hence the `except`.
        ['route' => 'admin.studio.index', 'pattern' => 'admin.studio.*', 'except' => 'admin.studio.genblaze', 'label' => 'Studio'],
        ['route' => 'admin.api-keys.index', 'pattern' => 'admin.api-keys.*', 'label' => 'API Keys'],
        ['route' => 'admin.voices.index', 'pattern' => 'admin.voices.*', 'label' => 'Voices'],
        ['route' => 'admin.pronunciations.index', 'pattern' => 'admin.pronunciations.*', 'label' => 'Pronunciations'],
        ['route' => 'admin.health', 'pattern' => 'admin.health', 'label' => 'Health'],
        ['route' => 'admin.settings.index', 'pattern' => 'admin.settings.*', 'label' => 'Settings'],
    ];
    // Headline hackathon feature — shown FIRST (the judges' landing page) when the
    // Genblaze runner is configured.
    if (config('tts.genblaze.runner_url')) {
        array_unshift($navItems, ['route' => 'admin.studio.genblaze', 'pattern' => 'admin.studio.genblaze', 'label' => 'Genblaze Demo']);
    }
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — Bespoken TTS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-zinc-950 text-zinc-100 antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-zinc-800 bg-zinc-900/60">
            <div class="mx-auto flex max-w-5xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('admin.dashboard') }}" class="flex shrink-0 items-center gap-2 font-semibold whitespace-nowrap">
                    <span class="grid h-7 w-7 place-items-center rounded-md bg-cyan-500 text-sm font-bold text-zinc-950">B</span>
                    Bespoken TTS
                </a>
                <nav class="flex flex-wrap items-center gap-1 text-sm">
                    @foreach($navItems as $item)
                        @php
                            $active = request()->routeIs($item['pattern'])
                                && ! (isset($item['except']) && request()->routeIs($item['except']));
                        @endphp
                        <a href="{{ route($item['route']) }}"
                           class="rounded-md px-3 py-1.5 transition {{ $active ? 'bg-cyan-500/10 text-cyan-400' : 'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                    <form method="POST" action="{{ route('logout') }}" class="ml-2">
                        @csrf
                        <button type="submit" class="rounded-md px-3 py-1.5 text-zinc-400 transition hover:bg-zinc-800 hover:text-zinc-100">Log out</button>
                    </form>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
            <div class="mb-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ $title }}</h1>
                    {{ $titleActions ?? '' }}
                </div>
                @if($description)
                    <p class="mt-1 text-sm text-zinc-400">{{ $description }}</p>
                @endif
            </div>

            @if(session('success'))
                <div class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            {{ $slot }}
        </main>

        @auth
            <footer class="border-t border-zinc-800 bg-zinc-900/40">
                <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-4 text-xs text-zinc-500">
                    <span>Bespoken TTS</span>
                    @php
                        $sourceUrl = config('app.source_url');
                        $releaseUrl = $sourceUrl ? rtrim($sourceUrl, '/').'/releases/tag/v'.config('app.version') : null;
                    @endphp
                    @if($releaseUrl)
                        <a href="{{ $releaseUrl }}" target="_blank" rel="noopener noreferrer"
                           class="font-mono transition hover:text-zinc-300">v{{ config('app.version') }}</a>
                    @else
                        <span class="font-mono">v{{ config('app.version') }}</span>
                    @endif
                </div>
            </footer>
        @endauth
    </div>
</body>
</html>
