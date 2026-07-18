@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    $navUser = auth()->user();

    // Destination cards — each is a full-card link to its "Manage" page (via a
    // stretched ::after link) plus a distinct "+ Add" secondary target lifted
    // above it with z-10. Counts come from the controller's per-user stats.
    $cards = [
        [
            'icon' => 'voices',
            'title' => 'Voices',
            'desc' => 'Custom & default voices',
            'count' => $stats['voices'],
            'manage' => ['label' => 'Manage voices', 'route' => 'admin.voices.index'],
            'add' => ['label' => '+ Add voice', 'route' => 'admin.voices.create'],
        ],
        [
            'icon' => 'key',
            'title' => 'API Keys',
            'desc' => 'Active credentials',
            'count' => $stats['apiKeys'],
            'manage' => ['label' => 'Manage API keys', 'route' => 'admin.api-keys.index'],
            'add' => ['label' => '+ New key', 'route' => 'admin.api-keys.create'],
        ],
        [
            'icon' => 'pronunciations',
            'title' => 'Pronunciations',
            'desc' => 'Custom word overrides',
            'count' => $stats['pronunciations'],
            'manage' => ['label' => 'Manage pronunciations', 'route' => 'admin.pronunciations.index'],
            'add' => ['label' => '+ Add rule', 'route' => 'admin.pronunciations.create'],
        ],
        [
            'icon' => 'projects',
            'title' => 'Projects',
            'desc' => "Scripts you're producing",
            'count' => $stats['projects'],
            'manage' => ['label' => 'Open in Studio', 'route' => 'admin.studio.index'],
            'add' => ['label' => '+ New project', 'route' => 'admin.studio.projects.create'],
        ],
    ];

    // cURL examples in the Connect card. The voice defaults to the first chip
    // (clicking any chip swaps it in client-side); missing values fall back to
    // readable placeholders so the examples always render complete commands.
    $exampleKey = $connect['apiKey'] ?? 'YOUR_API_KEY';
    $exampleVoice = $connect['voiceIds'][0] ?? 'YOUR_VOICE_ID';
    $exampleText = 'Hello from Alias. If you can hear this, your connection works.';
@endphp

