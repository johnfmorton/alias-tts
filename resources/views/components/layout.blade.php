@props(['title' => 'Dashboard', 'description' => null, 'contentWidth' => 'max-w-5xl', 'heading' => true])
@php
    use Illuminate\Support\Facades\Route as RouteFacade;
    use Illuminate\Support\Str;

    $navUser = auth()->user();
    $navFirstName = $navUser ? (Str::before(trim($navUser->name), ' ') ?: $navUser->name) : '';

    // Primary nav — the three flat, always-visible destinations. Everything else
    // lives under the account menu. Genblaze (the headline demo) leads when the
    // runner is configured; it's an emphasized pill, not a plain link.
    $primaryNav = [];
    if (config('tts.genblaze.runner_url')) {
        $primaryNav[] = ['route' => 'admin.studio.genblaze', 'pattern' => 'admin.studio.genblaze', 'label' => 'Genblaze Demo', 'demo' => true];
    }
    $primaryNav[] = ['route' => 'admin.dashboard', 'pattern' => 'admin.dashboard', 'label' => 'Dashboard'];
    // Studio must NOT highlight on the nested Genblaze route, which its wildcard
    // pattern would otherwise match — hence the `except`.
    $primaryNav[] = ['route' => 'admin.studio.index', 'pattern' => 'admin.studio.*', 'except' => 'admin.studio.genblaze', 'label' => 'Studio'];

    // Account menu — the secondary set, grouped. ADMIN is role-gated and rendered
    // separately below (only for SuperAdmins, and only once its routes exist).
    $menuSections = [
        [
            'label' => null,
            'items' => [
                ['route' => 'admin.account.index', 'pattern' => 'admin.account.*', 'label' => 'Account'],
            ],
        ],
        [
            'label' => 'Manage',
            'items' => [
                ['route' => 'admin.api-keys.index', 'pattern' => 'admin.api-keys.*', 'label' => 'API Keys'],
                ['route' => 'admin.voices.index', 'pattern' => 'admin.voices.*', 'label' => 'Voices'],
                ['route' => 'admin.pronunciations.index', 'pattern' => 'admin.pronunciations.*', 'label' => 'Pronunciations'],
            ],
        ],
        [
            'label' => 'System',
            'items' => [
                ['route' => 'admin.health', 'pattern' => 'admin.health', 'label' => 'Health'],
                ['route' => 'admin.settings.index', 'pattern' => 'admin.settings.*', 'label' => 'Settings'],
            ],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — Mimic TTS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('mimic-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-app text-zinc-100 antialiased">
    <div class="flex min-h-screen flex-col">
        @if(session()->has('impersonator_id'))
            <div class="flex flex-wrap items-center justify-center gap-3 border-b border-warn/25 bg-warn/[0.12] px-4 py-2 text-center text-sm text-warn">
                <span>You're signed in as <strong>{{ auth()->user()->name }}</strong> (impersonating).</span>
                <form method="POST" action="{{ route('admin.impersonate.leave') }}">
                    @csrf
                    <button type="submit" class="rounded-[6px] border border-warn/40 px-3 py-1 text-xs font-semibold text-warn transition hover:bg-warn/[0.1]">Return to your account</button>
                </form>
            </div>
        @endif
        <header class="border-b border-white/8 bg-app">
            <div class="mx-auto flex h-[74px] max-w-5xl items-center gap-5 px-4 sm:px-8">
                {{-- Logo + wordmark --}}
                <a href="{{ route('admin.dashboard') }}" class="flex shrink-0 items-center gap-3 whitespace-nowrap">
                    <img src="{{ asset('mimic-icon-on-dark.svg') }}" alt="" class="h-[38px] w-[38px]">
                    <span class="text-[20px] font-bold tracking-[-0.3px] text-zinc-100">Mimic TTS</span>
                </a>

                {{-- Primary nav — three flat destinations --}}
                <nav class="flex min-w-0 items-center gap-2 overflow-x-auto text-[15px]">
                    @foreach($primaryNav as $item)
                        @php
                            $active = request()->routeIs($item['pattern'])
                                && ! (isset($item['except']) && request()->routeIs($item['except']));
                        @endphp
                        @if($item['demo'] ?? false)
                            {{-- Emphasized pill for the headline demo, badged so judges spot it --}}
                            <a href="{{ route($item['route']) }}"
                               class="inline-flex shrink-0 items-center gap-[7px] rounded-[9px] border border-accent/40 bg-accent/[0.08] px-3 py-2 text-zinc-200 transition hover:bg-accent/[0.14]">
                                {{ $item['label'] }}
                                <span class="rounded-[5px] bg-accent px-1.5 py-0.5 font-mono text-[10px] font-bold tracking-wide text-accent-on">DEMO</span>
                            </a>
                        @else
                            <a href="{{ route($item['route']) }}"
                               class="shrink-0 rounded-[2px] px-3 py-2 transition {{ $active ? 'border-b-2 border-accent text-accent' : 'text-zinc-400 hover:text-zinc-100' }}">
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </nav>

                {{-- Account control + dropdown --}}
                @auth
                    <div class="relative ml-auto shrink-0">
                        <button id="account-pill" type="button" aria-haspopup="true" aria-expanded="false"
                                class="inline-flex items-center gap-[9px] rounded-[24px] border border-accent/45 bg-accent/[0.06] py-[5px] pr-[6px] pl-3 transition hover:bg-accent/10">
                            <span class="text-sm text-zinc-200">{{ $navFirstName }}</span>
                            <x-avatar :user="$navUser" :size="32" />
                        </button>

                        <div id="account-menu"
                             class="absolute top-[52px] right-0 z-50 hidden w-[250px] rounded-[14px] border border-white/10 bg-menu p-1.5 shadow-[0_20px_40px_-12px_rgba(0,0,0,0.7)]">
                            {{-- Header: identity + role badge --}}
                            <div class="flex items-center gap-2.5 p-3">
                                <x-avatar :user="$navUser" :size="34" />
                                <div class="min-w-0">
                                    <div class="truncate text-sm leading-tight font-semibold text-zinc-100">{{ $navUser->name }}</div>
                                    <div class="mt-[3px] flex items-center gap-1.5">
                                        <span class="truncate text-xs text-zinc-500">{{ request()->getHost() }}</span>
                                        @if($navUser->isSuperAdmin())
                                            <span class="rounded-[5px] border border-accent/30 bg-accent/[0.12] px-1.5 py-px text-[10px] font-semibold text-accent">SuperAdmin</span>
                                        @else
                                            <span class="rounded-[5px] border border-white/12 bg-white/[0.06] px-1.5 py-px text-[10px] font-semibold text-zinc-400">User</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="mx-2 mt-0.5 mb-1 h-px bg-white/8"></div>

                            @foreach($menuSections as $section)
                                @if($section['label'])
                                    <div class="px-3 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-zinc-500 uppercase">{{ $section['label'] }}</div>
                                @endif
                                @foreach($section['items'] as $it)
                                    @php $rowActive = request()->routeIs($it['pattern']); @endphp
                                    <a href="{{ route($it['route']) }}"
                                       class="block rounded-lg px-3 py-[7px] text-sm transition {{ $rowActive ? 'bg-accent/[0.08] text-accent' : 'text-zinc-300 hover:bg-white/[0.04]' }}">
                                        {{ $it['label'] }}
                                    </a>
                                @endforeach
                            @endforeach

                            {{-- ADMIN — role-gated, and only once its routes exist (Users lands in a later phase) --}}
                            @if($navUser->isSuperAdmin() && RouteFacade::has('admin.users.index'))
                                @php $usersActive = request()->routeIs('admin.users.*'); @endphp
                                <div class="mx-1.5 mt-2 rounded-lg border-t border-accent/25 bg-accent/[0.05] px-1.5 pt-2 pb-1">
                                    <div class="px-1.5 pb-1.5 text-[11px] font-semibold tracking-wider text-accent uppercase">Admin</div>
                                    <a href="{{ route('admin.users.index') }}"
                                       class="block rounded-lg px-1.5 py-[7px] text-sm transition {{ $usersActive ? 'text-accent' : 'text-zinc-200 hover:bg-white/[0.04]' }}">
                                        Users
                                    </a>
                                </div>
                            @endif

                            <div class="mx-2 my-1.5 h-px bg-white/8"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full rounded-lg px-3 py-[7px] text-left text-sm text-bad transition hover:bg-white/[0.04]">Log out</button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </header>

        <main class="mx-auto w-full flex-1 px-4 py-8 {{ $contentWidth }}">
            @if($heading)
                <div class="mb-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight">{{ $title }}</h1>
                        {{ $titleActions ?? '' }}
                    </div>
                    @if($description)
                        <p class="mt-1 text-sm text-zinc-400">{{ $description }}</p>
                    @endif
                </div>
            @endif

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
                    <span>Mimic TTS</span>
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
