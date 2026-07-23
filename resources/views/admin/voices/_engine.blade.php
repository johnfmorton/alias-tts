{{-- Engine picker: which catalog model this voice generates with, plus the
     built-in preset voice for engines that ship them (Turbo, Qwen). The preset
     only matters when the voice has no reference clip — a clip always wins. --}}
@php($models = \App\Services\Tts\ModelCatalog::all())
@php($currentModel = old('model', \App\Services\Tts\ModelCatalog::forVoice($voice)))
@php($currentPreset = old('preset_voice', $voice->settings['preset_voice'] ?? ''))
@php($presetEngines = collect($models)->filter(fn ($entry) => ($entry['preset_voices'] ?? []) !== [])->keys())
@php($selectClass = 'w-full rounded-[9px] border border-edge bg-inset px-3.5 py-3 text-[15px] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30')

<x-voice.section label="Engine">
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
            <label for="voice-model" class="mb-2 block text-[13px] font-semibold text-zinc-300">Model</label>
            <select id="voice-model" name="model" class="{{ $selectClass }}">
                @foreach($models as $key => $entry)
                    <option value="{{ $key }}" @selected($currentModel === $key)>{{ $entry['label'] ?? $key }}</option>
                @endforeach
            </select>
            <p class="mt-2 text-[12.5px] leading-relaxed text-zinc-500">Chatterbox is the expressive classic; Turbo is faster, supports sound tags like [laugh], and offers built-in voices; Qwen3 TTS speaks ten languages, offers built-in voices, and takes a free-text style note. Each model has its own tuning knobs and per-character rate.</p>
        </div>
        {{-- One preset select shared by every preset-bearing engine: options carry
             data-model and the JS filters them (and clears a foreign pick) when
             the engine changes. --}}
        <div data-engine-only="{{ $presetEngines->implode(' ') }}" @class(['hidden' => ! $presetEngines->contains($currentModel)])>
            <label for="preset-voice" class="mb-2 block text-[13px] font-semibold text-zinc-300">Built-in voice <span class="font-normal text-zinc-500">(used when there's no reference clip)</span></label>
            <select id="preset-voice" name="preset_voice" class="{{ $selectClass }}">
                <option value="">— none, use the reference clip —</option>
                @foreach($presetEngines as $engineKey)
                    @foreach(\App\Services\Tts\ModelCatalog::presetVoices($engineKey) as $preset)
                        <option value="{{ $preset }}" data-model="{{ $engineKey }}"
                                @selected($currentModel === $engineKey && $currentPreset === $preset)
                                @class(['hidden' => $currentModel !== $engineKey])>{{ $preset }}</option>
                    @endforeach
                @endforeach
            </select>
            <p data-engine-only="chatterbox-turbo" @class(['mt-2 text-[12.5px] leading-relaxed text-zinc-500', 'hidden' => $currentModel !== 'chatterbox-turbo'])>Turbo needs a reference clip <strong>longer than 5 seconds</strong> — or one of these built-ins instead of a clip.</p>
            <p data-engine-only="qwen3-tts" @class(['mt-2 text-[12.5px] leading-relaxed text-zinc-500', 'hidden' => $currentModel !== 'qwen3-tts'])>Qwen clones from a reference clip of <strong>at least 3 seconds</strong> (aim for 15–20s) — or speaks through one of these built-ins instead.</p>
        </div>
    </div>
</x-voice.section>
