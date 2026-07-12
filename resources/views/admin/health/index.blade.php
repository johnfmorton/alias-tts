<x-layout title="Health" description="Confirms the service is wired up correctly — some twenty checks across the runtime, storage, provider, queue, and the optional sidecars.">
    {{-- Diagnostics render asynchronously so the page paints instantly. The ~21
         checks (ffmpeg shell-out, storage probe, Whisper/Genblaze sidecar pings,
         and the deep provider/queue/upload probes) run in a follow-up request to
         admin.health.results; initHealthReport fetches it on load and shows a
         "running diagnostics" indicator so the page never reads as frozen. --}}
    <div data-health-report data-results-url="{{ route('admin.health.results') }}" class="mb-6">
        <noscript>
            <p class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-300">
                The diagnostics run with JavaScript. Run <span class="font-mono">php artisan tts:doctor</span> from the CLI to see them without it.
            </p>
        </noscript>
    </div>

    {{-- Live provider test — independent of the diagnostic checks above, so it
         renders with the page shell and stays usable while the checks run. --}}
    <div class="mb-6 rounded-xl border border-zinc-800 bg-zinc-900/50">
        <div class="border-b border-zinc-800 px-5 py-4">
            <h2 class="font-semibold">Live provider test</h2>
            <p class="mt-1 text-sm text-zinc-400">
                Generates real audio through the provider. <span class="text-zinc-300">Short</span> tests the synchronous
                path; <span class="text-zinc-300">long</span> tests async chunking, the queue worker, and concatenation.
                Each run makes a real, billable provider call.
            </p>
        </div>
        <div class="space-y-4 p-5">
            @if($voices->isEmpty())
                <p class="text-sm text-zinc-500">No voices configured — <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.create') }}">add one</a> to run a test.</p>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <label for="health-test-voice" class="text-sm text-zinc-400">Voice</label>
                    <select id="health-test-voice" class="rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm">
                        @foreach($voices as $v)
                            <option value="{{ $v->slug }}">{{ $v->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" data-test-short
                            data-url="{{ route('admin.health.test.short') }}"
                            data-voice-select="#health-test-voice"
                            data-audio-target="#health-test-audio"
                            data-status-target="#health-test-status"
                            class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">Test short text</button>
                    <button type="button" data-test-long
                            data-url="{{ route('admin.health.test.long') }}"
                            data-voice-select="#health-test-voice"
                            data-audio-target="#health-test-audio"
                            data-status-target="#health-test-status"
                            class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">Test long text (async)</button>
                </div>
                <div id="health-test-status" class="text-sm text-zinc-400" role="status" aria-live="polite"></div>
                <audio id="health-test-audio" controls class="hidden w-full"></audio>
            @endif
        </div>
    </div>
</x-layout>
