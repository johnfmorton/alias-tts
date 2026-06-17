<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bespoken TTS — self-hosted, ElevenLabs-compatible text-to-speech</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-zinc-950 text-zinc-100 antialiased">
    <div class="mx-auto flex min-h-full max-w-2xl flex-col items-center justify-center px-6 py-16 text-center">
        <span class="grid h-12 w-12 place-items-center rounded-xl bg-cyan-500 text-lg font-bold text-zinc-950">B</span>
        <h1 class="mt-6 text-3xl font-semibold tracking-tight sm:text-4xl">Bespoken TTS</h1>
        <p class="mt-4 text-balance text-zinc-400">
            A self-hosted, ElevenLabs-compatible text-to-speech service with voice cloning —
            the companion server for the Bespoken Craft CMS plugin.
        </p>
        <div class="mt-8 flex items-center gap-3">
            <a href="{{ route('login') }}"
               class="rounded-lg bg-cyan-500 px-5 py-2.5 text-sm font-medium text-zinc-950 transition hover:bg-cyan-400">
                Open dashboard
            </a>
            <a href="https://github.com/johnfmorton/bespoken-tts-service"
               class="rounded-lg border border-zinc-700 px-5 py-2.5 text-sm font-medium text-zinc-300 transition hover:bg-zinc-800">
                View on GitHub
            </a>
        </div>
        <p class="mt-10 font-mono text-xs text-zinc-600">POST /v1/text-to-speech/&#123;voice_id&#125;</p>
    </div>
</body>
</html>
