<x-layout title="Generate via Genblaze" description="One unattended run: Genblaze orchestrates generate → QA-gated re-roll → stitch, and writes every take + a verifiable provenance manifest to Backblaze B2.">
    @php($up = (bool) ($health['reachable'] ?? false))

    <div id="genblaze" data-run-url="{{ route('admin.studio.genblaze.run') }}">

        {{-- Runner liveness --}}
        @if($up)
            <div class="mb-6 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-300">
                Runner up — Bespoken <span class="font-mono">{{ $health['body']['bespoken'] ?? '?' }}</span>,
                B2 {{ ($health['body']['b2'] ?? false) ? 'connected' : 'not configured' }}.
            </div>
        @else
            <div class="mb-6 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300">
                The Genblaze runner isn't reachable ({{ $health['error'] ?? 'unknown' }}). Start the
                <code class="font-mono">genblaze-runner</code> service and set <code class="font-mono">TTS_GENBLAZE_RUNNER_URL</code>.
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Input --}}
            <section class="space-y-4 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <div>
                    <label for="gb-text" class="text-sm font-medium text-zinc-200">Text</label>
                    <textarea id="gb-text" rows="6"
                              class="mt-2 w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm text-zinc-100 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">Welcome to verifiable media generation. Every word in this audio was produced on Replicate and quality-checked before it shipped. When a take is truncated or trails off, the orchestrator re-rolls it automatically, and every attempt is written to Backblaze B2 with a verifiable manifest.</textarea>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex flex-col gap-1 text-sm text-zinc-400">
                        <span class="font-medium text-zinc-200">Voice</span>
                        <select id="gb-voice" class="rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
                            @foreach($voices as $v)
                                <option value="{{ $v->slug }}" @selected($v->slug === $defaultVoiceSlug)>{{ $v->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button id="gb-run" @disabled(! $up)
                            class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-50">
                        ▶ Generate via Genblaze
                    </button>
                </div>
                <p id="gb-status" class="text-sm text-zinc-500"></p>
                <p class="text-xs text-zinc-600">Generation runs end-to-end on Replicate with a Whisper QA gate — a multi-chunk run can take a minute or two; each re-roll is a real call.</p>
            </section>

            {{-- Provenance --}}
            <section id="gb-result" class="hidden space-y-4 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-300">Provenance</h2>
                    <span id="gb-rerolls" class="inline-flex rounded-md border border-zinc-700 bg-zinc-800 px-2 py-0.5 text-xs text-zinc-300"></span>
                </div>

                <audio id="gb-final-audio" controls class="hidden w-full"></audio>
                <div class="space-y-1 text-xs text-zinc-500">
                    <div>final: <a id="gb-final-url" href="#" target="_blank" rel="noopener" class="break-all font-mono text-cyan-400 hover:underline"></a></div>
                    <div>manifest: <span id="gb-manifest" class="break-all font-mono text-zinc-400"></span><span id="gb-manifest-verified" class="ml-2 hidden rounded-md border px-1.5 py-0.5 text-xs"></span></div>
                </div>

                <div class="border-t border-zinc-800 pt-3">
                    <div class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">Chunks</div>
                    <ul id="gb-chunks" class="space-y-2"></ul>
                </div>
            </section>
        </div>
    </div>
</x-layout>
