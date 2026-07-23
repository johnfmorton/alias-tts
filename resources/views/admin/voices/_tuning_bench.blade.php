{{-- A/B tuning bench for ONE voice: audition a sample line at several settings,
     pick the winner, save it as this voice's defaults. Row one is pre-filled with
     the voice's current defaults. Wired by initTuningBench() in app.js via
     .tuning-bench; generation goes through the Studio synthesize endpoint. The JS
     builds rows into .bench-rows as a table matching the header grid.

     The bench speaks the voice's SAVED engine (data-model): classic chatterbox
     rows carry exaggeration/cfg/temperature, turbo rows top-p/top-k/repetition
     penalty/temperature. Changing the Engine select above only retunes the bench
     after the voice is saved. Presets are engine-scoped — only this engine's
     chips are offered, and a new preset records the bench's engine. --}}
@php
    $benchModel = \App\Services\Tts\ModelCatalog::forVoice($voice);
    $benchTuning = is_array($voice->settings) ? $voice->settings : [];
    $benchNative = \App\Services\Tts\ChatterboxTuning::resolveNative($benchTuning);
    $benchExag = isset($benchTuning['exaggeration']) || isset($benchTuning['style']) ? $benchNative['exaggeration'] : '';
    $benchCfg = isset($benchTuning['cfg_weight']) || isset($benchTuning['stability']) ? $benchNative['cfg_weight'] : '';
    $benchTemp = isset($benchTuning['temperature'])
        ? \App\Services\Tts\ChatterboxTuning::clampTemperature((float) $benchTuning['temperature'])
        : '';
    $benchPresets = $presets->filter(fn ($p) => $p->engineModel() === $benchModel);
    $benchGrid = match ($benchModel) {
        'chatterbox-turbo' => 'grid-cols-[44px_1fr_1fr_1fr_1fr_0.8fr_1.5fr_40px]',
        'qwen3-tts' => 'grid-cols-[44px_2fr_0.8fr_1.5fr_40px]',
        default => 'grid-cols-[44px_1.1fr_1.1fr_1.1fr_0.8fr_1.5fr_40px]',
    };
@endphp
<div class="mt-[26px]">
    <div class="mb-3.5 flex items-center gap-2">
        <span class="text-xs font-bold uppercase tracking-[0.1em] text-ok">Tune by ear</span>
        @if($benchModel !== 'chatterbox')
            <span class="text-xs text-zinc-500">{{ \App\Services\Tts\ModelCatalog::label($benchModel) }} knobs</span>
        @endif
    </div>
    <div class="tuning-bench rounded-[14px] border border-white/8 bg-panel p-6"
         data-synthesize-url="{{ route('admin.studio.synthesize') }}"
         data-voice-defaults-url="{{ route('admin.studio.voice-defaults') }}"
         data-voice="{{ $voice->slug }}"
         data-model="{{ $benchModel }}"
         data-exaggeration="{{ $benchExag }}"
         data-cfg="{{ $benchCfg }}"
         data-temperature="{{ $benchTemp }}"
         data-top-p="{{ $benchTuning['top_p'] ?? '' }}"
         data-top-k="{{ $benchTuning['top_k'] ?? '' }}"
         data-repetition-penalty="{{ $benchTuning['repetition_penalty'] ?? '' }}"
         data-style-instruction="{{ $benchTuning['style_instruction'] ?? '' }}">
        <p class="max-w-[820px] text-[13.5px] leading-[1.55] text-zinc-400">
            Hear <strong class="text-zinc-300">{{ $voice->name }}</strong> read the sample line at different settings, pick the best take,
            and save it as the voice's defaults.
            @if($benchModel === 'chatterbox-turbo')
                Lower top-p/top-k = more focused, predictable delivery; higher repetition penalty = fewer repeated sounds;
                higher temperature = livelier but less predictable.
            @elseif($benchModel === 'qwen3-tts')
                Qwen has no numeric knobs — steer it with a plain-words style note
                ("speak slowly and calmly", "excited tone") and compare readings.
            @else
                Higher exaggeration = more animated delivery; lower CFG/Pace = quicker,
                looser pacing; higher temperature = livelier but less predictable.
            @endif
            Row one starts at the voice's current defaults.
        </p>

        <label class="mt-5 block">
            <span class="mb-2 block text-[13px] font-semibold text-zinc-300">Sample line</span>
            <textarea rows="2"
                      class="bench-text w-full rounded-[10px] border border-edge bg-inset px-4 py-3.5 text-[17px] leading-[1.55] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">Welcome back to the show. Today we're trying something new — and honestly? I can't wait.</textarea>
        </label>

        {{-- Named presets: bookmarks of knob values, reusable on any voice's bench
             running the SAME engine. Click a name to add a pre-filled row; ✕ deletes.
             Qwen has no numeric knobs to bookmark, so its bench skips the bar. --}}
        @if($benchModel !== 'qwen3-tts')
        <div class="bench-presets mt-4 flex flex-wrap items-center gap-2"
             data-store-url="{{ route('admin.studio.presets.store') }}">
            <span class="text-[13px] text-zinc-500" title="A preset is a named set of knob values — it changes nothing until you apply it and save">Presets:</span>
            @foreach($benchPresets as $preset)
                <span class="bench-preset inline-flex items-center gap-1 rounded-full border border-white/12 bg-inset py-0.5 pl-2.5 pr-1.5 text-xs"
                      data-id="{{ $preset->id }}" data-exaggeration="{{ $preset->exaggeration }}" data-cfg="{{ $preset->cfg_weight }}" data-temperature="{{ $preset->temperature }}"
                      data-top-p="{{ $preset->top_p }}" data-top-k="{{ $preset->top_k }}" data-repetition-penalty="{{ $preset->repetition_penalty }}">
                    <button type="button" class="preset-apply text-zinc-200 hover:text-accent">{{ $preset->name }}</button>
                    <button type="button" class="preset-delete text-zinc-500 hover:text-bad" title="Delete preset" aria-label="Delete preset">✕</button>
                </span>
            @endforeach
            <span class="bench-preset-empty text-[13px] text-zinc-600 @unless($benchPresets->isEmpty()) hidden @endunless">none yet</span>
            <button type="button"
                    class="bench-preset-save rounded-[8px] border border-white/12 px-3 py-1.5 text-[13px] text-zinc-300 hover:bg-white/5"
                    title="Bookmark the picked row's values under a name, to reuse on any voice">＋ Save pick as preset</button>
        </div>
        @endif

        <div class="mt-4 overflow-hidden rounded-[12px] border border-white/8">
            <div class="grid {{ $benchGrid }} items-center gap-2 border-b border-white/8 bg-inset px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.05em] text-zinc-500">
                @if($benchModel === 'chatterbox-turbo')
                    <div></div><div>Top-p</div><div>Top-k</div><div>Rep. penalty</div><div>Temperature</div><div>Preview</div><div>Take</div><div></div>
                @elseif($benchModel === 'qwen3-tts')
                    <div></div><div>Style note</div><div>Preview</div><div>Take</div><div></div>
                @else
                    <div></div><div>Exaggeration</div><div>CFG / Pace</div><div>Temperature</div><div>Preview</div><div>Take</div><div></div>
                @endif
            </div>
            <ol class="bench-rows"></ol>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button type="button" class="bench-add rounded-[9px] border border-white/12 px-3.5 py-2.5 text-sm text-zinc-300 hover:bg-white/5">+ Add setting</button>
            <button type="button" class="bench-generate inline-flex items-center gap-2 rounded-[9px] bg-accent px-4 py-2.5 text-sm font-semibold text-accent-on hover:brightness-110">▶ Generate all</button>
            <button type="button" class="bench-save inline-flex items-center gap-2 rounded-[9px] border border-ok/40 bg-ok/[0.06] px-4 py-2.5 text-sm font-semibold text-ok hover:bg-ok/10">Save pick as voice defaults</button>
        </div>
        <p class="mt-4 max-w-[820px] text-[12.5px] leading-relaxed text-zinc-500">
            Defaults apply when a request doesn't set its own — future API calls and new projects.
            Existing projects keep the settings they were created with. Each ▶ spends a real generation.
        </p>
        <div class="bench-status mt-2 text-sm text-zinc-400" role="status" aria-live="polite"></div>
    </div>
</div>
