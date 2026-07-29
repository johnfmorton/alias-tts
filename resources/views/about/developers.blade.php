<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alias TTS for developers</title>
    @include('partials.social-meta', [
        'metaTitle' => 'Alias TTS for developers — compatible APIs, production control',
        'metaDescription' => 'Keep your ElevenLabs or OpenAI integration and add cloned voices, editable Studio projects, automatic QA, and verifiable finals.',
        'metaImage' => 'images/social/alias-tts-about-og.png',
    ])
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('alias-icon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    <style>
        .grad-voice { background:linear-gradient(90deg,#22d3ee 0%,#246cff 42%,#6164ff 68%,#b129ff 100%);-webkit-background-clip:text;background-clip:text;color:transparent;padding-bottom:.12em;margin-bottom:-.12em }
        @supports not ((-webkit-background-clip:text) or (background-clip:text)){.grad-voice{color:#7c83ff}}
        a:focus-visible{outline:2px solid #22d3ee;outline-offset:3px;border-radius:.65rem}
        .k{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85em;color:#d4d4d8;background:rgba(255,255,255,.05);padding:.1em .4em;border-radius:.35em}
        .rise{opacity:0;animation:fadeUp .7s cubic-bezier(.2,.75,.25,1) forwards}
        @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
        @media (prefers-reduced-motion:reduce){.rise{animation:none!important;opacity:1!important;transform:none!important}}
    </style>
    @include('partials.analytics')
</head>
<body class="min-h-full bg-[#0a0a0a] text-zinc-100 antialiased">
    <header class="rise mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6" style="animation-delay: 0ms">
        <a href="{{ route('landing') }}" class="flex items-center gap-2.5">
            <img src="{{ asset('alias-icon-on-dark.svg') }}" alt="" class="h-7 w-7">
            <span class="text-[17px] font-bold">Alias<span class="font-semibold text-zinc-400"> TTS</span></span>
        </a>
        <a href="{{ auth()->check() ? route('admin.dashboard') : route('login') }}" class="rounded-lg bg-cyan-400 px-3.5 py-2 text-[13px] font-semibold text-cyan-950">
            {{ auth()->check() ? 'Open dashboard' : 'Log in' }}
        </a>
    </header>

    <main class="mx-auto w-full max-w-5xl px-6 pb-10">
        <nav class="rise mx-auto mt-10 flex w-fit rounded-full border border-zinc-800 bg-white/[0.025] p-1 text-sm" style="animation-delay: 60ms" aria-label="About Alias TTS">
            <a href="{{ route('about') }}" class="rounded-full px-4 py-2 text-zinc-400 transition hover:text-zinc-100">For audio creators</a>
            <span class="rounded-full bg-zinc-100 px-4 py-2 font-medium text-zinc-950">For developers</span>
        </nav>

        <section class="relative pt-16 text-center sm:pt-20">
            <div class="pointer-events-none absolute left-1/2 top-1/2 -z-10 h-80 w-[46rem] max-w-[120vw] -translate-x-1/2 -translate-y-1/2 blur-3xl" style="background:radial-gradient(48% 55% at 50% 50%,rgba(34,211,238,.14),rgba(97,100,255,.1) 48%,transparent 72%)"></div>
            <p class="rise font-mono text-xs font-semibold uppercase tracking-[.2em] text-cyan-400" style="animation-delay: 120ms">Alias TTS for developers</p>
            <h1 class="rise mx-auto mt-5 max-w-3xl text-4xl font-semibold leading-[1.05] tracking-tight sm:text-[3.3rem]" style="animation-delay: 180ms">
                <span class="block">Keep the client.</span>
                <span class="grad-voice block">Upgrade the workflow.</span>
            </h1>
            <p class="rise mx-auto mt-6 max-w-2xl leading-relaxed text-zinc-400" style="animation-delay: 240ms">
                Point an ElevenLabs- or OpenAI-compatible client at Alias TTS. Keep the request shapes,
                authentication, and errors your application already understands—then let any call become an
                editable Studio project with automatic QA and a verifiable final.
            </p>
            <div class="rise mx-auto mt-10 max-w-2xl overflow-x-auto rounded-xl border border-zinc-800 bg-[#0d0d10] px-5 py-4 text-left font-mono text-[13px] leading-[2]" style="animation-delay: 300ms">
                <div class="whitespace-pre text-zinc-400">  curl -X POST \</div>
                <div class="whitespace-pre rounded bg-rose-500/[.07] text-zinc-500"><span class="text-rose-400">- </span>  https://api.elevenlabs.io<span class="text-zinc-600">/v1/text-to-speech/rachel \</span></div>
                <div class="whitespace-pre rounded bg-cyan-400/[.09] text-cyan-100"><span class="text-cyan-300">+ </span>  https://aliastts.example.com<span class="text-cyan-100/70">/v1/text-to-speech/rachel \</span></div>
                <div class="whitespace-pre text-zinc-400">    -H "xi-api-key: sk_..."</div>
            </div>
        </section>

        <section class="mt-28" id="compatibility">
            <p class="font-mono text-xs font-semibold uppercase tracking-[.18em] text-cyan-400">Compatibility</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Two API dialects. One production pipeline.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">Both interfaces converge on the same voices, cache, sentence-aware chunking, generation, cleanup, and assembly.</p>
            <div class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                <div><h3 class="font-semibold">ElevenLabs-compatible</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400"><span class="k">POST /v1/text-to-speech/{voice_id}</span>, streaming, familiar headers, body fields, and error formats.</p></div>
                <div><h3 class="font-semibold">OpenAI-compatible</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400"><span class="k">POST /v1/audio/speech</span> with Bearer authentication and preset names that can map to your voices.</p></div>
                <div><h3 class="font-semibold">Built for long reads</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Async jobs accept long scripts, while caching and controlled seams keep repeat work fast and finished audio clean.</p></div>
            </div>
            <x-about.shot class="mt-12" file="dashboard.png" url="https://aliastts.example.com/admin" note="Connection details, API keys, and voice IDs ready to copy into a client."/>
        </section>

        <section class="mt-28" id="handoff">
            <p class="font-mono text-xs font-semibold uppercase tracking-[.18em] text-violet-400">API → Studio</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">A successful request can remain editable.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">Choose whether every API request, only a failed request, or no request leaves behind a project. When it does, the script is already split into chunks with its generated takes attached.</p>
            <div class="mt-10 grid gap-8 sm:grid-cols-2">
                <div><h3 class="font-semibold">Repair without starting over</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Edit or regenerate one chunk, compare takes, change the voice for a passage, and rebuild only when the project is ready.</p></div>
                <div><h3 class="font-semibold">Inspect before spending</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Preview cleaned text, chunk boundaries, pronunciation changes, and estimated cost before committing to a render.</p></div>
                <div><h3 class="font-semibold">Automatic QA</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Alias can compare each generated chunk to its script and flag or repair truncation, noise, and suspicious pauses.</p></div>
                <div><h3 class="font-semibold">Keys with boundaries</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Per-key hourly budgets keep one integration from exhausting the generation capacity intended for the rest.</p></div>
            </div>
            <x-about.shot class="mt-12" file="studio.png" url="https://aliastts.example.com/admin/studio/projects/42" note="An API-created Studio project with editable chunks, saved takes, and inline QA state."/>
        </section>

        <section class="mt-28" id="verification">
            <p class="font-mono text-xs font-semibold uppercase tracking-[.18em] text-fuchsia-400">Verification</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Ship the file with its proof.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">Seal an approved build with the SHA-256 fingerprint of its exact audio bytes and a frozen record of the script, chunks, settings, and selected takes. Anyone can later check that the file is unchanged.</p>
            <div class="mt-10 grid gap-8 sm:grid-cols-3">
                <div><h3 class="font-semibold">Portable receipt</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Export the audio with human-readable provenance and a machine-readable manifest.</p></div>
                <div><h3 class="font-semibold">Public check</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Drop a file on the verification page to confirm that it matches an approved final.</p></div>
                <div><h3 class="font-semibold">Local hashing</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">The browser computes the fingerprint locally; the audio itself is not uploaded for verification.</p></div>
            </div>
            <x-about.shot class="mt-12" file="verify.png" url="https://aliastts.example.com/verify" note="Public verification of a sealed, approved audio file."/>
        </section>

        <section class="mt-32 text-center">
            <h2 class="text-3xl font-semibold">Change the endpoint. Keep the integration.</h2>
            <p class="mx-auto mt-4 max-w-xl text-zinc-400">And when a take needs human judgment, open the project in Studio.</p>
            <a href="{{ auth()->check() ? route('admin.dashboard') : route('login') }}" class="mt-8 inline-block rounded-lg bg-cyan-400 px-5 py-2.5 text-sm font-semibold text-cyan-950">{{ auth()->check() ? 'Open dashboard' : 'Log in' }}</a>
        </section>
    </main>
    <footer class="mt-24 border-t border-zinc-900 py-10 text-center text-xs text-zinc-600">Alias TTS · <a href="{{ route('landing') }}" class="hover:text-zinc-400">Home</a> · <a href="{{ route('about') }}" class="hover:text-zinc-400">For audio creators</a></footer>
</body>
</html>
