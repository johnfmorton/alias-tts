<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alias TTS Studio for audio creators</title>
    @include('partials.social-meta', [
        'metaTitle' => 'Alias TTS Studio — shape every take',
        'metaDescription' => 'Clone a voice, prepare the script, direct every take, and export a polished, verifiable audio final.',
        'metaImage' => 'images/social/alias-tts-og.png',
    ])
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('alias-icon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    <style>
        .grad-voice{background:linear-gradient(90deg,#22d3ee 0%,#246cff 42%,#6164ff 68%,#b129ff 100%);-webkit-background-clip:text;background-clip:text;color:transparent;padding-bottom:.12em;margin-bottom:-.12em}
        @supports not ((-webkit-background-clip:text) or (background-clip:text)){.grad-voice{color:#7c83ff}}
        a:focus-visible{outline:2px solid #6164ff;outline-offset:3px;border-radius:.65rem}
        .k{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85em;color:#d4d4d8;background:rgba(255,255,255,.05);padding:.1em .4em;border-radius:.35em}
        .journey-steps{position:relative;display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}
        .journey-steps::before{content:'';position:absolute;left:12.5%;right:12.5%;top:14px;height:1px;background:linear-gradient(90deg,rgba(0,159,245,.45),rgba(97,100,255,.55),rgba(177,41,255,.45))}
        .journey-step{position:relative;text-align:center}
        .journey-step-number{position:relative;z-index:1;margin:0 auto;display:grid;height:28px;width:28px;place-items:center;border:1px solid rgba(139,142,255,.45);border-radius:9999px;background:#0a0a0a;font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;color:#b8baff;box-shadow:0 0 18px rgba(97,100,255,.12)}
        .rise{opacity:0;animation:fadeUp .7s cubic-bezier(.2,.75,.25,1) forwards}
        @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
        @media (prefers-reduced-motion:reduce){.rise{animation:none!important;opacity:1!important;transform:none!important}}
    </style>
    @include('partials.analytics')
</head>
<body class="min-h-full bg-[#0a0a0a] text-zinc-100 antialiased">
    <header class="rise mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6" style="animation-delay: 0ms">
        <a href="{{ route('landing') }}" class="flex items-center gap-2.5"><img src="{{ asset('alias-icon-on-dark.svg') }}" alt="" class="h-7 w-7"><span class="text-[17px] font-bold">Alias<span class="font-semibold text-zinc-400"> TTS</span></span></a>
        <a href="{{ auth()->check() ? route('admin.studio.index') : route('login') }}" class="rounded-lg bg-violet-500 px-3.5 py-2 text-[13px] font-semibold text-white">{{ auth()->check() ? 'Open Studio' : 'Log in' }}</a>
    </header>

    <main class="mx-auto w-full max-w-5xl px-6 pb-10">
        <nav class="rise mx-auto mt-10 flex w-fit rounded-full border border-zinc-800 bg-white/[0.025] p-1 text-sm" style="animation-delay: 60ms" aria-label="About Alias TTS">
            <span class="rounded-full bg-zinc-100 px-4 py-2 font-medium text-zinc-950">For audio creators</span>
            <a href="{{ route('about.developers') }}" class="rounded-full px-4 py-2 text-zinc-400 transition hover:text-zinc-100">For developers</a>
        </nav>

        <section class="relative pt-16 text-center sm:pt-20">
            <div class="pointer-events-none absolute left-1/2 top-1/2 -z-10 h-80 w-[46rem] max-w-[120vw] -translate-x-1/2 -translate-y-1/2 blur-3xl" style="background:radial-gradient(48% 55% at 50% 50%,rgba(97,100,255,.18),rgba(177,41,255,.08) 50%,transparent 72%)"></div>
            <p class="rise font-mono text-xs font-semibold uppercase tracking-[.2em] text-violet-400" style="animation-delay: 120ms">Alias TTS for audio creators</p>
            <h1 class="rise mx-auto mt-5 max-w-3xl text-4xl font-semibold leading-[1.05] tracking-tight sm:text-[3.3rem]" style="animation-delay: 180ms">
                <span class="block">Make the voice yours.</span>
                <span class="grad-voice block">Shape every take.</span>
            </h1>
            <p class="rise mx-auto mt-6 max-w-2xl leading-relaxed text-zinc-400" style="animation-delay: 240ms">Start with a voice and a script. Alias prepares the words for speech, lets you direct the performance chunk by chunk, and preserves the exact final you approved.</p>
            <div class="rise journey-steps mx-auto mt-10 max-w-2xl text-xs text-zinc-400" style="animation-delay: 300ms" aria-label="Studio workflow">
                @foreach ([['1','Clone'],['2','Prepare'],['3','Direct'],['4','Seal']] as [$number,$label])
                    <div class="journey-step">
                        <span class="journey-step-number">{{ $number }}</span>
                        <span class="mt-3 block">{{ $label }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-28" id="voice">
            <p class="font-mono text-xs font-semibold uppercase tracking-[.18em] text-sky-400">Your voice</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Clone a voice from a short recording.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">Record in the browser or upload a clean reference clip. There is no training job to manage: choose the voice, tune its delivery, and start making audio.</p>
            <div class="mt-10 grid gap-8 sm:grid-cols-2">
                <div><h3 class="font-semibold">Record with a prompt</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Use the built-in teleprompter, review the recording, and keep the take that best represents the voice.</p></div>
                <div><h3 class="font-semibold">Clean the reference</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Preview optional denoising, then let Alias normalize and trim the clip for consistent results.</p></div>
                <div><h3 class="font-semibold">Tune by ear</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Render the same line at several settings, compare the performances, and save the winner as a preset.</p></div>
                <div><h3 class="font-semibold">Keep it portable</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Export the reference, settings, and presets together, ready to import wherever you use Alias.</p></div>
            </div>
            <x-about.shot class="mt-12" file="voices.png" url="https://aliastts.example.com/admin/voices/your-voice/edit" note="The voice workspace for recording, cleanup, delivery controls, and side-by-side auditions."/>
        </section>

        <section class="mt-28" id="prepare">
            <p class="font-mono text-xs font-semibold uppercase tracking-[.18em] text-blue-400">Before the take</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Prepare the script for the way people listen.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">Paste the script into Inspector to see how it will be cleaned, pronounced, and divided before you spend anything on generation.</p>
            <div class="mt-10 grid gap-8 sm:grid-cols-3">
                <div><h3 class="font-semibold">Pronunciation</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Teach Alias how to say product names, initials, and unusual proper nouns once, then reuse the correction.</p></div>
                <div><h3 class="font-semibold">Natural chunks</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Sentence-aware boundaries turn a long script into manageable passages without careless mid-thought cuts.</p></div>
                <div><h3 class="font-semibold">A free preview</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Review cleaned text, chunk boundaries, voice settings, and likely cost before creating the project.</p></div>
            </div>
        </section>

        <section class="mt-28" id="direct">
            <p class="font-mono text-xs font-semibold uppercase tracking-[.18em] text-violet-400">Studio</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Fix the sentence, not the file.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">Studio turns the script into an editable sequence of chunks. Hear the whole piece, isolate a seam, or focus on one line without losing the rest of the approved performance.</p>
            <div class="mt-10 grid gap-8 sm:grid-cols-2">
                <div><h3 class="font-semibold">Direct one take at a time</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Edit a line, adjust its delivery, and regenerate only that chunk. Compare takes and keep the one that reads right.</p></div>
                <div><h3 class="font-semibold">Cast each passage</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Use a different voice for any chunk—a narrator, guest, character, or quoted speaker—in one final file.</p></div>
                <div><h3 class="font-semibold">Let QA listen too</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Automatic checks can catch incomplete lines, noisy tails, suspicious pauses, and boundary hum before the final build.</p></div>
                <div><h3 class="font-semibold">Preserve your decisions</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Mute a passage without deleting it, duplicate the project for another cut, and retain the original you approved.</p></div>
            </div>
            <x-about.shot class="mt-12" file="studio.png" url="https://aliastts.example.com/admin/studio/projects/42" note="A Studio project with chunk editing, alternate takes, playback, and QA feedback in one workspace."/>
        </section>

        <section class="mt-28" id="final">
            <p class="font-mono text-xs font-semibold uppercase tracking-[.18em] text-fuchsia-400">The final</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight">Approve it. Seal it. Prove it.</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-zinc-400">Build the finished work as MP3 or WAV. When it is final, seal the exact audio with a record of the script, settings, chunks, and chosen takes.</p>
            <div class="mt-10 grid gap-8 sm:grid-cols-3">
                <div><h3 class="font-semibold">A finished master</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Clean seams join the approved chunks into one downloadable file.</p></div>
                <div><h3 class="font-semibold">A production receipt</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Export the audio with a readable record and machine-readable manifest.</p></div>
                <div><h3 class="font-semibold">A public check</h3><p class="mt-2 text-sm leading-relaxed text-zinc-400">Anyone can verify that the file they received is the untouched final you approved.</p></div>
            </div>
            <x-about.shot class="mt-12" file="verify.png" url="https://aliastts.example.com/verify" note="A public check confirming that an audio file matches its sealed approval."/>
        </section>

        <section class="mt-32 text-center">
            <h2 class="text-3xl font-semibold">Bring the script. Direct the performance.</h2>
            <p class="mx-auto mt-4 max-w-xl text-zinc-400">Alias handles the repetitive production work while you decide what the final should sound like.</p>
            <a href="{{ auth()->check() ? route('admin.studio.index') : route('login') }}" class="mt-8 inline-block rounded-lg bg-violet-500 px-5 py-2.5 text-sm font-semibold text-white">{{ auth()->check() ? 'Open Studio' : 'Log in' }}</a>
        </section>
    </main>
    <footer class="mt-24 border-t border-zinc-900 py-10 text-center text-xs text-zinc-600">Alias TTS · <a href="{{ route('landing') }}" class="hover:text-zinc-400">Home</a> · <a href="{{ route('about.developers') }}" class="hover:text-zinc-400">For developers</a></footer>
</body>
</html>
