@php
    $inputClass = 'w-full rounded-[9px] border border-edge bg-inset px-3.5 py-3 text-[15px] text-zinc-100 placeholder:text-zinc-600 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
@endphp
<x-layout title="Add a voice" :heading="false" contentWidth="max-w-[1100px]">
    {{-- Add a voice is Edit minus history: the same rail, two live steps, and
         Delivery defaults visibly locked. You tune by ear, and there's nothing
         to hear until the voice exists — so Create lands on the edit page with
         step 3 waiting. --}}
    <form id="voice-form" method="POST" action="{{ route('admin.voices.store') }}" enctype="multipart/form-data"
          data-voice-flow="create" data-dirty-guard
          data-busy data-busy-label="Saving voice…"
          data-busy-message="Cleaning up and normalizing the reference clip can take up to a minute — keep this page open.">
        @csrf

        <div class="mb-7">
            <h1 class="text-[26px] font-bold tracking-[-0.015em] text-zinc-100">Add a voice</h1>
            <p class="mt-1.5 text-sm text-zinc-500">Name it, give it a source, and it's ready. Tuning comes after — with a voice to hear.</p>
        </div>

        <div class="grid grid-cols-1 items-start gap-9 pb-28 lg:grid-cols-[230px_minmax(0,1fr)]">
            <x-voice.rail
                :steps="[
                    ['key' => 'identity', 'number' => 1, 'name' => 'Identity', 'meta' => old('slug') ?: 'name it', 'state' => 'current'],
                    ['key' => 'source', 'number' => 2, 'name' => 'Voice source', 'meta' => 'engine + clip', 'state' => 'todo'],
                    ['key' => 'delivery', 'number' => 3, 'name' => 'Delivery defaults', 'meta' => 'after the voice exists', 'state' => 'locked'],
                ]"
                note="The same rail runs <strong class='text-zinc-400'>Edit voice</strong> — tuning unlocks there, once there's a voice to hear." />

            <div class="flex flex-col gap-[22px]">
                {{-- ── 1 · Identity ─────────────────────────────────────────── --}}
                <x-voice.step step="identity" number="1" title="Identity" hint="what clients ask for" state="current">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="mb-2 block text-[13px] font-semibold text-zinc-300">Name</label>
                            <input id="name" name="name" value="{{ old('name') }}" required placeholder="e.g. John"
                                   data-dirty-group="name" data-voice-name class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="slug" class="mb-2 block text-[13px] font-semibold text-zinc-300">voice_id <span class="font-normal text-zinc-500">(optional)</span></label>
                            <input id="slug" name="slug" value="{{ old('slug') }}" placeholder="defaults to a slug of the name"
                                   data-dirty-group="voice_id" data-rail-source="identity" class="{{ $inputClass }} font-mono">
                        </div>
                    </div>
                    <p class="mt-3 text-[12.5px] leading-relaxed text-zinc-500">Tip: set the voice_id to your existing ElevenLabs voice_id for a drop-in swap.</p>
                </x-voice.step>

                {{-- ── 2 · Voice source ─────────────────────────────────────── --}}
                <x-voice.step step="source" number="2" title="Voice source" hint="what the voice is made from" state="todo">
                    @include('admin.voices._engine_cards')

                    @include('admin.voices._clip_source', [
                        'replace' => false,
                        'fileHelp' => "WAV/MP3/M4A/OGG/FLAC, up to 20 MB. A clean, quiet ~15–20s sample works best.",
                    ])
                </x-voice.step>

                {{-- ── 3 · Delivery defaults — locked until the voice exists ─── --}}
                <section data-voice-step="delivery" data-state="locked" class="scroll-mt-6 opacity-50">
                    <div data-step-body class="rounded-[14px] border border-white/8 bg-panel p-6">
                        <div class="flex items-baseline justify-between gap-4">
                            <h2 class="text-base font-bold text-zinc-300"><span aria-hidden="true">🔒</span> 3 · Delivery defaults</h2>
                            <span class="text-[12.5px] text-zinc-500">after the voice exists</span>
                        </div>
                        <p class="mt-2.5 text-[13px] leading-relaxed text-zinc-500">You set these by ear — generate a few takes, listen, pick one. That needs a voice to hear, so it opens as soon as this one is created.</p>
                    </div>
                </section>
            </div>
        </div>

        {{-- One primary: Create. No save bar and no change tracking — there's
             nothing saved yet to be out of step with. --}}
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-white/8 bg-sticky/95 backdrop-blur">
            <div class="mx-auto flex max-w-[1100px] flex-wrap items-center gap-x-3.5 gap-y-2 px-4 py-3.5 sm:px-8">
                <span class="text-[13px] text-zinc-500" data-create-hint>Step 2 of 2 — tuning unlocks once the voice exists</span>
                <div class="ml-auto flex shrink-0 items-center gap-2">
                    <a href="{{ route('admin.voices.index') }}" class="px-3.5 py-2 text-sm text-zinc-400 transition hover:text-zinc-200">Cancel</a>
                    {{-- Enabled in markup, gated by initVoiceFlow(): without JS
                         there's no staged-clip state to read, so the server's
                         own validation stays the only gate. --}}
                    <button type="submit" data-create-submit
                            class="rounded-[9px] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-on transition hover:brightness-110 disabled:bg-accent/25 disabled:text-[#3a4a4e] disabled:hover:brightness-100">Create voice</button>
                </div>
            </div>
        </div>
    </form>
</x-layout>
