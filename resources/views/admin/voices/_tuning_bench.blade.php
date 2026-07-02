{{-- A/B tuning bench for ONE voice: audition a sample line at several settings,
     pick the winner, save it as this voice's defaults. Row one is pre-filled
     with the voice's current defaults. Wired by initTuningBench() in app.js via
     .tuning-bench; generation goes through the Studio synthesize endpoint. --}}
@php
    $benchTuning = is_array($voice->settings) ? $voice->settings : [];
    $benchNative = \App\Services\Tts\ChatterboxTuning::resolveNative($benchTuning);
    $benchExag = isset($benchTuning['exaggeration']) || isset($benchTuning['style']) ? $benchNative['exaggeration'] : '';
    $benchCfg = isset($benchTuning['cfg_weight']) || isset($benchTuning['stability']) ? $benchNative['cfg_weight'] : '';
@endphp
<div class="tuning-bench mt-8 max-w-2xl rounded-xl border border-zinc-800 bg-zinc-900/50 p-6"
     data-synthesize-url="{{ route('admin.studio.synthesize') }}"
     data-voice-defaults-url="{{ route('admin.studio.voice-defaults') }}"
     data-voice="{{ $voice->slug }}"
     data-exaggeration="{{ $benchExag }}"
     data-cfg="{{ $benchCfg }}">
    <h2 class="font-semibold">Tune by ear</h2>
    <p class="mt-1 text-sm text-zinc-400">
        Hear {{ $voice->name }} read the sample line at different settings, pick the best take, and save it
        as the voice's defaults. Higher exaggeration = more animated delivery; lower CFG/Pace = quicker,
        looser pacing. Row one starts at the voice's current defaults.
    </p>

    <label class="mt-4 block">
        <span class="mb-1.5 block text-sm font-medium">Sample line</span>
        <textarea rows="2"
                  class="bench-text w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">Welcome back to the show. Today we're trying something new — and honestly? I can't wait.</textarea>
    </label>

    {{-- Named presets: bookmarks of knob values, reusable on any voice's bench.
         Click a name to add a pre-filled row; ✕ deletes. --}}
    <div class="bench-presets mt-3 flex flex-wrap items-center gap-2"
         data-store-url="{{ route('admin.studio.presets.store') }}">
        <span class="text-xs text-zinc-500" title="A preset is a named pair of knob values — it changes nothing until you apply it and save">Presets:</span>
        @foreach($presets as $preset)
            <span class="bench-preset inline-flex items-center gap-1 rounded-full border border-zinc-700 bg-zinc-800 py-0.5 pl-2.5 pr-1.5 text-xs"
                  data-id="{{ $preset->id }}" data-exaggeration="{{ $preset->exaggeration }}" data-cfg="{{ $preset->cfg_weight }}">
                <button type="button" class="preset-apply text-zinc-200 hover:text-cyan-300">{{ $preset->name }}</button>
                <button type="button" class="preset-delete text-zinc-500 hover:text-red-300" title="Delete preset" aria-label="Delete preset">✕</button>
            </span>
        @endforeach
        <span class="bench-preset-empty text-xs text-zinc-600 @unless($presets->isEmpty()) hidden @endunless">none yet</span>
        <button type="button"
                class="bench-preset-save rounded-full border border-zinc-700 px-2.5 py-0.5 text-xs text-zinc-400 hover:bg-zinc-800"
                title="Bookmark the picked row's values under a name, to reuse on any voice">＋ Save pick as preset</button>
    </div>

    <ol class="bench-rows mt-3 space-y-2"></ol>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" class="bench-add rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">+ Add setting</button>
        <button type="button" class="bench-generate rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">▶ Generate all</button>
        <button type="button" class="bench-save rounded-lg border border-emerald-700/50 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300 hover:bg-emerald-500/20">Save pick as voice defaults</button>
    </div>
    <p class="mt-2 text-xs text-zinc-500">
        Defaults apply when a request doesn't set its own — future API calls and new projects.
        Existing projects keep the settings they were created with. Each ▶ spends a real generation.
    </p>
    <div class="bench-status mt-2 text-sm text-zinc-400" role="status" aria-live="polite"></div>
</div>
