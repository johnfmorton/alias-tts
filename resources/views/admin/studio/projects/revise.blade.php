<x-layout title="Revise text" description="Paste the updated text — only the chunks that changed are re-rendered; everything else keeps its audio.">
    <div id="revise-root" data-preview-url="{{ route('admin.studio.projects.revise.preview', $project) }}">
        <form method="POST" action="{{ route('admin.studio.projects.revise.apply', $project) }}"
              data-busy data-busy-label="Applying…"
              data-confirm="{{ ($foreignOwner ? "This is {$foreignOwner}'s project, not yours. " : '') }}Chunks whose text changed start re-rendering in the background; chunks that disappeared from the text are deleted permanently, audio included. Everything else keeps its audio."
              data-confirm-title="Apply this revision?"
              data-confirm-label="Apply revision"
              class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
            @csrf

            @if($foreignOwner)
                <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    ⚠ <strong>This is {{ $foreignOwner }}'s project.</strong> You can open it because you're a SuperAdmin — the revision is planned with <em>their</em> pronunciation dictionary and text settings, and it changes <em>their</em> chunks.
                </div>
            @endif

            <div class="rounded-lg border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-sm text-zinc-300">
                This is the project's current <strong>spoken</strong> text — pronunciations already applied — rebuilt from the chunks in order.
                Edit or paste over it, then <strong>Preview changes</strong> to see exactly which chunks a revision touches before applying it.
                Applying starts re-rendering the changed chunks in the background; everything whose text is unchanged keeps its audio, takes, and
                tuning. Pasting the text unchanged is useful too: it re-applies your
                <a href="{{ route('admin.pronunciations.index') }}" class="text-cyan-300 hover:underline">pronunciation dictionary</a> and text settings, flagging only the chunks they now affect.
            </div>

            <div>
                <label for="revise-text" class="mb-1.5 block text-sm font-medium">Text</label>
                <textarea id="revise-text" name="text" rows="14" required
                          class="w-full rounded-lg border border-edge bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                          placeholder="The revised text…">{{ old('text', $canonicalText) }}</textarea>
            </div>

            {{-- Filled by JS after Preview: summary counts, then only the changed rows. --}}
            <div id="revise-results" class="hidden space-y-3"></div>

            <div class="flex items-center gap-3">
                <button type="button" id="revise-preview"
                        class="rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-4 py-2 text-sm font-medium text-cyan-300 hover:bg-cyan-500/20">👁 Preview changes</button>
                <button type="submit" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-accent-on hover:bg-accent/90">✓ Apply revision</button>
                <a href="{{ route('admin.studio.projects.show', $project) }}" data-busy data-busy-label="Opening project…" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
                <span id="revise-status" class="text-sm" role="status"></span>
            </div>
        </form>
    </div>
</x-layout>
