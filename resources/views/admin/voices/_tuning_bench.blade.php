{{-- The takes table IS the knob editor. Each row is a candidate default: row one
     mirrors what's saved right now, ▶ generates that row's take, and the picked
     row's values are written straight into this step's delivery fields — so the
     one save bar at the bottom of the page persists it. There is no second save
     path here by design.

     Wired by initTuningBench() in app.js via .tuning-bench; generation goes
     through the Studio synthesize endpoint. Rows are built in JS into
     .bench-rows against the header grid below.

     The bench speaks the voice's SAVED engine (data-model): classic chatterbox
     rows carry exaggeration/cfg/temperature, turbo rows top-p/top-k/repetition
     penalty/temperature, qwen rows a style note. Presets are engine-scoped —
     only this engine's chips are offered, and a new preset records the engine. --}}
@php
    use App\Services\Tts\ChatterboxTuning;
    use App\Services\Tts\ModelCatalog;

    $benchModel = ModelCatalog::forVoice($voice);
    $benchTuning = is_array($voice->settings) ? $voice->settings : [];
    $benchNative = ChatterboxTuning::resolveNative($benchTuning);
    $benchExag = isset($benchTuning['exaggeration']) || isset($benchTuning['style']) ? $benchNative['exaggeration'] : '';
    $benchCfg = isset($benchTuning['cfg_weight']) || isset($benchTuning['stability']) ? $benchNative['cfg_weight'] : '';
    $benchTemp = isset($benchTuning['temperature'])
        ? ChatterboxTuning::clampTemperature((float) $benchTuning['temperature'])
        : '';
    $benchPresets = $presets->filter(fn ($p) => $p->engineModel() === $benchModel);
    $benchGrid = match ($benchModel) {
        'chatterbox-turbo' => 'grid-cols-[44px_repeat(4,minmax(0,1fr))_56px_minmax(110px,150px)_32px]',
        'qwen3-tts' => 'grid-cols-[44px_minmax(0,1fr)_56px_minmax(110px,150px)_32px]',
        default => 'grid-cols-[44px_repeat(3,minmax(0,1fr))_56px_minmax(110px,150px)_32px]',
    };
    // Below this the columns would collide, so the table scrolls inside its own
    // frame rather than the page scrolling sideways or the rows overlapping.
    $benchMin = $benchModel === 'chatterbox-turbo' ? 'min-w-[700px]' : 'min-w-[560px]';
@endphp
<div class="tuning-bench"
     data-synthesize-url="{{ route('admin.studio.synthesize') }}"
     data-voice="{{ $voice->slug }}"
     data-model="{{ $benchModel }}"
     data-exaggeration="{{ $benchExag }}"
     data-cfg="{{ $benchCfg }}"
     data-temperature="{{ $benchTemp }}"
     data-top-p="{{ $benchTuning['top_p'] ?? '' }}"
     data-top-k="{{ $benchTuning['top_k'] ?? '' }}"
     data-repetition-penalty="{{ $benchTuning['repetition_penalty'] ?? '' }}"
     data-style-instruction="{{ $benchTuning['style_instruction'] ?? '' }}">

    {{-- Sample line: one row, edited in place. --}}
    <label class="flex items-center gap-3 rounded-[11px] border border-white/9 bg-inset px-4 py-3">
        <span class="shrink-0 text-[12.5px] text-zinc-500">Sample line</span>
        <input type="text" class="bench-text min-w-0 flex-1 border-0 bg-transparent p-0 text-[14.5px] text-zinc-100 focus:ring-0 focus:outline-none"
               value="Welcome back to the show. Today we're trying something new — and honestly? I can't wait.">
        <span class="shrink-0 text-[12.5px] text-zinc-500" aria-hidden="true" title="Edit the sample line">✎</span>
    </label>

    <div class="mt-4 overflow-x-auto rounded-[12px] border border-white/8">
        <div class="grid {{ $benchGrid }} {{ $benchMin }} items-center gap-2 border-b border-white/8 bg-inset px-4 py-2.5 text-[11px] font-bold tracking-[0.08em] text-zinc-500 uppercase">
            @if($benchModel === 'chatterbox-turbo')
                <span></span><span>Top-p</span><span>Top-k</span><span>Rep. penalty</span><span>Temperature</span><span>Play</span><span>Take</span><span></span>
            @elseif($benchModel === 'qwen3-tts')
                <span></span><span>Style note</span><span>Play</span><span>Take</span><span></span>
            @else
                <span></span><span>Exaggeration</span><span>CFG / Pace</span><span>Temperature</span><span>Play</span><span>Take</span><span></span>
            @endif
        </div>
        <ol class="bench-rows {{ $benchMin }}"></ol>
    </div>

    <div class="mt-3.5 flex flex-wrap items-center gap-2.5">
        <button type="button" class="bench-add rounded-[8px] border border-edge px-3.5 py-2 text-[13px] text-zinc-300 transition hover:bg-white/5">+ Add row</button>
        <button type="button" class="bench-generate rounded-[8px] border border-edge px-3.5 py-2 text-[13px] text-zinc-300 transition hover:bg-white/5">▶ Generate all</button>
        <span class="text-[12.5px] text-zinc-500">each ▶ spends one generation</span>

        {{-- Named presets: bookmarks of knob values, reusable on any voice's bench
             running the SAME engine. Qwen has no numeric knobs to bookmark. --}}
        @if($benchModel !== 'qwen3-tts')
            <div class="bench-presets relative ml-auto" data-store-url="{{ route('admin.studio.presets.store') }}">
                <button type="button" class="bench-presets-toggle text-[12.5px] text-zinc-500 transition hover:text-zinc-300"
                        aria-haspopup="true" aria-expanded="false"
                        title="A preset is a named set of knob values — it changes nothing until you apply it and save">Presets ▾</button>
                <div class="bench-presets-menu absolute right-0 bottom-full z-20 mb-2 hidden w-[280px] rounded-[12px] border border-white/10 bg-menu p-3 shadow-[0_20px_40px_-12px_rgba(0,0,0,0.7)]">
                    <p class="mb-2 text-[11px] font-semibold tracking-wider text-zinc-500 uppercase">Apply a preset</p>
                    <div class="bench-preset-list flex flex-wrap gap-1.5">
                        @foreach($benchPresets as $preset)
                            <span class="bench-preset inline-flex items-center gap-1 rounded-full border border-white/12 bg-inset py-0.5 pr-1.5 pl-2.5 text-xs"
                                  data-id="{{ $preset->id }}" data-exaggeration="{{ $preset->exaggeration }}" data-cfg="{{ $preset->cfg_weight }}" data-temperature="{{ $preset->temperature }}"
                                  data-top-p="{{ $preset->top_p }}" data-top-k="{{ $preset->top_k }}" data-repetition-penalty="{{ $preset->repetition_penalty }}">
                                <button type="button" class="preset-apply text-zinc-200 hover:text-accent">{{ $preset->name }}</button>
                                <button type="button" class="preset-delete text-zinc-500 hover:text-bad" title="Delete preset" aria-label="Delete preset">✕</button>
                            </span>
                        @endforeach
                    </div>
                    <span class="bench-preset-empty block text-[13px] text-zinc-600 @unless($benchPresets->isEmpty()) hidden @endunless">none yet</span>
                    <button type="button"
                            class="bench-preset-save mt-3 w-full rounded-[8px] border border-white/12 px-3 py-1.5 text-[13px] text-zinc-300 transition hover:bg-white/5"
                            title="Bookmark the picked row's values under a name, to reuse on any voice">＋ Save pick as preset</button>
                </div>
            </div>
        @endif
    </div>

    <p class="mt-3 text-xs leading-[1.5] text-zinc-500">
        @if($benchModel === 'chatterbox-turbo')
            Lower top-p/top-k = more focused, predictable · higher repetition penalty = fewer repeated sounds · higher temperature = livelier, less predictable
        @elseif($benchModel === 'qwen3-tts')
            Qwen has no numeric knobs — steer it in plain words. Edit any row or add your own; picking one writes it into the Style note above, ready to save.
        @else
            Higher exaggeration = more animated · lower CFG/Pace = quicker, looser · higher temperature = livelier, less predictable
        @endif
    </p>
    <div class="bench-status mt-2 text-sm text-zinc-400" role="status" aria-live="polite"></div>
</div>
