<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alias TTS — self-hosted ElevenLabs &amp; OpenAI text-to-speech</title>
    @include('partials.social-meta', [
        'metaTitle'       => 'Alias TTS — one server, two APIs, your voices',
        'metaDescription' => 'Self-hosted text-to-speech with voice cloning, compatible with the ElevenLabs and OpenAI APIs — on a server you own.',
        'metaImage'       => 'images/social/alias-tts-og.png',
    ])
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('alias-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css'])
    <style>
        /* The brand's own waveform palette (logo blues/violet) bridged into the
           product's cyan accent — reused by the headline payoff + the signature. */
        .grad-voice {
            background: linear-gradient(90deg, #22d3ee 0%, #246cff 42%, #6164ff 68%, #b129ff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        @supports not ((-webkit-background-clip: text) or (background-clip: text)) {
            .grad-voice { color: #7c83ff; }
        }

        /* Signature: the logo's rounded bars, unspooled into an utterance that
           rises left-to-right on load (one orchestrated moment, not scattered). */
        .wf-bar {
            transform-box: fill-box;
            transform-origin: 50% 50%;
            animation: wfRise .55s cubic-bezier(.2, .75, .25, 1) both;
        }
        @keyframes wfRise {
            from { transform: scaleY(.08); opacity: .3; }
            to   { transform: scaleY(1);   opacity: 1;  }
        }

        .rise { opacity: 0; animation: fadeUp .7s cubic-bezier(.2, .75, .25, 1) forwards; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: none; }
        }

        /* Junction hairlines: each API drops a line that draws itself toward the
           Studio plate once the waveform has risen. */
        .wf-link {
            stroke-dasharray: 1;
            stroke-dashoffset: 1;
            animation: wfLink .7s cubic-bezier(.2, .75, .25, 1) forwards;
            animation-delay: .62s;
        }
        @keyframes wfLink { to { stroke-dashoffset: 0; } }

        @media (prefers-reduced-motion: reduce) {
            .wf-bar, .rise { animation: none !important; opacity: 1 !important; transform: none !important; }
            .wf-link { animation: none !important; stroke-dashoffset: 0; }
        }

        a:focus-visible { outline: 2px solid #22d3ee; outline-offset: 3px; border-radius: .65rem; }
    </style>
</head>
<body class="h-full bg-[#0a0a0a] text-zinc-100 antialiased">
    <main class="relative mx-auto flex min-h-full max-w-2xl flex-col items-center justify-center overflow-hidden px-6 py-20 text-center">
        {{-- Ambient glow: the voice as a soft light source behind the waveform. --}}
        <div aria-hidden="true"
             class="pointer-events-none absolute left-1/2 top-[62%] -z-10 h-72 w-[42rem] max-w-[120vw] -translate-x-1/2 -translate-y-1/2 blur-3xl"
             style="background: radial-gradient(48% 55% at 50% 50%, rgba(97,100,255,.20), rgba(34,211,238,.10) 46%, transparent 72%);"></div>

        {{-- Brand lockup: the real waveform mark, sized up so the name reads as the
             brand — "Alias" carries it, "TTS" trails as a quiet descriptor. --}}
        <div class="rise flex items-center gap-[13px]" style="animation-delay: 0ms">
            <img src="{{ asset('alias-icon-on-dark.svg') }}" alt="" class="h-11 w-11">
            <span class="text-[26px] font-bold leading-none tracking-[0.2px] text-[#f4f4f5]">Alias<span class="font-semibold text-[#9aa0a6]"> TTS</span></span>
        </div>

        {{-- Thesis: the three pillars as a tricolon — self-hosted, dual-API, cloning. --}}
        <h1 class="rise mt-9 text-4xl font-semibold leading-[1.03] tracking-tight sm:text-5xl" style="animation-delay: 80ms">
            <span class="block">One server,</span>
            <span class="block">two APIs,</span>
            <span class="grad-voice block">your voices.</span>
        </h1>

        <p class="rise mt-6 max-w-md leading-relaxed text-zinc-400" style="animation-delay: 160ms">
            Self-hosted text-to-speech with voice cloning, compatible with the ElevenLabs and OpenAI APIs.
        </p>

        <div class="rise mt-9 flex items-center gap-3" style="animation-delay: 240ms">
            <a href="{{ auth()->check() ? route('admin.dashboard') : route('login') }}"
               class="rounded-lg bg-[#22d3ee] px-5 py-2.5 text-sm font-semibold text-[#062326] transition hover:brightness-110">
                {{ auth()->check() ? 'Open dashboard' : 'Log in' }}
            </a>
            {{-- Repo is private for now; restore when it goes public again.
            <a href="https://github.com/johnfmorton/alias-tts"
               class="rounded-lg border border-zinc-700 px-5 py-2.5 text-sm font-medium text-zinc-300 transition hover:border-zinc-500 hover:bg-white/5">
                View on GitHub
            </a>
            --}}
            <a href="{{ route('about') }}"
               class="px-2 py-2.5 text-sm font-medium text-zinc-400 transition hover:text-zinc-100">
                About<span aria-hidden="true"> &rarr;</span>
            </a>
        </div>

        {{-- Signature: the logo's waveform stretched into an utterance. --}}
        @php
            $N = 57; $W = 1140; $H = 120; $bw = 8; $pitch = $W / $N; $maxH = $H * 0.92;
            $bars = [];
            for ($i = 0; $i < $N; $i++) {
                $t = $i / ($N - 1);
                $env = 0.30 + 0.70 * sin(M_PI * $t);                       // centre-peaked envelope
                $detail = 0.55 + 0.45 * abs(sin($t * M_PI * 6.3 + 0.6)) * (0.6 + 0.4 * sin($t * M_PI * 12.7));
                $bh = max(0.08, min(1.0, $env * $detail)) * $maxH;
                $bars[] = [
                    round($i * $pitch + ($pitch - $bw) / 2, 2),
                    round(($H - $bh) / 2, 2),
                    round($bh, 2),
                    (int) round($i * 11),                                  // left→right stagger (ms)
                ];
            }
        @endphp
        <div class="rise mt-16 w-full max-w-xl" style="animation-delay: 300ms">
            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-16 w-full" aria-hidden="true" preserveAspectRatio="xMidYMid meet">
                <defs>
                    <linearGradient id="wf" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0" stop-color="#22d3ee"/>
                        <stop offset="0.22" stop-color="#009ff5"/>
                        <stop offset="0.45" stop-color="#246cff"/>
                        <stop offset="0.68" stop-color="#6164ff"/>
                        <stop offset="1" stop-color="#b129ff"/>
                    </linearGradient>
                </defs>
                @foreach ($bars as [$x, $y, $bh, $delay])
                    <rect class="wf-bar" x="{{ $x }}" y="{{ $y }}" width="{{ $bw }}" height="{{ $bh }}"
                          rx="{{ $bw / 2 }}" fill="url(#wf)" style="animation-delay: {{ $delay }}ms"/>
                @endforeach
            </svg>

            {{-- The legend is a signal-flow junction: the two dialects (cyan and
                 magenta, the waveform's ends) each drop a hairline that converges —
                 fading to the gradient's violet — into the Studio plate, where any
                 of those calls can land as an editable project. --}}
            <div class="mt-8 grid grid-cols-1 gap-y-5 sm:grid-cols-2">
                <div class="flex flex-col items-center text-center">
                    <div class="flex items-center gap-[9px]">
                        <span class="h-[9px] w-[9px] shrink-0 rounded-full"
                              style="background:#22d3ee; box-shadow:0 0 10px rgba(34,211,238,.7)"></span>
                        <span class="text-sm font-semibold text-[#e9e9ec]">ElevenLabs-compatible</span>
                    </div>
                    <div class="mt-[3px] font-mono text-[13px] text-[#7c828a]">POST /v1/text-to-speech/&#123;voice_id&#125;</div>
                </div>
                <div class="flex flex-col items-center text-center">
                    <div class="flex items-center gap-[9px]">
                        <span class="h-[9px] w-[9px] shrink-0 rounded-full"
                              style="background:#b129ff; box-shadow:0 0 10px rgba(177,41,255,.7)"></span>
                        <span class="text-sm font-semibold text-[#e9e9ec]">OpenAI-compatible</span>
                    </div>
                    <div class="mt-[3px] font-mono text-[13px] text-[#7c828a]">POST /v1/audio/speech</div>
                </div>
            </div>

            {{-- The junction: one hairline from under each dialect, meeting at the plate. --}}
            <svg class="mt-1 hidden h-10 w-full sm:block" viewBox="0 0 576 40" fill="none"
                 preserveAspectRatio="none" aria-hidden="true">
                <defs>
                    <linearGradient id="lnk-el" x1="144" y1="0" x2="288" y2="0" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#6164ff"/>
                    </linearGradient>
                    <linearGradient id="lnk-oa" x1="432" y1="0" x2="288" y2="0" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#b129ff"/><stop offset="1" stop-color="#6164ff"/>
                    </linearGradient>
                </defs>
                <path class="wf-link" pathLength="1" d="M144 2 C 144 24, 288 14, 288 38"
                      stroke="url(#lnk-el)" stroke-width="1.25" stroke-linecap="round" opacity=".5"/>
                <path class="wf-link" pathLength="1" d="M432 2 C 432 24, 288 14, 288 38"
                      stroke="url(#lnk-oa)" stroke-width="1.25" stroke-linecap="round" opacity=".5"/>
            </svg>

            <div class="mx-auto mt-4 max-w-[400px] rounded-xl border border-[#6164ff]/25 bg-[#6164ff]/[0.06] px-5 py-3.5 text-center sm:mt-0">
                <div class="flex items-center justify-center gap-[9px]">
                    <span class="h-[9px] w-[9px] shrink-0 rounded-full"
                          style="background:#6164ff; box-shadow:0 0 10px rgba(97,100,255,.7)"></span>
                    <span class="text-sm font-semibold text-[#e9e9ec]">Studio</span>
                </div>
                <p class="mt-1 text-[13px] leading-relaxed text-[#7c828a]">
                    Any call can land as an editable project — fix a sentence, re-roll a take, seal the final.
                </p>
            </div>
        </div>

        <p class="rise mt-9 max-w-md text-xs leading-relaxed text-zinc-500" style="animation-delay: 360ms">
            Start by pointing an app at it — the Bespoken plugin for Craft CMS, or anything that
            speaks either API. Graduate to Studio when a take needs directing.
        </p>
    </main>
</body>
</html>
