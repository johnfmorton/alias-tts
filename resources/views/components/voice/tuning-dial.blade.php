@props(['name', 'label', 'hint', 'min', 'max', 'value' => '', 'neutral' => '0.5'])
{{-- A default-tuning dial: a number field and a visual slider that are two views
     of one value (synced in JS — initVoiceTuningDials). Blank number = system
     default; the slider then rests at neutral without writing a value. `neutral`
     is the resting point (0.5 for exaggeration/cfg, 0.8 for temperature). --}}
<div data-tuning-dial data-neutral="{{ $neutral }}">
    <div class="mb-2.5 flex items-baseline justify-between">
        <span class="text-[13px] font-semibold text-zinc-300">{{ $label }}</span>
        <span class="text-xs text-zinc-500">{{ $hint }}</span>
    </div>
    <div class="flex items-center gap-3.5">
        <input id="{{ $name }}" name="{{ $name }}" type="number" step="0.05" min="{{ $min }}" max="{{ $max }}"
               value="{{ $value }}" placeholder="{{ $neutral }}" data-tuning-number
               class="w-[74px] rounded-[9px] border border-white/12 bg-inset px-2 py-2.5 text-center text-[15px] text-zinc-100 placeholder:text-zinc-600 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
        {{-- Visual affordance only (the number is the accessible control). --}}
        <input type="range" min="{{ $min }}" max="{{ $max }}" step="0.05" value="{{ $value !== '' ? $value : $neutral }}"
               data-tuning-slider aria-hidden="true" tabindex="-1" class="tuning-slider flex-1">
    </div>
</div>
