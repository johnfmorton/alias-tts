<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bespoken TTS — self-hosted, ElevenLabs- &amp; OpenAI-compatible text-to-speech</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('bespoken-icon.svg') }}" type="image/svg+xml">
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

        @media (prefers-reduced-motion: reduce) {
            .wf-bar, .rise { animation: none !important; opacity: 1 !important; transform: none !important; }
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

        {{-- Brand lockup --}}
        <div class="rise flex items-center gap-2.5" style="animation-delay: 0ms">
            <img src="{{ asset('bespoken-icon-on-dark.svg') }}" alt="" class="h-8 w-8">
            <span class="text-lg font-semibold tracking-tight text-zinc-200">Bespoken TTS</span>
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
                Open dashboard
            </a>
            <a href="https://github.com/johnfmorton/bespoken-tts-service"
               class="rounded-lg border border-zinc-700 px-5 py-2.5 text-sm font-medium text-zinc-300 transition hover:border-zinc-500 hover:bg-white/5">
                View on GitHub
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

            {{-- Two addresses, one engine. Dots pick up the waveform's two ends. --}}
            <div class="mt-5 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 font-mono text-[11px] text-zinc-500">
                <span class="inline-flex items-center gap-2">
                    <span class="h-1.5 w-1.5 rounded-full" style="background:#22d3ee"></span>POST /v1/text-to-speech/&#123;voice_id&#125;
                </span>
                <span class="inline-flex items-center gap-2">
                    <span class="h-1.5 w-1.5 rounded-full" style="background:#b129ff"></span>POST /v1/audio/speech
                </span>
            </div>
        </div>

        <p class="rise mt-9 text-xs text-zinc-500" style="animation-delay: 360ms">
            Works with the Bespoken Craft CMS plugin — or any app you point at it.
        </p>
    </main>
</body>
</html>
