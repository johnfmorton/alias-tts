<x-layout title="Add a voice" description="Upload a clean ~15–30s reference clip. It's normalized and registered instantly (zero-shot — no training job).">
    <form method="POST" action="{{ route('admin.voices.store') }}" enctype="multipart/form-data" class="max-w-lg space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
        @csrf
        <div>
            <label for="name" class="mb-1.5 block text-sm font-medium">Name</label>
            <input id="name" name="name" value="{{ old('name') }}" required placeholder="e.g. John"
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
        </div>
        <div>
            <label for="slug" class="mb-1.5 block text-sm font-medium">voice_id <span class="text-zinc-500">(optional)</span></label>
            <input id="slug" name="slug" value="{{ old('slug') }}" placeholder="defaults to a slug of the name"
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            <p class="mt-1.5 text-xs text-zinc-500">Tip: set this to your existing ElevenLabs voice_id for a drop-in swap.</p>
        </div>
        <div>
            <label for="audio" class="mb-1.5 block text-sm font-medium">Reference clip <span class="text-zinc-500">(optional)</span></label>
            <input id="audio" name="audio" type="file" accept=".wav,.mp3,.m4a,.aac,.ogg,.flac"
                   class="block w-full text-sm text-zinc-400 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:px-3 file:py-2 file:text-sm file:text-zinc-200 hover:file:bg-zinc-700">
            <p class="mt-1.5 text-xs text-zinc-500">WAV/MP3/M4A/OGG/FLAC, up to 20 MB. A clean, quiet ~15–30s sample works best. Leave blank to use Chatterbox's built-in voice (no cloning).</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-zinc-400">
            <input type="checkbox" name="raw" value="1" {{ old('raw') ? 'checked' : '' }} class="rounded border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
            Store raw (skip auto-normalization)
        </label>
        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Save voice</button>
            <a href="{{ route('admin.voices.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
        </div>
    </form>
</x-layout>
