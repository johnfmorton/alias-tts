<x-layout title="Start over" description="Edit the original text and re-split it into fresh chunks. This discards all audio generated so far.">
    <form method="POST" action="{{ route('admin.studio.projects.reset', $project) }}"
          data-busy data-busy-label="Resetting…"
          data-confirm="{{ ($foreignOwner ? "This is {$foreignOwner}'s project, not yours. " : '') }}This deletes all generated audio and re-splits the text into fresh chunks."
          data-confirm-title="Start over?"
          data-confirm-label="Reset &amp; re-chunk"
          class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
        @csrf

        @if($foreignOwner)
            <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                ⚠ <strong>This is {{ $foreignOwner }}'s project.</strong> You can open it because you're a SuperAdmin — resetting it destroys <em>their</em> generated audio, not a copy.
            </div>
        @endif

        <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300">
            Resetting re-chunks the text below and <strong>permanently deletes every generated chunk and the final audio</strong>. The voice and settings stay the same.
        </div>

        <div>
            <label for="text" class="mb-1.5 block text-sm font-medium">Text</label>
            <textarea id="text" name="text" rows="14" required
                      class="w-full rounded-lg border border-edge bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                      placeholder="The text to re-split into editable audio…">{{ old('text', $project->source_text) }}</textarea>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm font-medium text-red-300 hover:bg-red-500/20">↺ Reset &amp; re-chunk</button>
            <a href="{{ route('admin.studio.projects.show', $project) }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
        </div>
    </form>
</x-layout>
