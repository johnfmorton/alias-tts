<x-layout :title="$project->title" description="Edit a sentence and regenerate only that chunk, then rebuild — no full re-generation.">
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
         data-final-url="{{ route('admin.studio.projects.audio', $project) }}">

        {{-- Toolbar --}}
        <div class="mb-6 flex flex-col gap-4 rounded-xl border border-zinc-800 bg-zinc-900/50 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.studio.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">← Projects</a>
                <span class="text-sm text-zinc-500">{{ $project->voice?->name ?? 'no voice' }} · {{ $chunks->count() }} chunks</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="project-generate-all"
                        class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">▶ Generate all remaining</button>
                <button type="button" id="project-rebuild"
                        class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">⟳ Rebuild final</button>
                <a id="project-download" href="{{ route('admin.studio.projects.audio', $project) }}" download
                   class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800 {{ $hasFinal ? '' : 'hidden' }}">⤓ Download</a>
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

        {{-- Chunks --}}
        <ol class="space-y-3">
            @foreach($chunks as $chunk)
                <li class="studio-chunk rounded-xl border border-zinc-800 bg-zinc-900/50 p-4"
                    data-chunk-id="{{ $chunk->id }}"
                    data-generate-url="{{ route('admin.studio.projects.chunks.generate', [$project, $chunk]) }}"
                    data-patch-url="{{ route('admin.studio.projects.chunks.update', [$project, $chunk]) }}"
                    data-audio-url="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-sm text-zinc-400">
                            <span class="font-mono text-zinc-300">#{{ $chunk->position + 1 }}</span>
                            <span class="chunk-chars">{{ $chunk->characters }} chars</span>
                            <span class="inline-flex rounded-md border px-2 py-0.5 text-xs {{ $chunk->break_after === 'paragraph' ? 'border-amber-500/30 bg-amber-500/10 text-amber-300' : 'border-zinc-700 bg-zinc-800 text-zinc-400' }}">{{ $chunk->break_after }} seam</span>
                            <span class="chunk-status inline-flex rounded-md border px-2 py-0.5 text-xs {{ $chunkStyles[$chunk->status->value] ?? $chunkStyles['pending'] }}">{{ $chunk->status->value }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="chunk-save rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">Save text</button>
                            <button type="button" class="chunk-generate rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">▶ {{ $chunk->isCompleted() ? 'Regenerate' : 'Generate' }}</button>
                        </div>
                    </div>

                    <textarea class="chunk-text mt-2 w-full rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-2 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                              rows="2" data-original="{{ $chunk->text }}">{{ $chunk->text }}</textarea>

                    <audio class="chunk-audio mt-3 w-full {{ $chunk->isCompleted() ? '' : 'hidden' }}" controls
                           @if($chunk->isCompleted()) src="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}" @endif></audio>
                </li>
            @endforeach
        </ol>
    </div>
</x-layout>
