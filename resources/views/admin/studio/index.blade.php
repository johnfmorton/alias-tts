<x-layout title="Studio" description="Inspect how text is normalized and chunked, then hear it whole, chunk-by-chunk (raw), or stitched the way production does.">
    {{-- Editable projects --}}
    <div class="mb-8 rounded-xl border border-zinc-800 bg-zinc-900/50">
        <div class="flex items-center justify-between border-b border-zinc-800 px-5 py-4">
            <div>
                <h2 class="font-semibold">Projects</h2>
                <p class="mt-1 text-sm text-zinc-400">Saved, editable audio — regenerate a single sentence without rebuilding the whole file.</p>
            </div>
            <a href="{{ route('admin.studio.projects.create') }}"
               class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">New project</a>
        </div>
        @if($projects->isEmpty())
            <p class="px-5 py-5 text-sm text-zinc-500">No projects yet.</p>
        @else
            <ul class="divide-y divide-zinc-800">
                @php
                    $projectStyles = [
                        'ready' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
                        'stale' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
                        'draft' => 'border-zinc-700 bg-zinc-800 text-zinc-400',
                    ];
                @endphp
                @foreach($projects as $project)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <a href="{{ route('admin.studio.projects.show', $project) }}" class="min-w-0 flex-1">
                            <div class="truncate font-medium hover:text-cyan-300">{{ $project->title }}</div>
                            <div class="text-xs text-zinc-500">{{ $project->chunks_count }} chunk(s) · updated {{ $project->updated_at->diffForHumans() }}</div>
                        </a>
                        <span class="inline-flex shrink-0 rounded-md border px-2 py-0.5 text-xs {{ $projectStyles[$project->status->value] ?? $projectStyles['draft'] }}">{{ $project->status->value }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <h2 class="mb-3 font-semibold">Inspector <span class="text-sm font-normal text-zinc-500">— quick, stateless: paste, see chunking, hear it</span></h2>
    <div id="studio"
         data-preview-url="{{ route('admin.studio.preview') }}"
         data-synthesize-url="{{ route('admin.studio.synthesize') }}"
         data-stitch-url="{{ route('admin.studio.stitch') }}"
         data-concat-url="{{ route('admin.studio.concat') }}"
         data-advanced-url="{{ route('admin.studio.advanced') }}">

        {{-- Input --}}
        <div class="mb-6 rounded-xl border border-zinc-800 bg-zinc-900/50 p-5">
            <label for="studio-text" class="mb-1.5 block text-sm font-medium">Text</label>
            <textarea id="studio-text" rows="8"
                      class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                      placeholder="Paste a block of text…"></textarea>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="studio-voice" class="mb-1.5 block text-sm text-zinc-400">Voice</label>
                    @if($voices->isEmpty())
                        <p class="text-sm text-zinc-500">No voices — <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.create') }}">add one</a> to generate audio.</p>
                    @else
                        <select id="studio-voice" class="rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm">
                            @foreach($voices as $v)
                                <option value="{{ $v->slug }}">{{ $v->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                <button type="button" id="studio-preview"
                        class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">
                    Preview chunks
                </button>
            </div>

            {{-- Advanced tuning: a per-user toggle (off by default) reveals the
                 Chatterbox knobs and the A/B tuning bench. --}}
            <label class="mt-4 inline-flex cursor-pointer items-center gap-2 text-sm text-zinc-300">
                <input id="studio-advanced-toggle" type="checkbox" @checked(auth()->user()->studio_advanced)
                       class="rounded border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
                Advanced tuning <span class="text-xs text-zinc-500">— knobs &amp; A/B bench</span>
            </label>

            <div id="studio-advanced" @unless(auth()->user()->studio_advanced) class="hidden" @endunless>
                {{-- Single-shot knobs (used by Whole / Stitched / per-chunk Generate) --}}
                <div class="mt-3 flex flex-wrap gap-4 rounded-lg border border-zinc-800 bg-zinc-950/40 p-3 text-sm text-zinc-400">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-zinc-500">Seed (blank = random)</span>
                        <input id="studio-seed" type="number" inputmode="numeric"
                               class="w-36 rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-1.5 text-sm">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-zinc-500">Stability (0–1)</span>
                        <input id="studio-stability" type="number" step="0.05" min="0" max="1" placeholder="0.5"
                               class="w-28 rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-1.5 text-sm">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-zinc-500">Style (0–1)</span>
                        <input id="studio-style" type="number" step="0.05" min="0" max="1" placeholder="0.0"
                               class="w-28 rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-1.5 text-sm">
                    </label>
                    {{-- Live Chatterbox mapping for the current knobs (3a). --}}
                    <span id="studio-mapping" class="self-end pb-1.5 font-mono text-xs text-zinc-500"></span>
                </div>

                {{-- A/B tuning bench: hear the text above at several settings, pick the
                     best, save it as the selected voice's defaults. --}}
                <div id="studio-bench" class="mt-3 rounded-xl border border-zinc-800 bg-zinc-900/50 p-4"
                     data-voice-defaults-url="{{ route('admin.studio.voice-defaults') }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-zinc-200">Tuning bench</h3>
                            <p class="mt-0.5 text-xs text-zinc-500">Hear the text above at different settings, then save the best to the voice. Higher stability = steadier pacing; higher style = more animated.</p>
                        </div>
                        <button type="button" id="studio-bench-add"
                                class="rounded-lg border border-zinc-700 px-3 py-1.5 text-xs hover:bg-zinc-800">+ Add setting</button>
                    </div>

                    <ol id="studio-bench-rows" class="mt-3 space-y-2"></ol>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" id="studio-bench-generate" @disabled($voices->isEmpty())
                                class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20 disabled:opacity-40">▶ Generate all</button>
                        <button type="button" id="studio-bench-save" @disabled($voices->isEmpty())
                                class="rounded-lg border border-emerald-700/50 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300 hover:bg-emerald-500/20 disabled:opacity-40">Save pick to voice defaults</button>
                    </div>
                    <div id="studio-bench-status" class="mt-2 text-sm text-zinc-400" role="status" aria-live="polite"></div>
                </div>
            </div>

            <div id="studio-status" class="mt-3 text-sm text-zinc-400" role="status" aria-live="polite"></div>
        </div>

        {{-- Results (revealed after a preview) --}}
        <div id="studio-results" class="hidden space-y-6">

            {{-- Normalized text + whole/stitched actions --}}
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/50">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-800 px-5 py-4">
                    <div>
                        <h2 class="font-semibold">Normalized text</h2>
                        <p class="mt-1 text-sm text-zinc-400">
                            Cleaned the way the Bespoken plugin cleans a post (<span id="studio-norm-chars">0</span> chars),
                            then split into <span id="studio-chunk-count">0</span> chunk(s) below.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" id="studio-whole" @disabled($voices->isEmpty())
                                class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800 disabled:opacity-40">
                            ▶ Whole (single call)
                        </button>
                        <button type="button" id="studio-stitch" @disabled($voices->isEmpty())
                                class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20 disabled:opacity-40">
                            ▶ Stitched (production)
                        </button>
                    </div>
                </div>
                <div class="space-y-3 p-5">
                    <p id="studio-normalized" class="whitespace-pre-wrap break-words rounded-lg bg-zinc-950 p-3 text-sm text-zinc-300"></p>
                    <audio id="studio-whole-audio" controls class="hidden w-full"></audio>
                </div>
            </div>

            {{-- Per-chunk cards --}}
            <div>
                <h2 class="mb-3 font-semibold">Chunks <span class="text-sm font-normal text-zinc-500">— each plays the raw Chatterbox output</span></h2>

                {{-- Concatenation preview: stitch the chunks you've generated through
                     the real production trim + seam join, to catch dropped words. --}}
                <div id="studio-concat-bar" class="mb-4 hidden space-y-3 rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" id="studio-concat"
                                class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">
                            ▶ Concatenate selected
                        </button>
                        <span class="text-sm text-zinc-400">
                            Tick generated chunks, then stitch them through production's trim + seam join.
                            One chunk alone tests the trim; two adjacent test the seam.
                        </span>
                    </div>
                    <div id="studio-concat-status" class="text-sm text-zinc-400" role="status" aria-live="polite"></div>
                    <audio id="studio-concat-audio" controls class="hidden w-full"></audio>
                </div>

                <ol id="studio-chunks" class="space-y-3"></ol>
            </div>
        </div>
    </div>
</x-layout>
