{{-- Engine picker: which catalog model this voice generates with, plus the
     built-in preset voice for engines that ship them (Turbo). The preset only
     matters when the voice has no reference clip — a clip always wins. --}}
@php($models = \App\Services\Tts\ModelCatalog::all())
@php($currentModel = old('model', \App\Services\Tts\ModelCatalog::forVoice($voice)))
@php($currentPreset = old('preset_voice', $voice->settings['preset_voice'] ?? ''))
@php($turboPresets = \App\Services\Tts\ModelCatalog::presetVoices('chatterbox-turbo'))
@php($selectClass = 'w-full rounded-[9px] border border-white/12 bg-inset px-3.5 py-3 text-[15px] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30')

<x-voice.section label="Engine">
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
            <label for="voice-model" class="mb-2 block text-[13px] font-semibold text-zinc-300">Model</label>
            <select id="voice-model" name="model" class="{{ $selectClass }}">
                @foreach($models as $key => $entry)
                    <option value="{{ $key }}" @selected($currentModel === $key)>{{ $entry['label'] ?? $key }}</option>
                @endforeach
            </select>
            <p class="mt-2 text-[12.5px] leading-relaxed text-zinc-500">Chatterbox is the expressive classic; Turbo is faster, supports sound tags like [laugh], and offers built-in voices. Each model has its own tuning knobs and per-character rate.</p>
        </div>
        <div data-engine-only="chatterbox-turbo" @class(['hidden' => $currentModel !== 'chatterbox-turbo'])>
            <label for="preset-voice" class="mb-2 block text-[13px] font-semibold text-zinc-300">Built-in voice <span class="font-normal text-zinc-500">(used when there's no reference clip)</span></label>
            <select id="preset-voice" name="preset_voice" class="{{ $selectClass }}">
                <option value="">— none, use the reference clip —</option>
                @foreach($turboPresets as $preset)
                    <option value="{{ $preset }}" @selected($currentPreset === $preset)>{{ $preset }}</option>
                @endforeach
            </select>
            <p class="mt-2 text-[12.5px] leading-relaxed text-zinc-500">Turbo needs a reference clip <strong>longer than 5 seconds</strong> — or one of these built-ins instead of a clip.</p>
        </div>
    </div>
</x-voice.section>
