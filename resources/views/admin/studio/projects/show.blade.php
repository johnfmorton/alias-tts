<x-layout :title="$project->title" description="Edit a sentence and regenerate only that chunk, then rebuild — no full re-generation.">
    {{-- Inline rename, rendered next to the page title via the layout's titleActions slot. --}}
    <x-slot:titleActions>
        <button type="button" id="project-rename"
                class="rounded-lg border border-zinc-700 px-2.5 py-1 text-xs text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">Rename</button>
        <div id="project-rename-form" class="hidden w-full max-w-xl items-center gap-2">
            <input type="text" id="project-title-input" value="{{ $project->title }}" maxlength="200"
                   class="min-w-0 flex-1 rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-1.5 text-base text-zinc-100 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            <button type="button" id="project-rename-save"
                    class="shrink-0 rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-1.5 text-sm text-cyan-300 hover:bg-cyan-500/20">Save</button>
            <button type="button" id="project-rename-cancel"
                    class="shrink-0 rounded-lg border border-zinc-700 px-3 py-1.5 text-sm text-zinc-400 hover:bg-zinc-800">Cancel</button>
        </div>
    </x-slot:titleActions>

    @php
        $chunkStyles = [
            'completed' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
            'stale'     => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
            'failed'    => 'border-red-500/30 bg-red-500/10 text-red-300',
            'pending'   => 'border-zinc-700 bg-zinc-800 text-zinc-400',
        ];
        $hasFinal = (bool) $project->final_audio_path;
    @endphp

    <div id="studio-project"
         data-rebuild-url="{{ route('admin.studio.projects.rebuild', $project) }}"
         data-final-url="{{ route('admin.studio.projects.audio', $project) }}"
         data-preview-url="{{ route('admin.studio.projects.preview', $project) }}"
         data-rename-url="{{ route('admin.studio.projects.update', $project) }}"
         data-voice-url="{{ route('admin.studio.projects.voice', $project) }}"
         data-insert-url="{{ route('admin.studio.projects.chunks.store', $project) }}"
         data-generate-pace-ms="{{ (int) config('tts.studio_generate_pace_ms', 800) }}">

        {{-- Toolbar --}}
        <div class="mb-6 flex flex-col gap-4 rounded-xl border border-zinc-800 bg-zinc-900/50 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.studio.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">← Projects</a>
                <label class="flex items-center gap-2 text-sm text-zinc-500">
                    <span class="text-zinc-400">Voice</span>
                    <select id="project-voice" title="Changing the voice marks generated chunks for regeneration."
                            class="rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
                        @foreach($voices as $v)
                            <option value="{{ $v->slug }}" @selected($project->voice && $project->voice->id === $v->id)>{{ $v->name }}</option>
                        @endforeach
                    </select>
                </label>
                <span class="text-sm text-zinc-500">· {{ $chunks->count() }} chunks</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="project-generate-all"
                        class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">▶ Generate all remaining</button>
                <button type="button" id="project-rebuild"
                        class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">⟳ Rebuild final</button>
                <a id="project-download" href="{{ route('admin.studio.projects.audio', $project) }}" download
                   class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800 {{ $hasFinal ? '' : 'hidden' }}">⤓ Download</a>
                <a href="{{ route('admin.studio.projects.edit', $project) }}"
                   class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">↺ Start over</a>
                <form method="POST" action="{{ route('admin.studio.projects.destroy', $project) }}"
                      onsubmit="return confirm('Delete this project and all its audio?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-lg border border-red-500/30 px-3 py-2 text-sm text-red-400 hover:bg-red-500/10">Delete</button>
                </form>
            </div>
        </div>

        {{-- Final audio --}}
        <div class="mb-6 rounded-xl border border-zinc-800 bg-zinc-900/50 p-5">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Final audio</h2>
                <span id="project-status" class="inline-flex rounded-md border px-2 py-0.5 text-xs {{ $project->status->value === 'ready' ? $chunkStyles['completed'] : ($project->status->value === 'stale' ? $chunkStyles['stale'] : $chunkStyles['pending']) }}">{{ $project->status->value }}</span>
            </div>
            <div id="project-final-status" class="mt-2 text-sm text-zinc-400" role="status" aria-live="polite"></div>
            <audio id="project-final-audio" controls class="mt-3 w-full {{ $hasFinal ? '' : 'hidden' }}" @if($hasFinal) src="{{ route('admin.studio.projects.audio', $project) }}" @endif></audio>
        </div>

        {{-- Chunks, with an inline "Preview stitch" connector between any two adjacent GENERATED chunks --}}
        <div class="space-y-3">
            {{-- Insert a new (empty) chunk at this gap. Always available, unlike the seam. --}}
            <div class="chunk-insert flex justify-center" data-position="0">
                <button type="button" class="rounded-full border border-zinc-800 px-3 py-0.5 text-xs text-zinc-600 hover:border-zinc-600 hover:text-zinc-300">+ insert chunk</button>
            </div>

            @foreach($chunks as $chunk)
                <div class="studio-chunk rounded-xl border border-zinc-800 bg-zinc-900/50 p-4"
                     data-chunk-id="{{ $chunk->id }}"
                     data-generate-url="{{ route('admin.studio.projects.chunks.generate', [$project, $chunk]) }}"
                     data-patch-url="{{ route('admin.studio.projects.chunks.update', [$project, $chunk]) }}"
                     data-tuning-url="{{ route('admin.studio.projects.chunks.tuning', [$project, $chunk]) }}"
                     data-reroll-url="{{ route('admin.studio.projects.chunks.reroll', [$project, $chunk]) }}"
                     data-preview-tuning-url="{{ route('admin.studio.projects.chunks.preview-tuning', [$project, $chunk]) }}"
                     data-audio-url="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-sm text-zinc-400">
                            <span class="font-mono text-zinc-300">#{{ $chunk->position + 1 }}</span>
                            <span class="chunk-chars">{{ $chunk->characters }} chars</span>
                            <span class="inline-flex rounded-md border px-2 py-0.5 text-xs {{ $chunk->break_after === 'paragraph' ? 'border-amber-500/30 bg-amber-500/10 text-amber-300' : 'border-zinc-700 bg-zinc-800 text-zinc-400' }}">{{ $chunk->break_after }} seam</span>
                            <span class="chunk-status inline-flex rounded-md border px-2 py-0.5 text-xs {{ $chunkStyles[$chunk->status->value] ?? $chunkStyles['pending'] }}">{{ $chunk->status->value }}</span>
                            @php $asrBadge = $chunk->asrBadge(); @endphp
                            {{-- ASR transcript-QA verdict; only when the chunk's current audio was scored. --}}
                            <span class="chunk-asr-badge {{ $asrBadge ? 'inline-flex rounded-md border px-2 py-0.5 text-xs '.($asrBadge['tone'] === 'ok' ? $chunkStyles['completed'] : $chunkStyles['failed']) : 'hidden' }}"
                                  @if($asrBadge) title="{{ $asrBadge['title'] }}" @endif>{{ $asrBadge['text'] ?? '' }}</span>
                            <span class="chunk-dirty hidden rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">● unsaved</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-1.5 text-xs text-zinc-500">
                                <span class="text-zinc-400">Voice</span>
                                <select class="chunk-voice rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                                        data-voice-url="{{ route('admin.studio.projects.chunks.voice', [$project, $chunk]) }}"
                                        data-inherits="{{ $chunk->voice_id ? '0' : '1' }}"
                                        title="Voice for this chunk. Follows the project voice until you pick one here.">
                                    @foreach($voices as $v)
                                        <option value="{{ $v->slug }}" @selected(($chunk->voice_id ?? $project->voice_id) === $v->id)>{{ $v->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="button" class="chunk-revert hidden rounded-lg border border-zinc-700 px-3 py-1.5 text-sm text-zinc-400 hover:bg-zinc-800">Revert</button>
                            <button type="button" class="chunk-save rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">Save text</button>
                            <button type="button" class="chunk-generate rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">▶ {{ $chunk->isCompleted() ? 'Regenerate' : 'Generate' }}</button>
                        </div>
                    </div>

                    <textarea class="chunk-text mt-2 w-full rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-2 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                              rows="2" data-original="{{ $chunk->text }}">{{ $chunk->text }}</textarea>

                    <audio class="chunk-audio mt-3 w-full {{ $chunk->isCompleted() ? '' : 'hidden' }}" controls
                           @if($chunk->isCompleted()) src="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}" @endif></audio>

                    {{-- Per-chunk tuning override + re-roll (a fresh random take). --}}
                    <details class="chunk-tune mt-3 text-sm text-zinc-400" @if(!empty($chunk->settings['stability']) || !empty($chunk->settings['style'])) open @endif>
                        <summary class="cursor-pointer select-none text-xs hover:text-zinc-200">Tune this chunk</summary>
                        <div class="mt-2 flex flex-wrap items-end gap-3">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs text-zinc-500">Stability (0–1)</span>
                                <input class="chunk-stability w-24 rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1 text-sm" type="number" step="0.05" min="0" max="1"
                                       value="{{ $chunk->settings['stability'] ?? '' }}" placeholder="inherit">
                            </label>
                            <label class="flex flex-col gap-1">
                                <span class="text-xs text-zinc-500">Style (0–1)</span>
                                <input class="chunk-style w-24 rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1 text-sm" type="number" step="0.05" min="0" max="1"
                                       value="{{ $chunk->settings['style'] ?? '' }}" placeholder="inherit">
                            </label>
                            <button type="button" class="chunk-tune-preview rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">▶ Preview</button>
                            <button type="button" class="chunk-tune-save rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">Save tuning</button>
                            <button type="button" class="chunk-reroll rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">⟳ Re-roll</button>
                        </div>
                        <audio class="chunk-tune-audio mt-2 hidden w-full" controls></audio>
                        <p class="mt-1.5 text-xs text-zinc-500">Blank inherits the project's setting. <span class="text-zinc-400">Preview</span> auditions the typed settings without saving (compare it to the chunk audio above). Saving marks the chunk stale — regenerate to apply. Re-roll regenerates with a new random seed for a different take.</p>
                    </details>
                </div>

                @unless($loop->last)
                    @php $next = $chunks->get($loop->index + 1); @endphp
                    <div class="chunk-seam flex flex-col items-center {{ $chunk->isCompleted() && $next->isCompleted() ? '' : 'hidden' }}"
                         data-prev="{{ $chunk->id }}" data-next="{{ $next->id }}">
                        <span class="h-3 w-px bg-zinc-700"></span>
                        <button type="button" class="seam-preview rounded-full border border-zinc-700 bg-zinc-800 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-300 hover:bg-zinc-700">▶ Preview stitch</button>
                        <span class="h-3 w-px bg-zinc-700"></span>
                        <div class="seam-player mt-1 hidden w-full max-w-2xl">
                            <audio class="seam-audio w-full" controls></audio>
                            <div class="seam-status mt-1 text-center text-xs text-zinc-400" role="status" aria-live="polite"></div>
                        </div>
                    </div>
                @endunless

                <div class="chunk-insert flex justify-center" data-position="{{ $loop->iteration }}">
                    <button type="button" class="rounded-full border border-zinc-800 px-3 py-0.5 text-xs text-zinc-600 hover:border-zinc-600 hover:text-zinc-300">+ insert chunk</button>
                </div>
            @endforeach
        </div>
    </div>
</x-layout>
