{{-- Engine picker for Add a voice: three equal cards, each stating the tradeoff
     up front, because the choice decides both what the voice can do and which
     controls step 3 will offer. Radios (not a select) so the tradeoffs are
     readable side by side — initVoiceEngineToggle() reads either shape. --}}
@php
    use App\Services\Tts\ModelCatalog;

    $models = ModelCatalog::all();
    $currentModel = old('model', ModelCatalog::DEFAULT);
    $presetEngines = collect($models)->filter(fn ($entry) => ($entry['preset_voices'] ?? []) !== [])->keys();
    $currentPreset = old('preset_voice', '');
    $selectClass = 'w-full rounded-[9px] border border-edge bg-inset px-3.5 py-3 text-[15px] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
@endphp

<div class="mb-[18px] grid grid-cols-1 gap-2.5 sm:grid-cols-3" role="radiogroup" aria-label="Engine">
    @foreach($models as $key => $entry)
        <label class="cursor-pointer rounded-[11px] border border-white/9 bg-inset px-4 py-3.5 transition has-[:checked]:border-accent/40 has-[:checked]:bg-accent/[0.06] hover:border-white/20">
            <input type="radio" name="model" value="{{ $key }}" @checked($currentModel === $key)
                   data-engine-input data-label="{{ $entry['label'] ?? $key }}" data-dirty-group="engine"
                   class="sr-only">
            <span class="mb-1 block text-sm font-semibold text-zinc-200">{{ $entry['label'] ?? $key }}</span>
            <span class="block text-xs leading-[1.45] text-zinc-500">{{ ModelCatalog::tagline($key) }}</span>
        </label>
    @endforeach
</div>

{{-- Built-in voices, for the engines that ship them. A clip always wins; this
     is what the voice speaks through when there isn't one. --}}
<div class="mb-[18px]" data-engine-only="{{ $presetEngines->implode(' ') }}" @class(['hidden' => ! $presetEngines->contains($currentModel)])>
    <label for="preset-voice" class="mb-2 block text-[13px] font-semibold text-zinc-300">Built-in voice <span class="font-normal text-zinc-500">(used when there's no reference clip)</span></label>
    <select id="preset-voice" name="preset_voice" data-dirty-group="built-in voice" data-voice-source class="{{ $selectClass }}">
        <option value="">— none, use the reference clip —</option>
        @foreach($presetEngines as $engineKey)
            @foreach(ModelCatalog::presetVoices($engineKey) as $preset)
                <option value="{{ $preset }}" data-model="{{ $engineKey }}"
                        @selected($currentModel === $engineKey && $currentPreset === $preset)
                        @class(['hidden' => $currentModel !== $engineKey])>{{ $preset }}</option>
            @endforeach
        @endforeach
    </select>
    <p data-engine-only="chatterbox-turbo" @class(['mt-2 text-[12.5px] leading-relaxed text-zinc-500', 'hidden' => $currentModel !== 'chatterbox-turbo'])>Turbo needs a reference clip <strong>longer than 5 seconds</strong> — or one of these built-ins instead of a clip.</p>
    <p data-engine-only="qwen3-tts" @class(['mt-2 text-[12.5px] leading-relaxed text-zinc-500', 'hidden' => $currentModel !== 'qwen3-tts'])>Qwen clones from a reference clip of <strong>at least 3 seconds</strong> (aim for 15–20s) — or speaks through one of these built-ins instead.</p>
</div>
