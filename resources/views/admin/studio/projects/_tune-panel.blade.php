{{-- The per-chunk tuning panel body (Delivery chips, saved presets, seed pin,
     fine-tune knobs). Rendered per card on eager markup, or ONCE into
     #chunk-tune-template on slim cards ($chunk = null: every value blank =
     inherit; ensureTune() in app.js clones it and fills the chunk's overrides
     from data-tune-settings as the card nears the viewport). Inherits the
     page scope: $chunkModel, $presets, $project, and the $inherit* values. --}}
@php $s = is_array($chunk?->settings) ? $chunk->settings : []; @endphp

{{-- Delivery: the everyday control. Three archetypes fill the (hidden)
     sliders below; dragging a slider off an archetype flips this to an
     implicit Custom (no chip lit). JS applies + matches against
     data-delivery-presets on #studio-project, per the active engine. --}}
<div @class(['chunk-delivery-wrap mt-3', 'hidden' => $chunkModel === 'qwen3-tts'])>
    <span class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">Delivery</span>
    <div class="chunk-delivery mt-1.5 flex flex-wrap gap-2">
        @foreach(['steady' => ['Steady', 'focused, consistent'], 'balanced' => ['Balanced', 'neutral default'], 'expressive' => ['Expressive', 'varied, lively']] as $key => $meta)
            <button type="button" class="delivery-chip" data-delivery="{{ $key }}">
                <span class="delivery-chip__name">{{ $meta[0] }}</span>
                <span class="delivery-chip__desc">{{ $meta[1] }}</span>
            </button>
        @endforeach
    </div>
    @if($presets->isNotEmpty())
        {{-- User-saved named combos ("Calm narration"): a secondary way to
             fill the sliders. Belongs to an engine — JS hides foreign ones. --}}
        <label class="mt-2 flex items-center gap-2 text-xs text-zinc-500">Saved
            <select class="chunk-preset rounded-lg border border-edge bg-zinc-950 px-2 py-1 text-sm text-zinc-300">
                <option value="" selected>Apply a saved preset…</option>
                @foreach($presets as $preset)
                    <option value="{{ $preset->id }}" data-model="{{ $preset->engineModel() }}"
                            data-exaggeration="{{ $preset->exaggeration }}" data-cfg="{{ $preset->cfg_weight }}" data-temperature="{{ $preset->temperature }}"
                            data-top-p="{{ $preset->top_p }}" data-top-k="{{ $preset->top_k }}" data-repetition-penalty="{{ $preset->repetition_penalty }}"
                            @class(['hidden' => $preset->engineModel() !== $chunkModel])>{{ $preset->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
</div>

{{-- Seed pin — for engines whose schema has one (qwen's doesn't, so
     the row is engine-scoped via data-knob like the sliders). Not a
     slider knob (integer, no neutral). Blank inherits the project seed
     (or rolls random), so a blank-seed Regenerate IS the fresh-take
     re-roll. A pin only biases the draw; Chatterbox is not
     bit-reproducible even so. --}}
<div class="tuning-knob mt-4 {{ $chunkModel === 'qwen3-tts' ? 'hidden' : 'flex' }} flex-wrap items-center gap-x-3 gap-y-1.5" data-knob="seed">
    <span class="text-sm text-zinc-300">Seed</span>
    <input type="number" min="0" step="1"
           value="{{ $s['seed'] ?? '' }}" placeholder="{{ $inheritSeedText }}"
           class="chunk-seed w-28 rounded-lg border border-edge bg-zinc-950 px-2 py-1 text-right text-sm tabular-nums">
    <button type="button" class="chunk-seed-random rounded-lg border border-edge px-1.5 py-1 text-sm text-zinc-400 hover:bg-zinc-800"
            title="Roll a random seed" aria-label="Roll a random seed">🎲</button>
    <span class="text-[11px] text-zinc-500">Leave blank for a fresh variation. Reusing a number may produce a similar—but not identical—take.</span>
</div>

{{-- Fine-tune: the raw sliders, collapsed by default. The effective
     voice's engine decides which knobs show (classic: exaggeration/cfg ·
     turbo: top-p/top-k/repetition penalty · temperature both); JS re-syncs
     on a voice change via syncKnobEngines (toggling flex AND hidden
     together), keeps the (N) count in step, and remembers open/closed per
     user (localStorage). --}}
<div class="finetune mt-4 border-t border-white/8 pt-3">
    <div class="flex items-center justify-between gap-2">
        <button type="button" class="finetune-toggle inline-flex items-center gap-2 text-sm font-semibold text-accent" aria-expanded="false">
            <span class="finetune-caret text-[10px] leading-none">▸</span> Fine-tune <span class="finetune-count font-normal text-zinc-500">(3)</span>
        </button>
        {{-- Reset all: clear every override + seed back to the project's
             inherited tuning (which lights Balanced on a default project).
             Only meaningful with the sliders open, so shown with them. --}}
        <button type="button" class="chunk-tune-reset-all hidden text-xs text-zinc-500 hover:text-zinc-300">Reset all</button>
    </div>
    <div class="finetune-body mt-3 hidden flex-col gap-4">
        <x-tuning-knob knob="exaggeration" label="Exaggeration"
                       hint="how animated the delivery is · neutral 0.5"
                       help="How animated the delivery is — higher is more expressive and intense, lower is flatter. 0.5 is neutral."
                       :min="0.25" :max="2" :step="0.05"
                       :value="$s['exaggeration'] ?? ''" :placeholder="$inheritExaggeration"
                       inputClass="chunk-exaggeration" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox'" />
        <x-tuning-knob knob="cfg_weight" label="CFG / Pace"
                       hint="higher = measured read, lower = quicker · neutral 0.5"
                       help="Pacing steadiness — higher sticks closer to a measured read, lower is quicker and looser."
                       :min="0.2" :max="1" :step="0.05"
                       :value="$s['cfg_weight'] ?? ''" :placeholder="$inheritCfg"
                       inputClass="chunk-cfg" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox'" />
        <x-tuning-knob knob="top_p" label="Top-p"
                       hint="how adventurous each step can be · neutral 0.95"
                       help="Limits the pool of likely next sounds. Lower is focused and consistent, higher allows more varied phrasing."
                       :min="0.5" :max="1" :step="0.01"
                       :value="$s['top_p'] ?? ''" :placeholder="$inheritTopP"
                       inputClass="chunk-top-p" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox-turbo'" />
        <x-tuning-knob knob="top_k" label="Top-k"
                       hint="size of that pool · neutral 1000"
                       help="How many candidate sounds Top-p draws from. Smaller is tighter and more predictable, larger is more varied."
                       :min="1" :max="2000" :step="1"
                       :value="$s['top_k'] ?? ''" :placeholder="$inheritTopK"
                       inputClass="chunk-top-k" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox-turbo'" />
        <x-tuning-knob knob="repetition_penalty" label="Rep. penalty"
                       hint="nudge up if syllables stutter · neutral 1.2"
                       help="Discourages repeated sounds — nudge it up if syllables or words stutter."
                       :min="1" :max="2" :step="0.05"
                       :value="$s['repetition_penalty'] ?? ''" :placeholder="$inheritRepPenalty"
                       inputClass="chunk-repetition-penalty" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox-turbo'" />
        <x-tuning-knob knob="temperature" label="Temperature"
                       hint="lower = steadier, higher = livelier · neutral 0.8"
                       help="Sampling randomness — lower is flatter and steadier, higher is livelier but less predictable."
                       :min="0.5" :max="1.5" :step="0.05"
                       :value="$s['temperature'] ?? ''" :placeholder="$inheritTemperature"
                       inputClass="chunk-temperature" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel === 'qwen3-tts'" />
        {{-- Qwen's string controls (it has no numeric knobs); same
             .tuning-knob/data-knob contract so syncKnobEngines swaps
             them with the chunk's engine. --}}
        <div class="tuning-knob relative w-full {{ $chunkModel === 'qwen3-tts' ? 'flex' : 'hidden' }} flex-col gap-1" data-knob="language">
            <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-zinc-300">Language</span>
                <select class="chunk-language ml-auto rounded-lg border border-edge bg-zinc-950 px-2 py-1 text-sm text-zinc-200">
                    <option value="">Inherit ({{ $project->settings['language'] ?? 'auto' }})</option>
                    @foreach(\App\Services\Tts\Qwen3TtsTuning::LANGUAGES as $lang)
                        <option value="{{ $lang }}" @selected(($s['language'] ?? '') === $lang)>{{ $lang === 'auto' ? 'Auto-detect' : $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div class="text-[11.5px] text-zinc-500">which language the text is read as · auto detects</div>
        </div>
        <div class="tuning-knob relative w-full {{ $chunkModel === 'qwen3-tts' ? 'flex' : 'hidden' }} flex-col gap-1" data-knob="style_instruction">
            <span class="text-sm font-semibold text-zinc-300">Style note</span>
            <input type="text" maxlength="{{ \App\Services\Tts\Qwen3TtsTuning::STYLE_INSTRUCTION_MAX }}"
                   value="{{ $s['style_instruction'] ?? '' }}"
                   placeholder="{{ $project->settings['style_instruction'] ?? 'e.g. speak slowly and calmly' }}"
                   class="chunk-style-instruction w-full rounded-lg border border-edge bg-zinc-950 px-2 py-1 text-sm text-zinc-200">
            <div class="text-[11.5px] text-zinc-500">plain-words delivery steer · blank inherits</div>
        </div>
    </div>
</div>

{{-- No buttons down here on purpose: Regenerate (top of the card) is
     the one render action, and it saves this panel as part of the
     click. This line is the panel's only reminder of that contract. --}}
<p class="mt-4 border-t border-white/8 pt-3 text-xs text-zinc-500">Regenerate renders with these settings and saves them · selecting an older take restores its text &amp; settings.</p>
