@props([
    'knob',               // native key: 'exaggeration' | 'cfg_weight' | 'temperature' | 'top_p' | 'top_k' | 'repetition_penalty'
    'label',
    'min',
    'max',
    'step' => '0.05',
    'value' => '',        // explicit override ('' = inherit the resolved default)
    'placeholder' => '',  // the inherited value: shown when blank, and the slider's resting point
    'inputClass' => '',   // surface hook class for the number input (e.g. chunk-exaggeration)
    'hint' => null,       // optional caption next to the label (e.g. "neutral 0.5")
    'hidden' => false,    // start hidden (an inactive engine's knob). The root gets
                          // EITHER `flex` OR `hidden`, never both — JS must toggle
                          // the pair together (see syncKnobEngines in app.js).
])

{{-- One tuning knob as slider + number box + reset (↺), matching the Hugging
     Face demo. The number box is the source of truth ('' = inherit); the slider
     mirrors it. Wired generically by initTuningKnobs() in app.js via .tuning-knob. --}}
<div {{ $attributes->merge(['class' => 'tuning-knob min-w-[11rem] flex-col gap-1 '.($hidden ? 'hidden' : 'flex')]) }} data-knob="{{ $knob }}">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs text-zinc-500">{{ $label }}@if($hint) <span class="text-zinc-600">{{ $hint }}</span>@endif</span>
        <span class="flex items-center gap-1.5">
            <input type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
                   value="{{ $value }}" placeholder="{{ $placeholder }}"
                   class="knob-number {{ $inputClass }} w-16 rounded-lg border border-edge bg-zinc-950 px-2 py-1 text-right text-sm tabular-nums">
            <button type="button" class="knob-reset rounded-lg border border-zinc-700 px-1.5 py-1 text-xs text-zinc-400 hover:bg-zinc-800"
                    title="Reset to the inherited default" aria-label="Reset to the inherited default">↺</button>
        </span>
    </div>
    <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
           value="{{ ($value !== '' && $value !== null) ? $value : ($placeholder !== '' ? $placeholder : $min) }}"
           class="knob-range w-full cursor-pointer accent-cyan-500" aria-label="{{ $label }}">
    <div class="flex justify-between text-[11px] text-zinc-600"><span>{{ $min }}</span><span>{{ $max }}</span></div>
</div>
