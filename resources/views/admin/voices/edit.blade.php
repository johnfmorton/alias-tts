@php
    use App\Services\Tts\ChatterboxTuning;
    use App\Services\Tts\ModelCatalog;

    // The form speaks Chatterbox's native knobs. A voice saved before v0.15.0 may
    // still carry the ElevenLabs-style pair — show its native equivalent (saving
    // rewrites it in native form); show blank when the voice has no default for
    // that knob at all.
    $tuning = is_array($voice->settings) ? $voice->settings : [];
    $native = ChatterboxTuning::resolveNative($tuning);
    $exagValue = isset($tuning['exaggeration']) || isset($tuning['style']) ? $native['exaggeration'] : '';
    $cfgValue = isset($tuning['cfg_weight']) || isset($tuning['stability']) ? $native['cfg_weight'] : '';
    // Temperature is native-only (no ElevenLabs twin); blank when unset.
    $temperatureValue = isset($tuning['temperature'])
        ? ChatterboxTuning::clampTemperature((float) $tuning['temperature'])
        : '';
    // Turbo's sampling knobs (blank = the model's own defaults).
    $topPValue = $tuning['top_p'] ?? '';
    $topKValue = $tuning['top_k'] ?? '';
    $repPenaltyValue = $tuning['repetition_penalty'] ?? '';

    $engineModel = old('model', ModelCatalog::forVoice($voice));
    $engineLabel = ModelCatalog::label($engineModel);
    $presetVoice = old('preset_voice', $tuning['preset_voice'] ?? '');
    $isQwen = $engineModel === 'qwen3-tts';

    // Everything said about the stored clip is read from its own header
    // (VoiceController::clipMeta) — duration, channels, rate, container. We
    // never claim a loudness we didn't measure.
    $clipSeconds = $clipMeta['seconds'] ?? null;
    $clipFacts = array_values(array_filter([
        $clipSeconds ? rtrim(rtrim(number_format($clipSeconds, 1), '0'), '.').'s' : null,
        match ($clipMeta['channels'] ?? 0) { 1 => 'mono', 0 => null, default => 'stereo' },
        ($clipMeta['sample_rate'] ?? 0) > 0
            ? rtrim(rtrim(number_format($clipMeta['sample_rate'] / 1000, 1), '0'), '.').' kHz'
            : null,
        $clipMeta ? strtoupper($clipMeta['ext']) : null,
    ]));
    $clipLine = $clipFacts ? implode(' · ', $clipFacts) : 'stored clip';

    // Rail metas. Step 2 says what the voice is MADE FROM; step 3 what it
    // currently sounds like by default.
    // Same rounding as $clipLine, so the rail and the step never disagree by a
    // tenth of a second about the same file.
    $clipLength = $clipSeconds ? rtrim(rtrim(number_format($clipSeconds, 1), '0'), '.').'s' : null;
    $sourceMeta = $engineLabel.' · '.match (true) {
        (bool) $voice->reference_audio_path => $clipLength ? $clipLength.' clip' : 'reference clip',
        $presetVoice !== '' => 'built-in '.$presetVoice,
        default => 'no clip yet',
    };
    $knobTriple = array_values(array_filter(
        $engineModel === 'chatterbox-turbo'
            ? [$topPValue, $topKValue, $repPenaltyValue, $temperatureValue]
            : [$exagValue, $cfgValue, $temperatureValue],
        fn ($v) => $v !== '' && $v !== null,
    ));
    $deliveryMeta = $isQwen
        ? 'style note · language'
        : ($knobTriple ? implode(' / ', $knobTriple) : 'system defaults');

    $inputClass = 'w-full rounded-[9px] border border-edge bg-inset px-3.5 py-3 text-[15px] text-zinc-100 placeholder:text-zinc-600 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
