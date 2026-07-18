<x-layout title="New project" description="Paste text, pick a voice, and we'll normalize and split it into editable chunks.">
    @if($voices->isEmpty())
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-5 text-sm text-amber-300">
            No voices configured — <a class="underline" href="{{ route('admin.voices.create') }}">add a voice</a> before creating a project.
        </div>
    @else
        <form id="create-project-form" method="POST" action="{{ route('admin.studio.projects.review') }}"
              data-detect-url="{{ route('admin.studio.projects.detect') }}"
              data-store-url="{{ route('admin.studio.projects.store') }}"
              class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
            @csrf
            <div>
                <label for="title" class="mb-1.5 block text-sm font-medium">Title</label>
                <input id="title" name="title" value="{{ old('title') }}" placeholder="Untitled project"
                       class="w-full max-w-md rounded-lg border border-edge bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            </div>

            <div>
                <label for="text" class="mb-1.5 block text-sm font-medium">Text</label>
                <textarea id="text" name="text" rows="10" required
                          class="w-full rounded-lg border border-edge bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                          placeholder="Paste the text to turn into editable audio…">{{ old('text') }}</textarea>
            </div>

            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label for="voice" class="mb-1.5 block text-sm text-zinc-400">Voice</label>
                    <select id="voice" name="voice" class="rounded-lg border border-edge bg-zinc-950 px-3 py-2 text-sm">
                        @foreach($voices as $v)
                            <option data-model="{{ \App\Services\Tts\ModelCatalog::forVoice($v) }}" value="{{ $v->slug }}" @selected(old('voice', $defaultVoiceSlug) === $v->slug)>{{ $v->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if($presets->isNotEmpty())
                    @php $createVoiceModel = \App\Services\Tts\ModelCatalog::forVoice($voices->firstWhere('slug', old('voice', $defaultVoiceSlug)) ?? $voices->first()); @endphp
                    <div>
                        <label for="preset" class="mb-1.5 block text-sm text-zinc-400">Delivery <span class="text-zinc-600">(optional)</span></label>
                        {{-- Presets belong to an engine; only the chosen voice's engine's
                             presets are offered (initCreateProjectPresets re-filters on
                             voice change and resets a now-foreign pick). --}}
                        <select id="preset" name="preset" class="rounded-lg border border-edge bg-zinc-950 px-3 py-2 text-sm"
                                title="A preset saved from a voice's tuning bench — seeds this project's tuning; the voice's own defaults apply when left as-is">
                            <option value="" selected>Voice default</option>
                            @foreach($presets as $preset)
                                <option value="{{ $preset->id }}" data-model="{{ $preset->engineModel() }}"
                                        @class(['hidden' => $preset->engineModel() !== $createVoiceModel])>{{ $preset->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <a href="{{ route('admin.voices.index') }}" class="py-2 text-sm text-cyan-400 hover:text-cyan-300">Manage voices →</a>
            </div>

            <div class="space-y-2">
                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Create project</button>
                    {{-- Idle: a plain "never mind" back to Studio. Once the
                         pronunciation check is running, JS hides this and shows
                         "Skip" in its place (below). --}}
                    <a id="create-project-cancel" href="{{ route('admin.studio.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
                    {{-- Shown only while the check runs: abort the LLM gate and
                         create straight from the chunks (applies the existing
                         dictionary, no review step). --}}
                    <button type="button" id="skip-pronunciation" hidden
                            title="Skip the pronunciation check and create the project now"
                            class="text-sm text-zinc-400 hover:text-zinc-200">Skip</button>
                </div>
                <p id="create-project-status" class="text-sm text-zinc-400" role="status" aria-live="polite"></p>
            </div>
        </form>
    @endif
</x-layout>
