<x-layout title="Edit voice" description="Rename the voice_id, set default tuning or seed, or replace the reference clip.">
    <form method="POST" action="{{ route('admin.voices.update', $voice) }}" enctype="multipart/form-data" class="max-w-lg space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
        @csrf
        @method('PUT')
        <div>
            <label for="name" class="mb-1.5 block text-sm font-medium">Name</label>
            <input id="name" name="name" value="{{ old('name', $voice->name) }}" required
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
        </div>
        <div>
            <label for="slug" class="mb-1.5 block text-sm font-medium">voice_id</label>
            <input id="slug" name="slug" value="{{ old('slug', $voice->slug) }}" required
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            <p class="mt-1.5 text-xs text-zinc-500">Used in the API path and by clients (e.g. the Bespoken plugin). Renaming it also moves the stored reference clip.</p>
        </div>
        <div>
            <label for="seed" class="mb-1.5 block text-sm font-medium">Default seed <span class="text-zinc-500">(optional)</span></label>
            <input id="seed" name="seed" type="number" value="{{ old('seed', $voice->settings['seed'] ?? '') }}" placeholder="random"
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            <p class="mt-1.5 text-xs text-zinc-500">Leave blank for a random seed each call.</p>
        </div>
        <div>
            <span class="mb-1.5 block text-sm font-medium">Default tuning <span class="text-zinc-500">(optional)</span></span>
            <div class="flex gap-3">
                <label class="flex-1">
                    <span class="mb-1 block text-xs text-zinc-500">Stability (0–1)</span>
                    <input id="stability" name="stability" type="number" step="0.05" min="0" max="1"
                           value="{{ old('stability', $voice->settings['stability'] ?? '') }}" placeholder="0.5"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
                </label>
                <label class="flex-1">
                    <span class="mb-1 block text-xs text-zinc-500">Style (0–1)</span>
                    <input id="style" name="style" type="number" step="0.05" min="0" max="1"
                           value="{{ old('style', $voice->settings['style'] ?? '') }}" placeholder="0.0"
                           class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
                </label>
            </div>
            <p class="mt-1.5 text-xs text-zinc-500">Used when a request doesn't set its own. Higher stability = steadier pacing; higher style = more animated. Blank uses the system defaults — tune by ear in <a class="text-cyan-400 hover:underline" href="{{ route('admin.studio.index') }}">Studio</a>.</p>
        </div>
        <div>
            <label for="audio" class="mb-1.5 block text-sm font-medium">Replace reference clip <span class="text-zinc-500">(optional)</span></label>
            <input id="audio" name="audio" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.flac"
                   class="block w-full text-sm text-zinc-400 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:px-3 file:py-2 file:text-sm file:text-zinc-200 hover:file:bg-zinc-700">
            <p class="mt-1.5 text-xs text-zinc-500">Leave empty to keep the current clip ({{ $voice->reference_audio_path ? 'present' : 'none' }}).</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-zinc-400">
            <input type="checkbox" name="raw" value="1" {{ old('raw') ? 'checked' : '' }} class="rounded border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
            Store raw (skip auto-normalization) — only applies when replacing the clip
        </label>
        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Save changes</button>
            <a href="{{ route('admin.voices.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
        </div>
    </form>
</x-layout>
