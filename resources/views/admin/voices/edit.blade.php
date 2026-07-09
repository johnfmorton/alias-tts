@php
    // The form speaks Chatterbox's native knobs. A voice saved before v0.15.0 may
    // still carry the ElevenLabs-style pair — show its native equivalent (saving
    // rewrites it in native form); show blank when the voice has no default for
    // that knob at all.
    $tuning = is_array($voice->settings) ? $voice->settings : [];
    $native = \App\Services\Tts\ChatterboxTuning::resolveNative($tuning);
    $exagValue = isset($tuning['exaggeration']) || isset($tuning['style']) ? $native['exaggeration'] : '';
    $cfgValue = isset($tuning['cfg_weight']) || isset($tuning['stability']) ? $native['cfg_weight'] : '';
    // Temperature is native-only (no ElevenLabs twin); blank when unset.
    $temperatureValue = isset($tuning['temperature'])
        ? \App\Services\Tts\ChatterboxTuning::clampTemperature((float) $tuning['temperature'])
        : '';
    $inputClass = 'w-full rounded-[9px] border border-white/12 bg-inset px-3.5 py-3 text-[15px] text-zinc-100 placeholder:text-zinc-600 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
@endphp
<x-layout title="Edit voice" :heading="false" contentWidth="max-w-[1060px]">
    <form id="voice-form" method="POST" action="{{ route('admin.voices.update', $voice) }}" enctype="multipart/form-data"
          data-busy data-busy-label="Saving changes…"
          data-busy-message="Cleaning up and normalizing a replacement clip can take up to a minute — keep this page open.">
        @csrf
        @method('PUT')

        {{-- Header: pinned like the Studio command bar so Save/Cancel stay reachable
             no matter how far the form is scrolled. --}}
        <div class="sticky top-0 z-30 -mx-4 mb-8 border-b border-white/[0.09] bg-sticky px-4 py-4 shadow-[0_12px_26px_-14px_rgba(0,0,0,0.85)]">
            <div class="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <h1 class="text-[27px] font-bold tracking-[-0.015em] text-zinc-100">Edit voice</h1>
                    <p class="mt-1.5 text-sm text-zinc-500">Rename the voice_id, set default tuning, or replace the reference clip.</p>
                </div>
                <div class="flex flex-shrink-0 items-center gap-2">
                    <a href="{{ route('admin.voices.index') }}" class="px-3.5 py-2.5 text-sm text-zinc-400 transition hover:text-zinc-200">Cancel</a>
                    <button type="submit" class="rounded-[9px] bg-accent px-5 py-2.5 text-sm font-semibold text-accent-on transition hover:brightness-110">Save changes</button>
                </div>
            </div>
        </div>

        {{-- Preserve any existing seed without surfacing it (a fixed seed doesn't
             guarantee an identical take). --}}
        <input type="hidden" name="seed" value="{{ old('seed', $voice->settings['seed'] ?? '') }}">

        <x-voice.section label="Identity">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label for="name" class="mb-2 block text-[13px] font-semibold text-zinc-300">Name</label>
                    <input id="name" name="name" value="{{ old('name', $voice->name) }}" required class="{{ $inputClass }}">
                </div>
                <div>
                    <label for="slug" class="mb-2 block text-[13px] font-semibold text-zinc-300">voice_id</label>
                    <input id="slug" name="slug" value="{{ old('slug', $voice->slug) }}" required class="{{ $inputClass }} font-mono">
                </div>
            </div>
            <p class="mt-3 text-[12.5px] leading-relaxed text-zinc-500">Used in the API path and by clients (e.g. the Bespoken plugin). Renaming it also moves the stored reference clip.</p>
        </x-voice.section>

        <x-voice.section label="Default tuning" hint="optional">
            <div class="grid grid-cols-1 gap-7 sm:grid-cols-3">
                <x-voice.tuning-dial name="exaggeration" label="Exaggeration" hint="0.25–2 · neutral 0.5"
                                     min="0.25" max="2" :value="old('exaggeration', $exagValue)" />
                <x-voice.tuning-dial name="cfg_weight" label="CFG / Pace" hint="0.2–1 · neutral 0.5"
                                     min="0.2" max="1" :value="old('cfg_weight', $cfgValue)" />
                <x-voice.tuning-dial name="temperature" label="Temperature" hint="0.5–1.5 · neutral 0.8"
                                     min="0.5" max="1.5" neutral="0.8" :value="old('temperature', $temperatureValue)" />
            </div>
            <p class="mt-4 text-[12.5px] leading-relaxed text-zinc-500">Used when a request doesn't set its own. Higher exaggeration = more animated delivery; lower CFG/Pace = quicker, looser pacing; higher temperature = livelier but less predictable. Blank uses the system defaults.</p>
        </x-voice.section>

        @include('admin.voices._clip_source', [
            'replace' => true,
            'hint' => 'optional, but recommended · '.($voice->reference_audio_path ? 'current clip present' : 'no clip yet'),
            'fileHelp' => 'Leave empty to keep the current clip ('.($voice->reference_audio_path ? 'present' : 'none').').',
        ])

        {{-- The bench lives inside the form so the sticky header's containing block
             spans the whole page (keeping it pinned past the clip section). It's safe:
             every control is type=button and it has no named inputs, so nothing here
             is submitted with the voice form. --}}
        @include('admin.voices._tuning_bench', ['voice' => $voice, 'presets' => $presets])
    </form>
</x-layout>