<x-layout title="Dashboard" :heading="false" contentWidth="max-w-[1080px]">
    {{-- Page header --}}
    <div class="mb-7">
        <h1 class="text-[27px] font-bold tracking-[-0.015em] text-zinc-100">Dashboard</h1>
        <p class="mt-1.5 text-sm text-zinc-400">Everything in Alias, one hop away — plus connection details for any ElevenLabs- or OpenAI-compatible app.</p>
    </div>

    {{-- Destination cards --}}
    <div class="mb-3.5 text-xs font-bold uppercase tracking-[0.1em] text-accent">Manage</div>
    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach($cards as $card)
            <div class="relative flex flex-col rounded-[14px] border border-white/8 bg-panel px-6 py-[22px] transition hover:border-white/[0.14]">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3.5">
                        {{-- Icon tile (40px, cyan-tinted) --}}
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] border border-accent/30 bg-accent/10 text-accent">
                            @switch($card['icon'])
                                @case('voices')
                                    <span class="flex items-end gap-[2px]">
                                        <span class="w-[2.5px] rounded-[2px] bg-accent" style="height:8px"></span>
                                        <span class="w-[2.5px] rounded-[2px] bg-accent" style="height:15px"></span>
                                        <span class="w-[2.5px] rounded-[2px] bg-accent" style="height:11px"></span>
                                        <span class="w-[2.5px] rounded-[2px] bg-accent" style="height:6px"></span>
                                    </span>
                                    @break
                                @case('key')
                                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <circle cx="8" cy="8" r="4.5"></circle>
                                        <path d="M11 11l8 8"></path>
                                        <path d="M16 16l2-2"></path>
                                    </svg>
                                    @break
                                @case('pronunciations')
                                    <span class="font-mono text-[15px] font-bold leading-none">əˈ</span>
                                    @break
                                @case('projects')
                                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <rect x="4" y="4" width="12" height="12" rx="2.5"></rect>
                                        <rect x="9" y="9" width="12" height="12" rx="2.5" fill="var(--color-panel)"></rect>
                                    </svg>
                                    @break
                            @endswitch
                        </span>
                        <div>
                            <div class="text-[15px] font-semibold text-zinc-100">{{ $card['title'] }}</div>
                            <div class="mt-0.5 text-[12.5px] text-zinc-500">{{ $card['desc'] }}</div>
                        </div>
                    </div>
                    <div class="text-[32px] font-bold leading-none tracking-[-1px] text-zinc-100">{{ $card['count'] }}</div>
                </div>

                <div class="mt-5 flex items-center justify-between border-t border-white/8 pt-4">
                    {{-- Primary target: the whole card links here via the stretched ::after. --}}
                    <a href="{{ route($card['manage']['route']) }}"
                       class="text-sm font-semibold text-accent after:absolute after:inset-0 after:content-['']">
                        {{ $card['manage']['label'] }} →
                    </a>
                    {{-- Secondary target: lifted above the stretched link with z-10. --}}
                    <a href="{{ route($card['add']['route']) }}"
                       class="relative z-10 text-[12.5px] text-zinc-500 transition hover:text-zinc-300">
                        {{ $card['add']['label'] }}
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Credit — metered accounts only; unlimited users see nothing (unchanged). --}}
    @if($navUser->hasLimitedCredit())
        <div class="mb-3 flex items-center gap-4 rounded-xl border border-white/8 bg-inset px-[22px] py-4">
            <span class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[9px] border border-accent/30 bg-accent/10 text-base font-bold text-accent">$</span>
            <div>
                <div class="text-sm font-semibold {{ $navUser->credit_balance_micro <= 0 ? 'text-warn' : 'text-zinc-100' }}">
                    {{ \App\Services\Credit\CreditService::formatMicro($navUser->credit_balance_micro) }} available
                </div>
                <div class="mt-px text-[12.5px] text-zinc-500">Prepaid credit — spends as you generate, pauses at $0.</div>
            </div>
            @if(config('tts.support_email'))
                <a href="mailto:{{ config('tts.support_email') }}" class="relative z-10 ml-auto text-sm text-accent hover:underline">Need more?</a>
            @else
                <span class="ml-auto text-sm text-zinc-500">Prepaid balance</span>
            @endif
        </div>
    @endif

    {{-- Generations — a usage metric, not a destination. --}}
    <div class="mb-8 flex items-center gap-4 rounded-xl border border-white/8 bg-inset px-[22px] py-4">
        <span class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[9px] border border-ok/[0.28] bg-ok/10 text-base text-ok">✦</span>
        <div>
            <div class="text-sm font-semibold text-zinc-200">{{ $stats['speeches'] }} {{ $stats['speeches'] === 1 ? 'generation' : 'generations' }}</div>
            <div class="mt-px text-[12.5px] text-zinc-500">Lifetime audio generated across all projects.</div>
        </div>
        <span class="ml-auto text-sm text-zinc-500">Usage metric · not a page</span>
    </div>

    {{-- Connect your app --}}
    <div class="mb-7 rounded-[14px] border border-white/8 bg-panel px-7 py-6">
        <h2 class="text-[17px] font-bold text-zinc-100">Connect your app</h2>
        <p class="mt-2 max-w-[820px] text-[13.5px] leading-relaxed text-zinc-400">Alias speaks both the ElevenLabs v1 and OpenAI speech APIs, so any app compatible with either works — including the Bespoken Craft CMS plugin. Paste these into its API settings.</p>

        <div class="my-5 h-px bg-white/8"></div>

        {{-- Base URL --}}
        <div class="mb-1.5 text-[13px] font-semibold text-zinc-300">Base URL</div>
        <div class="mb-5 flex items-center gap-2.5">
            <code class="flex-1 truncate rounded-[9px] border border-white/12 bg-inset px-3.5 py-3 font-mono text-sm text-zinc-200">{{ $connect['baseUrl'] }}</code>
            <button data-copy="{{ $connect['baseUrl'] }}" class="rounded-[9px] border border-white/[0.14] px-4.5 py-3 text-sm text-zinc-300 transition hover:bg-white/[0.04]">Copy</button>
        </div>

        {{-- API key --}}
        <div class="mb-1.5 text-[13px] font-semibold text-zinc-300">API key <span class="font-mono text-xs font-normal text-zinc-500">(xi-api-key)</span></div>
        @if($connect['apiKey'])
            <div class="flex items-center gap-2.5">
                <code class="flex-1 truncate rounded-[9px] border border-white/12 bg-inset px-3.5 py-3 font-mono text-sm text-zinc-200">{{ $connect['apiKey'] }}</code>
                <button data-copy="{{ $connect['apiKey'] }}" class="rounded-[9px] border border-white/[0.14] px-4.5 py-3 text-sm text-zinc-300 transition hover:bg-white/[0.04]">Copy</button>
                <form method="POST" action="{{ route('admin.dashboard.reset-key') }}"
                      data-confirm="The current key stops working immediately — you will need to update your app with the new key."
                      data-confirm-title="Reset this API key?"
                      data-confirm-label="Reset key">
                    @csrf
                    <button class="rounded-[9px] border border-bad/40 px-4.5 py-3 text-sm text-bad transition hover:bg-bad/10" title="Issue a new key if this one leaked">Reset</button>
                </form>
            </div>
            <p class="mt-2 text-[12.5px] text-zinc-500">Leaked? <span class="text-zinc-300">Reset</span> issues a new key and immediately revokes this one.</p>
        @else
            <p class="text-sm text-zinc-500">No active key yet — <a class="text-accent hover:underline" href="{{ route('admin.api-keys.create') }}">create one</a>.</p>
        @endif

        {{-- Voice IDs --}}
        <div class="mt-5 mb-2.5 text-[13px] font-semibold text-zinc-300">Voice IDs</div>
        @if(count($connect['voiceIds']))
            <div class="flex flex-wrap gap-2.5">
                @foreach($connect['voiceIds'] as $vid)
                    <button data-copy="{{ $vid }}" data-voice-chip="{{ $vid }}"
                            class="rounded-lg border px-3.5 py-2 font-mono text-[13px] transition {{ $loop->first ? 'border-accent/50 bg-accent/10 text-accent' : 'border-white/[0.14] text-zinc-200 hover:bg-white/[0.04]' }}"
                            title="Copy — and use in the examples below">{{ $vid }}</button>
                @endforeach
            </div>
        @else
            <p class="text-sm text-zinc-500">No voices yet — <a class="text-accent hover:underline" href="{{ route('admin.voices.create') }}">add one</a>.</p>
        @endif

        <div class="my-5 h-px bg-white/8"></div>

        {{-- cURL examples: how the three values above combine into a real request.
             Clicking a voice chip swaps its ID into both examples (app.js). The
             Copy buttons copy the rendered command text via data-copy-from, so
             they always match what's on screen. --}}
        <div class="text-[13px] font-semibold text-zinc-300">How it fits together</div>
        <p class="mt-1 mb-4 text-[12.5px] text-zinc-500">The Base URL, API key, and a voice ID form a complete request in either dialect. Click a voice ID above to swap it into both examples.</p>

        <div class="mb-1.5 text-[13px] font-semibold text-zinc-300">ElevenLabs-compatible example</div>
        <div class="mb-5 flex items-start gap-2.5">
            <pre class="flex-1 overflow-x-auto rounded-[9px] border border-white/12 bg-inset px-4 py-3.5 font-mono text-[13px] leading-[1.7] text-zinc-200"><code id="connect-example-el">curl -X POST <span class="text-accent">{{ $connect['baseUrl'] }}</span>/v1/text-to-speech/<span class="text-accent" data-example-voice>{{ $exampleVoice }}</span> \
  -H "xi-api-key: <span class="text-accent">{{ $exampleKey }}</span>" \
  -H "Content-Type: application/json" \
  -d '{"text": "{{ $exampleText }}"}' \
  --output alias-speech.mp3</code></pre>
            <button data-copy-from="#connect-example-el" class="rounded-[9px] border border-white/[0.14] px-4.5 py-3 text-sm text-zinc-300 transition hover:bg-white/[0.04]">Copy</button>
        </div>

        <div class="mb-1.5 text-[13px] font-semibold text-zinc-300">OpenAI-compatible example</div>
        <div class="flex items-start gap-2.5">
            <pre class="flex-1 overflow-x-auto rounded-[9px] border border-white/12 bg-inset px-4 py-3.5 font-mono text-[13px] leading-[1.7] text-zinc-200"><code id="connect-example-openai">curl -X POST <span class="text-accent">{{ $connect['baseUrl'] }}</span>/v1/audio/speech \
  -H "Authorization: Bearer <span class="text-accent">{{ $exampleKey }}</span>" \
  -H "Content-Type: application/json" \
  -d '{"model": "gpt-4o-mini-tts", "voice": "<span class="text-accent" data-example-voice>{{ $exampleVoice }}</span>", "input": "{{ $exampleText }}"}' \
  --output alias-speech.mp3</code></pre>
            <button data-copy-from="#connect-example-openai" class="rounded-[9px] border border-white/[0.14] px-4.5 py-3 text-sm text-zinc-300 transition hover:bg-white/[0.04]">Copy</button>
        </div>
        <p class="mt-2 text-[12.5px] text-zinc-500"><code class="font-mono text-zinc-400">model</code> accepts <code class="font-mono text-zinc-400">chatterbox</code> or <code class="font-mono text-zinc-400">chatterbox-turbo</code> to pick the engine for that request; any other value (like <code class="font-mono text-zinc-400">gpt-4o-mini-tts</code>) is accepted for compatibility and the voice's own engine decides.</p>
    </div>

    {{-- System --}}
    <div class="mb-3 text-xs font-bold uppercase tracking-[0.1em] text-zinc-500">System</div>
    <div class="flex flex-wrap gap-3">
        {{-- Health: a plain link only. No status indicator — status is only known
             after the Health page runs its full test suite. SuperAdmin only,
             matching the server-side route gate. --}}
        @if($navUser?->isSuperAdmin())
            <a href="{{ route('admin.health') }}" class="inline-flex items-center gap-2 rounded-[10px] border border-white/12 px-4 py-[11px] text-sm text-zinc-300 transition hover:bg-white/[0.04]">Health</a>
        @endif
        <a href="{{ route('admin.settings.index') }}" class="inline-flex items-center gap-2 rounded-[10px] border border-white/12 px-4 py-[11px] text-sm text-zinc-300 transition hover:bg-white/[0.04]">Settings</a>
        {{-- Users: SuperAdmin only, and only once its routes exist (gated on the server too). --}}
        @if($navUser?->isSuperAdmin() && RouteFacade::has('admin.users.index'))
            <a href="{{ route('admin.users.index') }}" class="inline-flex items-center gap-2 rounded-[10px] border border-accent/30 bg-accent/[0.05] px-4 py-[11px] text-sm text-zinc-200 transition hover:bg-accent/10">
                Users
                <span class="rounded-[5px] bg-accent/[0.14] px-1.5 py-0.5 text-[10px] font-bold text-accent">ADMIN</span>
            </a>
        @endif
    </div>
</x-layout>
