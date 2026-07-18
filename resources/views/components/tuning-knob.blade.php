@props([
    'knob',               // native key: 'exaggeration' | 'cfg_weight' | 'temperature' | 'top_p' | 'top_k' | 'repetition_penalty'
    'label',
    'min',
    'max',
    'step' => '0.05',
    'value' => '',        // explicit override ('' = inherit the resolved default)
    'placeholder' => '',  // the inherited value: shown when blank, and the slider's resting point
    'inputClass' => '',   // surface hook class for the number input (e.g. chunk-exaggeration)
    'hint' => null,       // one-line caption under the label (e.g. "lower = steadier · neutral 0.8")
    'help' => null,       // deeper explanation revealed by the ⓘ popover (plain text)
    'reset' => true,      // show the per-knob ↺ reset (the Studio editor hides it — it has "Reset all")
    'rail' => true,       // show the min/max rail labels (dropped where the hint carries the range)
    'hidden' => false,    // start hidden (an inactive engine's knob). The root gets
                          // EITHER `flex` OR `hidden`, never both — JS must toggle
                          // the pair together (see syncKnobEngines in app.js).
])

{{-- One tuning knob as slider + number box (+ optional ↺ reset). The number box
     is the source of truth ('' = inherit); the slider mirrors it. Wired
     generically by initTuningKnobs() in app.js via .tuning-knob. An optional ⓘ
     opens a small popover with the deeper explanation (toggled by a delegated
     handler in app.js), so the panel needn't carry a wall of help text. --}}
<div {{ $attributes->merge(['class' => 'tuning-knob relative min-w-[11rem] flex-col gap-1 '.($hidden ? 'hidden' : 'flex')]) }} data-knob="{{ $knob }}">
    <div class="flex items-center gap-2">
        <span class="text-sm font-semibold text-zinc-300">{{ $label }}</span>
        @if($help)
            <button type="button" class="knob-info" aria-label="About {{ $label }}" aria-expanded="false" title="What this does">i</button>
            <div class="knob-popover hidden" role="tooltip"><p>{{ $help }}</p></div>
        @endif
        <span class="ml-auto flex items-center gap-1.5">
            <input type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
                   value="{{ $value }}" placeholder="{{ $placeholder }}"
                   class="knob-number {{ $inputClass }} w-16 rounded-lg border border-edge bg-zinc-950 px-2 py-1 text-right text-sm tabular-nums">
            @if($reset)
                <button type="button" class="knob-reset rounded-lg border border-zinc-700 px-1.5 py-1 text-xs text-zinc-400 hover:bg-zinc-800"
                        title="Reset to the inherited default" aria-label="Reset to the inherited default">↺</button>
            @endif
        </span>
    </div>
    @if($hint)
        <div class="text-[11.5px] text-zinc-500">{{ $hint }}</div>
    @endif
    <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}"
           value="{{ ($value !== '' && $value !== null) ? $value : ($placeholder !== '' ? $placeholder : $min) }}"
           class="knob-range w-full cursor-pointer accent-cyan-500" aria-label="{{ $label }}">
    @if($rail)
        <div class="flex justify-between text-[11px] text-zinc-600"><span>{{ $min }}</span><span>{{ $max }}</span></div>
    @endif
</div>
