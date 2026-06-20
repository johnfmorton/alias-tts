<x-layout title="New project" description="Paste text, pick a voice, and we'll normalize and split it into editable chunks.">
    @if($voices->isEmpty())
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-5 text-sm text-amber-300">
            No voices configured — <a class="underline" href="{{ route('admin.voices.create') }}">add a voice</a> before creating a project.
        </div>
    @else
        <form method="POST" action="{{ route('admin.studio.projects.store') }}" class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
            @csrf
            <div>
                <label for="title" class="mb-1.5 block text-sm font-medium">Title</label>
                <input id="title" name="title" value="{{ old('title') }}" placeholder="Untitled project"
                       class="w-full max-w-md rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            </div>

            <div>
                <label for="text" class="mb-1.5 block text-sm font-medium">Text</label>
                <textarea id="text" name="text" rows="10" required
                          class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                          placeholder="Paste the text to turn into editable audio…">{{ old('text') }}</textarea>
            </div>

            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label for="voice" class="mb-1.5 block text-sm text-zinc-400">Voice</label>
                    <select id="voice" name="voice" class="rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm">
                        @foreach($voices as $v)
                            <option value="{{ $v->slug }}" @selected(old('voice', $defaultVoiceSlug) === $v->slug)>{{ $v->name }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-500">Seed (optional)</span>
                    <input name="seed" type="number" inputmode="numeric" value="{{ old('seed') }}"
                           class="w-36 rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-1.5 text-sm">
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Create project</button>
                <a href="{{ route('admin.studio.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
            </div>
        </form>
    @endif
</x-layout>
