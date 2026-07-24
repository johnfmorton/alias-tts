{{-- Step 3 on the Edit page: "how it speaks by default". The rail never changes,
     but this card's contents belong to the ENGINE — Chatterbox-family engines get
     numeric knobs set through the takes table, Qwen3 gets a language and a
     plain-words style note. Either way the pick lands in this step's delivery
     fields and rides the page's one save bar.

     The bench is built for the voice's SAVED engine. Choosing a different engine
     in step 2 swaps the controls, but only after that change is saved — until
     then the pending notice stands in. --}}
@php
    use App\Services\Tts\ModelCatalog;
    use App\Services\Tts\Qwen3TtsTuning;

    $savedEngine = ModelCatalog::forVoice($voice);
    $savedLabel = ModelCatalog::label($savedEngine);
    $isQwen = $savedEngine === 'qwen3-tts';
    $tuning = is_array($voice->settings) ? $voice->settings : [];
    // Only the saved engine's knobs are submitted — a stale value from another
    // engine would otherwise ride along invisibly in the settings JSON.
    $knobs = match ($savedEngine) {
        'chatterbox-turbo' => ['top_p' => $topPValue, 'top_k' => $topKValue, 'repetition_penalty' => $repPenaltyValue, 'temperature' => $temperatureValue],
        'qwen3-tts' => [],
        default => ['exaggeration' => $exagValue, 'cfg_weight' => $cfgValue, 'temperature' => $temperatureValue],
    };
@endphp

<x-voice.step step="delivery" number="3" title="Delivery defaults"
              hint="{{ $savedLabel }} tuning · used when a request doesn't set its own"
              state="current">

    <div data-delivery-for="{{ $savedEngine }}">
        @if($isQwen)
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-[220px_minmax(0,1fr)]">
                <div>
                    <label for="voice-language" class="mb-2 block text-[13px] font-semibold text-zinc-300">Language</label>
                    <select id="voice-language" name="language" data-dirty-group="language"
                            data-delivery-field="language" class="{{ $inputClass }}">
                        @foreach(Qwen3TtsTuning::LANGUAGES as $lang)
                            <option value="{{ $lang }}" @selected(old('language', $tuning['language'] ?? 'auto') === $lang)>{{ $lang === 'auto' ? 'Auto-detect' : $lang }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="voice-style-instruction" class="mb-2 block text-[13px] font-semibold text-zinc-300">Style note <span class="font-normal text-zinc-500">(free text)</span></label>
                    <input id="voice-style-instruction" name="style_instruction" maxlength="{{ Qwen3TtsTuning::STYLE_INSTRUCTION_MAX }}"
                           value="{{ old('style_instruction', $tuning['style_instruction'] ?? '') }}"
                           data-dirty-group="style note" data-delivery-field="style_instruction"
                           placeholder="e.g. speak slowly and calmly" class="{{ $inputClass }}">
                </div>
            </div>
            <p class="mb-4 text-[12.5px] leading-relaxed text-zinc-500">Auto-detect handles mixed or unknown text; the style note steers delivery in plain words. Qwen has no numeric knobs.</p>

            {{-- Audition the settings as they stand, then the same compare-takes
                 loop the numeric engines use — rows are style notes here. --}}
            <div class="flex flex-wrap items-center gap-3 rounded-[10px] border border-white/9 bg-inset px-3.5 py-2.5">
                <button type="button" data-bench-audition
                        class="grid h-8 w-8 shrink-0 place-items-center rounded-full border border-accent/50 text-accent transition hover:bg-accent/10"
                        aria-label="Audition this style note">▶</button>
                <span class="text-[13.5px] text-zinc-300">Audition this style note</span>
                <span class="text-[12.5px] text-zinc-500">— reads the sample line with the settings above · spends one generation</span>
                <button type="button" data-disclosure-toggle="compare-takes"
                        data-open-label="Compare takes ▾" data-close-label="Hide takes ▴"
                        class="ml-auto shrink-0 rounded-[8px] border border-edge px-3.5 py-[7px] text-[13px] text-zinc-300 transition hover:bg-white/5">Compare takes ▾</button>
            </div>
            <div data-disclosure="compare-takes" class="mt-4 hidden">
                @include('admin.voices._tuning_bench', ['voice' => $voice, 'presets' => $presets])
            </div>
        @else
            <p class="mb-[18px] text-[13px] leading-[1.55] text-zinc-400">Set these by ear: each row is a candidate default. Row one is what's saved now. Generate, listen, pick — your pick becomes the new defaults when you save.</p>

            {{-- The submitted values. The takes table writes the picked row here;
                 nothing else on this card is a form field. --}}
            @foreach($knobs as $param => $value)
                <input type="hidden" name="{{ $param }}" value="{{ $value }}"
                       data-delivery-field="{{ $param }}" data-dirty-group="delivery defaults">
            @endforeach

            @include('admin.voices._tuning_bench', ['voice' => $voice, 'presets' => $presets])
        @endif
    </div>

    {{-- Shown by initVoiceFlow() while step 2 holds an unsaved engine change. --}}
    <p data-delivery-pending class="hidden rounded-[10px] border border-warn/30 bg-warn/[0.05] px-4 py-3.5 text-[13px] leading-relaxed text-zinc-300">
        Save the engine change first. Each engine has its own controls, so
        <span data-delivery-pending-label>the new engine</span>'s appear here once this voice is saved on it —
        your {{ $savedLabel }} tuning won't carry over.
    </p>
</x-voice.step>
