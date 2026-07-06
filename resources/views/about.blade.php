<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>About — Alias TTS</title>
    <meta name="description" content="Alias TTS is a self-hosted text-to-speech server compatible with the ElevenLabs and OpenAI APIs — zero-shot voice cloning, automatic quality checks, a per-chunk Studio editor, and sealed, verifiable finals.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('alias-icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css'])
    <style>
        /* Same bridge as the landing page: the logo's blues/violet as text. */
        .grad-voice {
            background: linear-gradient(90deg, #22d3ee 0%, #246cff 42%, #6164ff 68%, #b129ff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        @supports not ((-webkit-background-clip: text) or (background-clip: text)) {
            .grad-voice { color: #7c83ff; }
        }

        .rise { opacity: 0; animation: fadeUp .7s cubic-bezier(.2, .75, .25, 1) forwards; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            .rise { animation: none !important; opacity: 1 !important; transform: none !important; }
        }

        a:focus-visible { outline: 2px solid #22d3ee; outline-offset: 3px; border-radius: .65rem; }

        /* Inline code inside body copy. */
        .k {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .85em;
            color: #d4d4d8;
            background: rgba(255, 255, 255, .05);
            padding: .1em .4em;
            border-radius: .35em;
        }
    </style>
</head>
<body class="h-full bg-[#0a0a0a] text-zinc-100 antialiased">

    <header class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6">
        <a href="{{ route('landing') }}" class="flex items-center gap-2.5">
            <img src="{{ asset('alias-icon-on-dark.svg') }}" alt="" class="h-7 w-7">
            <span class="text-[17px] font-bold leading-none tracking-[0.2px] text-[#f4f4f5]">Alias<span class="font-semibold text-[#9aa0a6]"> TTS</span></span>
        </a>
        <nav class="flex items-center gap-5 text-sm">
            {{-- Repo is private for now; restore when it goes public again.
            <a href="https://github.com/johnfmorton/alias-tts" class="font-medium text-zinc-400 transition hover:text-zinc-100">GitHub</a>
            --}}
            <a href="{{ auth()->check() ? route('admin.dashboard') : route('login') }}"
               class="rounded-lg bg-[#22d3ee] px-3.5 py-2 text-[13px] font-semibold text-[#062326] transition hover:brightness-110">
                Open dashboard
            </a>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-5xl px-6 pb-8">

        {{-- ===== Hero: the thesis is the migration itself — one changed line. ===== --}}
        <section class="relative pt-14 text-center sm:pt-20">
            <div aria-hidden="true"
                 class="pointer-events-none absolute left-1/2 top-[55%] -z-10 h-80 w-[46rem] max-w-[120vw] -translate-x-1/2 -translate-y-1/2 blur-3xl"
                 style="background: radial-gradient(48% 55% at 50% 50%, rgba(97,100,255,.18), rgba(34,211,238,.09) 46%, transparent 72%);"></div>

            <h1 class="rise mx-auto max-w-3xl text-4xl font-semibold leading-[1.05] tracking-tight sm:text-[3.3rem]" style="animation-delay: 0ms">
                <span class="block">Point your app here.</span>
                <span class="grad-voice block">Nothing else changes.</span>
            </h1>

            <p class="rise mx-auto mt-6 max-w-2xl leading-relaxed text-zinc-400" style="animation-delay: 90ms">
                Alias TTS is a self-hosted text-to-speech server that answers to two APIs — ElevenLabs and OpenAI.
                Your client keeps its request shapes, auth headers, and error handling. Behind them, it gets your
                own cloned voices, your own storage, and a GPU you pay by the second.
            </p>

            <div class="rise mx-auto mt-10 max-w-2xl text-left" style="animation-delay: 180ms">
                <div class="overflow-x-auto rounded-xl border border-zinc-800 bg-[#0d0d10] px-5 py-4 font-mono text-[13px] leading-[2]">
                    <div class="whitespace-pre text-zinc-400"><span class="text-zinc-600">  </span>curl -X POST \</div>
                    <div class="whitespace-pre rounded bg-rose-500/[0.07] text-zinc-500"><span class="text-rose-400/80">- </span>  https://api.elevenlabs.io<span class="text-zinc-600">/v1/text-to-speech/rachel \</span></div>
                    <div class="whitespace-pre rounded bg-cyan-400/[0.09] text-cyan-100"><span class="text-cyan-300">+ </span>  https://tts.your-domain.com<span class="text-cyan-100/70">/v1/text-to-speech/rachel \</span></div>
                    <div class="whitespace-pre text-zinc-400"><span class="text-zinc-600">  </span>  -H "xi-api-key: sk_..." \</div>
                    <div class="whitespace-pre text-zinc-400"><span class="text-zinc-600">  </span>  -d '{"text": "Hello from a server I own."}'</div>
                </div>
                <p class="mt-3 text-center text-xs leading-relaxed text-zinc-500">
                    That's the whole migration. OpenAI clients switch the same way — <span class="font-mono text-zinc-400">POST /v1/audio/speech</span> with a Bearer key.
                </p>
            </div>
        </section>

        {{-- ===== The API (cyan — the left edge of the waveform) ===== --}}
        <section class="mt-28">
            <div class="flex items-center gap-2.5">
                <x-about.glyph color="#22d3ee"/>
                <span class="font-mono text-xs font-semibold uppercase tracking-[0.18em] text-[#22d3ee]">The API</span>
            </div>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Two dialects. One engine.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">
                Every request — either dialect — lands on the same pipeline: authenticate, check the cache,
                split long text into sentence-aware chunks, generate on the GPU, clean up with ffmpeg, and
                stitch the seams so you can't hear them.
            </p>

            <div class="mt-10 grid gap-x-12 gap-y-8 sm:grid-cols-2">
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Drop-in ElevenLabs</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        <span class="k">POST /v1/text-to-speech/{voice_id}</span> and <span class="k">/stream</span>,
                        with ElevenLabs auth headers, body shapes, and error formats. Settings like
                        <span class="k">stability</span> and <span class="k">style</span> map onto the engine's own controls.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Drop-in OpenAI</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        <span class="k">POST /v1/audio/speech</span> with Bearer auth and OpenAI-style errors.
                        Stock preset names like <span class="k">alloy</span> can alias to your own voices.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Caching built in</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Identical requests are served straight from cache and stamped
                        <span class="k">x-cache: HIT</span>. The second run of the same text costs nothing.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Async jobs for long text</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Submit up to ~40,000 characters — roughly 40–50 minutes of finished audio — then poll
                        the job and download the result. No HTTP timeout in the way.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Click-free seams</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Chunks are edge-trimmed with short fades and joined with brief, controlled silence,
                        so long reads come back as one clean file.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Per-key rate limits</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Every API key carries its own hourly budget, set when you mint it — one noisy
                        integration can't starve the rest.
                    </p>
                </div>
            </div>

            <x-about.shot class="mt-12" file="dashboard.png"
                          url="https://tts.your-domain.com/admin"
                          note="The dashboard home, signed in: the connection panel with base URL, API key, and voice IDs ready to copy into any client."/>
        </section>

        {{-- ===== Voices (bright blue) ===== --}}
        <section class="mt-28">
            <div class="flex items-center gap-2.5">
                <x-about.glyph color="#009ff5"/>
                <span class="font-mono text-xs font-semibold uppercase tracking-[0.18em] text-[#2eb4ff]">Voices</span>
            </div>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Clone a voice from thirty seconds of audio.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">
                The engine (<a href="https://replicate.com/resemble-ai/chatterbox" class="text-zinc-300 underline decoration-zinc-700 underline-offset-2 transition hover:decoration-zinc-400">Chatterbox</a>, MIT-licensed)
                is zero-shot: a clean 15–30&nbsp;second reference clip is the entire setup. No training job, no waiting.
            </p>

            <div class="mt-10 grid items-start gap-10 lg:grid-cols-2">
                <div class="space-y-7">
                    <div>
                        <h3 class="text-[15px] font-semibold text-zinc-100">Record right in the browser</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                            Read one of the built-in teleprompter prompts, review the take, save. Or upload a
                            clip you already have.
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[15px] font-semibold text-zinc-100">Automatic cleanup</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                            An optional denoise-and-enhance pass with an original-vs-cleaned preview before you
                            commit. Every reference clip is normalized — mono, trimmed, loudness-leveled,
                            peak-capped — for consistent clones.
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[15px] font-semibold text-zinc-100">Tune by ear</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                            An A/B bench on the voice's edit page: compare two deliveries of the same line side
                            by side, then save the winner as the voice's default or a named preset.
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[15px] font-semibold text-zinc-100">Voices travel</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                            Export any voice as a portable <span class="k">.zip</span> — clip, settings, and all —
                            and import it on another install.
                        </p>
                    </div>
                </div>
                <x-about.shot file="voices.png"
                              url="https://tts.your-domain.com/admin/voices/your-voice/edit"
                              note="A voice's edit page: the delivery dials and the Tune-by-ear A/B bench."/>
            </div>
        </section>

        {{-- ===== Quality (mid blue) ===== --}}
        <section class="mt-28">
            <div class="flex items-center gap-2.5">
                <x-about.glyph color="#246cff"/>
                <span class="font-mono text-xs font-semibold uppercase tracking-[0.18em] text-[#5b8dff]">Quality</span>
            </div>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">QA that listens to every take.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">
                TTS models flub takes — they stop short, trail into noise, or hum through a pause. Alias can
                transcribe every generated chunk with a local Whisper sidecar, compare it to the script, and
                re-roll or trim a flawed take automatically — before you ever hear it.
            </p>

            <div class="mt-8 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <div class="rounded-lg border border-zinc-800 bg-white/[0.02] px-4 py-3">
                    <div class="font-mono text-[13px] font-semibold text-[#5b8dff]">TRUNC</div>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">Stopped before the end of the script.</p>
                </div>
                <div class="rounded-lg border border-zinc-800 bg-white/[0.02] px-4 py-3">
                    <div class="font-mono text-[13px] font-semibold text-[#5b8dff]">TAIL</div>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">Long junk tail after the last word.</p>
                </div>
                <div class="rounded-lg border border-zinc-800 bg-white/[0.02] px-4 py-3">
                    <div class="font-mono text-[13px] font-semibold text-[#5b8dff]">TAILNOISE</div>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">Short but loud artifact past the last word.</p>
                </div>
                <div class="rounded-lg border border-zinc-800 bg-white/[0.02] px-4 py-3">
                    <div class="font-mono text-[13px] font-semibold text-[#5b8dff]">PAUSE</div>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">Dead air hanging mid-stream.</p>
                </div>
                <div class="rounded-lg border border-zinc-800 bg-white/[0.02] px-4 py-3">
                    <div class="font-mono text-[13px] font-semibold text-[#5b8dff]">BNDNOISE</div>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">Tonal hum filling a sentence boundary.</p>
                </div>
            </div>

            <div class="mt-8 rounded-xl border border-zinc-800 bg-white/[0.02] px-6 py-5">
                <h3 class="text-[15px] font-semibold text-zinc-100">Pronunciation dictionary</h3>
                <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-zinc-400">
                    An LLM pass flags the words a model will mangle — initialisms, product names, unusual proper
                    nouns — and suggests phonetic respellings (<span class="k">DDEV</span> → <span class="k">dee dev</span>).
                    Approve a fix once and it's applied to everything you generate afterward. Dictionaries are
                    per-user, and clients can read them at <span class="k">GET /v1/pronunciations</span>.
                </p>
            </div>
        </section>

        {{-- ===== Studio (violet) — the control room. ===== --}}
        <section class="mt-28">
            <div class="flex items-center gap-2.5">
                <x-about.glyph color="#6164ff"/>
                <span class="font-mono text-xs font-semibold uppercase tracking-[0.18em] text-[#8b8eff]">Studio</span>
            </div>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Fix the sentence, not the file.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">
                Studio is where you take full control. Every project is broken into chunks you can hear, edit,
                and regenerate one at a time — so a forty-minute build never gets thrown away over one flat line.
            </p>

            <div class="mt-10 grid gap-x-12 gap-y-8 sm:grid-cols-2">
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Inspector</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Paste text, pick a voice, and see exactly how production will normalize and chunk it —
                        the preview uses the same code as the real pipeline and costs nothing.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Hear it three ways</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        One whole-text call, raw chunk-by-chunk, or stitched the way production joins it — the
                        fastest way to hunt down a seam artifact.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Per-chunk regeneration</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Edit, insert, or delete chunks and regenerate just one. Edited chunks are marked stale,
                        so what needs a fresh take is always visible.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Keep the take you approved</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Re-roll a chunk until it reads right, A/B the takes, and keep the winner — the exact
                        audio you heard, byte for byte.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Seam preview</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Audition the stitch across any boundary — with QA flags shown inline on each chunk —
                        before rebuilding the whole file.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Build &amp; download</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Rebuild the final as MP3 or WAV, per your account's output setting, and download it from
                        the same page.
                    </p>
                </div>
            </div>

            <x-about.shot class="mt-12" file="studio.png"
                          url="https://tts.your-domain.com/admin/studio/projects/42"
                          note="A Studio project: the chunk list with per-chunk playback, edit state, and QA badges."/>
        </section>

        {{-- ===== Provenance (magenta — the right edge of the waveform) ===== --}}
        <section class="mt-28">
            <div class="flex items-center gap-2.5">
                <x-about.glyph color="#b129ff"/>
                <span class="font-mono text-xs font-semibold uppercase tracking-[0.18em] text-[#c55dff]">Provenance</span>
            </div>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Approve it. Seal it. Prove it.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">
                When a build is the final, seal it: the project records a SHA-256 fingerprint of the exact audio
                bytes plus a frozen snapshot of how they were made — and exports a receipt anyone can check.
            </p>

            <div class="mt-10 grid items-start gap-10 lg:grid-cols-2">
                <div class="space-y-7">
                    <div>
                        <h3 class="text-[15px] font-semibold text-zinc-100">The receipt</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                            A <span class="k">.zip</span> holding the audio, a human-readable provenance record —
                            including the script of every chunk — and a machine-readable manifest.
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[15px] font-semibold text-zinc-100">Public verification</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                            Anyone can drop a file on the public <a href="{{ route('verify') }}" class="text-zinc-300 underline decoration-zinc-700 underline-offset-2 transition hover:decoration-zinc-400">/verify</a>
                            page to confirm it's the untouched, approved final.
                        </p>
                    </div>
                    <div>
                        <h3 class="text-[15px] font-semibold text-zinc-100">Private by design</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                            The browser fingerprints the file locally — the audio never leaves the verifier's
                            machine. Only the 64-character hash is sent and matched against the seal.
                        </p>
                    </div>
                </div>
                <x-about.shot file="verify.png"
                              url="https://tts.your-domain.com/verify"
                              note="The public verify page confirming a dropped file matches the sealed approval."/>
            </div>
        </section>

        {{-- ===== Ownership — the full spectrum, because you run every band of it. ===== --}}
        <section class="mt-28">
            <div class="flex items-center gap-2.5">
                <x-about.glyph gradient gid="g-own"/>
                <span class="font-mono text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Ownership</span>
            </div>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Yours, all the way down.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">
                Alias TTS is a stack you can read, point at, and run — not an account you rent.
            </p>

            <div class="mt-10 grid gap-x-12 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Your server</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        A Laravel app you deploy anywhere PHP 8.3 runs, on SQLite, MySQL, or Postgres.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Your storage</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Audio and reference clips live on your disk or your own S3-compatible bucket.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Your GPU bill</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Generation runs on a pay-per-second GPU via Replicate. Low or bursty volume doesn't pay
                        a monthly subscription.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Your team</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        A multi-user dashboard: every account gets its own keys, voices, dictionary, and settings.
                    </p>
                </div>
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Your health checks</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        <span class="k">tts:doctor</span> and the dashboard's Health page verify the whole
                        install, with one-click end-to-end test generations.
                    </p>
                </div>
                {{-- Sixth-card option A: provenance. Keep one of A/B and delete the other. --}}
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Your proof</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Seal a finished project and it ships with a receipt — anyone can verify,
                        byte for byte, that a file is the audio you approved.
                    </p>
                </div>
                {{-- Sixth-card option B: pronunciation dictionary. --}}
                <div>
                    <h3 class="text-[15px] font-semibold text-zinc-100">Your dictionary</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-400">
                        Teach it how to say your names, brands, and acronyms once — every future
                        generation pronounces them your way.
                    </p>
                </div>
            </div>
        </section>

        {{-- ===== Closing CTA ===== --}}
        <section class="mt-32 flex flex-col items-center text-center">
            <x-about.glyph gradient gid="g-cta" size="h-8"/>
            <h2 class="mt-6 text-3xl font-semibold tracking-tight">Give your app a new address.</h2>
            <div class="mt-8 flex items-center gap-3">
                <a href="{{ auth()->check() ? route('admin.dashboard') : route('login') }}"
                   class="rounded-lg bg-[#22d3ee] px-5 py-2.5 text-sm font-semibold text-[#062326] transition hover:brightness-110">
                    Open dashboard
                </a>
                {{-- Repo is private for now; restore when it goes public again.
                <a href="https://github.com/johnfmorton/alias-tts"
                   class="rounded-lg border border-zinc-700 px-5 py-2.5 text-sm font-medium text-zinc-300 transition hover:border-zinc-500 hover:bg-white/5">
                    View on GitHub
                </a>
                --}}
            </div>
            <p class="mt-6 text-xs text-zinc-500">
                Works with any ElevenLabs- or OpenAI-compatible client — including the Bespoken plugin for Craft CMS.
            </p>
        </section>
    </main>

    <footer class="mt-24 border-t border-zinc-900 py-10 text-center text-xs text-zinc-600">
        Alias TTS
        <span class="mx-1.5 text-zinc-800">·</span><a href="{{ route('landing') }}" class="transition hover:text-zinc-400">Home</a>
        <span class="mx-1.5 text-zinc-800">·</span><a href="{{ route('verify') }}" class="transition hover:text-zinc-400">Verify a file</a>
        {{-- Repo is private for now; restore when it goes public again.
        <span class="mx-1.5 text-zinc-800">·</span><a href="https://github.com/johnfmorton/alias-tts" class="transition hover:text-zinc-400">GitHub</a>
        --}}
        <span class="mx-1.5 text-zinc-800">·</span>© 2026 John F. Morton
    </footer>
</body>
</html>
