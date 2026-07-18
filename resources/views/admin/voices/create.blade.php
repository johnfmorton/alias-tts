@php
    $inputClass = 'w-full rounded-[9px] border border-edge bg-inset px-3.5 py-3 text-[15px] text-zinc-100 placeholder:text-zinc-600 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
@endphp
<x-layout title="Add a voice" :heading="false" contentWidth="max-w-[1060px]">
    <form id="voice-form" method="POST" action="{{ route('admin.voices.store') }}" enctype="multipart/form-data"
          data-busy data-busy-label="Saving voice…"
          data-busy-message="Cleaning up and normalizing the reference clip can take up to a minute — keep this page open.">
        @csrf

        {{-- Header: pinned like the Studio command bar so Save/Cancel stay reachable
             no matter how far the form is scrolled. --}}
        <div class="sticky top-0 z-30 -mx-4 mb-8 border-b border-white/[0.09] bg-sticky px-4 py-4 shadow-[0_12px_26px_-14px_rgba(0,0,0,0.85)]">
            <div class="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <h1 class="text-[27px] font-bold tracking-[-0.015em] text-zinc-100">Add a voice</h1>
                    <p class="mt-1.5 text-sm text-zinc-500">Record or upload a clean ~15–20s reference clip. It's normalized and registered instantly (zero-shot — no training job).</p>
                </div>
                <div class="flex flex-shrink-0 items-center gap-2">
                    <a href="{{ route('admin.voices.index') }}" class="px-3.5 py-2.5 text-sm text-zinc-400 transition hover:text-zinc-200">Cancel</a>
                    <button type="submit" class="rounded-[9px] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-on transition hover:brightness-110">Save voice</button>
                </div>
            </div>
        </div>

        <x-voice.section label="Identity">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label for="name" class="mb-2 block text-[13px] font-semibold text-zinc-300">Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" required placeholder="e.g. John" class="{{ $inputClass }}">
                </div>
                <div>
                    <label for="slug" class="mb-2 block text-[13px] font-semibold text-zinc-300">voice_id <span class="font-normal text-zinc-500">(optional)</span></label>
                    <input id="slug" name="slug" value="{{ old('slug') }}" placeholder="defaults to a slug of the name" class="{{ $inputClass }} font-mono">
                </div>
            </div>
            <p class="mt-3 text-[12.5px] leading-relaxed text-zinc-500">Tip: set the voice_id to your existing ElevenLabs voice_id for a drop-in swap.</p>
        </x-voice.section>

        @include('admin.voices._engine', ['voice' => null])

        @include('admin.voices._clip_source', [
            'replace' => false,
            'hint' => 'optional, but recommended',
            'fileHelp' => "WAV/MP3/M4A/OGG/FLAC, up to 20 MB. A clean, quiet ~15–20s sample works best. Leave blank to use Chatterbox's built-in voice (no cloning).",
        ])
    </form>
</x-layout>