@endphp
<x-layout title="Edit voice" :heading="false" contentWidth="max-w-[1100px]">
    {{-- One form, one Save. The step cards below are views onto it, never
         separate save paths — the takes table writes its pick into the hidden
         delivery fields and the save bar reports it like any other edit. --}}
    <form id="voice-form" method="POST" action="{{ route('admin.voices.update', $voice) }}" enctype="multipart/form-data"
          data-voice-flow="edit" data-dirty-guard
          data-busy data-busy-label="Saving changes…"
          data-busy-message="Cleaning up and normalizing a replacement clip can take up to a minute — keep this page open.">
        @csrf
        @method('PUT')

        <div class="mb-7">
            <h1 class="text-[26px] font-bold tracking-[-0.015em] text-zinc-100">Edit voice — {{ $voice->name }}</h1>
            <p class="mt-1.5 text-sm text-zinc-500">Changes apply when you save. Nothing is sent to the engines until then.</p>
        </div>

        {{-- Preserve any existing seed without surfacing it (a fixed seed doesn't
             guarantee an identical take). --}}
        <input type="hidden" name="seed" value="{{ old('seed', $voice->settings['seed'] ?? '') }}">

        <div class="grid grid-cols-1 items-start gap-9 pb-28 lg:grid-cols-[230px_minmax(0,1fr)]">
            <x-voice.rail
                :steps="[
                    ['key' => 'identity', 'number' => 1, 'name' => 'Identity', 'meta' => $voice->slug, 'state' => 'done'],
                    ['key' => 'source', 'number' => 2, 'name' => 'Voice source', 'meta' => $sourceMeta, 'state' => 'done'],
                    ['key' => 'delivery', 'number' => 3, 'name' => 'Delivery defaults', 'meta' => $deliveryMeta, 'state' => 'current'],
                ]"
                note="Step 3's controls belong to the engine. {{ $isQwen ? 'Qwen3 = language plus a plain-words style note, set by ear below.' : 'Chatterbox-family engines = numeric knobs, set by ear below.' }}" />

            <div class="flex flex-col gap-[22px]">
                {{-- ── 1 · Identity ─────────────────────────────────────────── --}}
                <x-voice.step step="identity" number="1" title="Identity" hint="what clients ask for" state="done">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="name" class="mb-2 block text-[13px] font-semibold text-zinc-300">Name</label>
                            <input id="name" name="name" value="{{ old('name', $voice->name) }}" required
                                   data-dirty-group="name" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="slug" class="mb-2 block text-[13px] font-semibold text-zinc-300">voice_id</label>
                            <input id="slug" name="slug" value="{{ old('slug', $voice->slug) }}" required
                                   data-dirty-group="voice_id" data-rail-source="identity" class="{{ $inputClass }} font-mono">
                        </div>
                    </div>
                    <p class="mt-3 text-[12.5px] leading-relaxed text-zinc-500">Used in the API path and by clients (e.g. the Bespoken plugin). Renaming it also moves the stored reference clip.</p>
                </x-voice.step>

                {{-- ── 2 · Voice source ─────────────────────────────────────── --}}
                @include('admin.voices._step_source', [
                    'voice' => $voice,
                    'engineModel' => $engineModel,
                    'engineLabel' => $engineLabel,
                    'clipLine' => $clipLine,
                    'inputClass' => $inputClass,
                ])

                {{-- ── 3 · Delivery defaults ────────────────────────────────── --}}
                @include('admin.voices._step_delivery', [
                    'voice' => $voice,
                    'presets' => $presets,
                    'engineModel' => $engineModel,
                    'engineLabel' => $engineLabel,
                    'inputClass' => $inputClass,
                    'exagValue' => $exagValue,
                    'cfgValue' => $cfgValue,
                    'temperatureValue' => $temperatureValue,
                    'topPValue' => $topPValue,
                    'topKValue' => $topKValue,
                    'repPenaltyValue' => $repPenaltyValue,
                ])
            </div>
        </div>

        <x-voice.save-bar />
    </form>
</x-layout>
