{{-- A/B tuning bench for ONE voice: audition a sample line at several settings,
     pick the winner, save it as this voice's defaults. Row one is pre-filled with
     the voice's current defaults. Wired by initTuningBench() in app.js via
     .tuning-bench; generation goes through the Studio synthesize endpoint. The JS
     builds rows into .bench-rows as a table matching the header grid. --}}
@php
    $benchTuning = is_array($voice->settings) ? $voice->settings : [];
    $benchNative = \App\Services\Tts\ChatterboxTuning::resolveNative($benchTuning);
    $benchExag = isset($benchTuning['exaggeration']) || isset($benchTuning['style']) ? $benchNative['exaggeration'] : '';
    $benchCfg = isset($benchTuning['cfg_weight']) || isset($benchTuning['stability']) ? $benchNative['cfg_weight'] : '';
@endphp
<div class="mt-[26px]">
    <div class="mb-3.5 flex items-center gap-2">
        <span class="text-xs font-bold uppercase tracking-[0.1em] text-ok">Tune by ear</span>
    </div>
    <div class="tuning-bench rounded-[14px] border border-white/8 bg-panel p-6"
         data-synthesize-url="{{ route('admin.studio.synthesize') }}"
         data-voice-defaults-url="{{ route('admin.studio.voice-defaults') }}"
         data-voice="{{ $voice->slug }}"
         data-exaggeration="{{ $benchExag }}"
         data-cfg="{{ $benchCfg }}">
        <p class="max-w-[820px] text-[13.5px] leading-[1.55] text-zinc-400">
            Hear <strong class="text-zinc-300">{{ $voice->name }}</strong> read the sample line at different settings, pick the best take,
            and save it as the voice's defaults. Higher exaggeration = more animated delivery; lower CFG/Pace = quicker,
            looser pacing. Row one starts at the voice's current defaults.
        </p>

        <label class="mt-5 block">
            <span class="mb-2 block text-[13px] font-semibold text-zinc-300">Sample line</span>
            <textarea rows="2"
                      class="bench-text w-full rounded-[10px] border border-white/12 bg-inset px-4 py-3.5 text-[17px] leading-[1.55] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">Welcome back to the show. Today we're trying something new — and honestly? I can't wait.</textarea>
        </label>

        {{-- Named presets: bookmarks of knob values, reusable on any voice's bench.
             Click a name to add a pre-filled row; ✕ deletes. --}}
        <div class="bench-presets mt-4 flex flex-wrap items-center gap-2"
             data-store-url="{{ route('admin.studio.presets.store') }}">
            <span class="text-[13px] text-zinc-500" title="A preset is a named pair of knob values — it changes nothing until you apply it and save">Presets:</span>
            @foreach($presets as $preset)
                <span class="bench-preset inline-flex items-center gap-1 rounded-full border border-white/12 bg-inset py-0.5 pl-2.5 pr-1.5 text-xs"
                      data-id="{{ $preset->id }}" data-exaggeration="{{ $preset->exaggeration }}" data-cfg="{{ $preset->cfg_weight }}">
                    <button type="button" class="preset-apply text-zinc-200 hover:text-accent">{{ $preset->name }}</button>
                    <button type="button" class="preset-delete text-zinc-500 hover:text-bad" title="Delete preset" aria-label="Delete preset">✕</button>
                </span>
            @endforeach
            <span class="bench-preset-empty text-[13px] text-zinc-600 @unless($presets->isEmpty()) hidden @endunless">none yet</span>
            <button type="button"
                    class="bench-preset-save rounded-[8px] border border-white/12 px-3 py-1.5 text-[13px] text-zinc-300 hover:bg-white/5"
                    title="Bookmark the picked row's values under a name, to reuse on any voice">＋ Save pick as preset</button>
        </div>

        <div class="mt-4 overflow-hidden rounded-[12px] border border-white/8">
            <div class="grid grid-cols-[44px_1.4fr_1.4fr_1fr_1.6fr_40px] items-center gap-2 border-b border-white/8 bg-inset px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.05em] text-zinc-500">
                <div></div><div>Exaggeration</div><div>CFG / Pace</div><div>Preview</div><div>Take</div><div></div>
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
