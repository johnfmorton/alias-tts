{{-- Step 2 on the Edit page: "what the voice is made from" — the reference clip
     and the engine, one decision. The card collapses to a summary row when it
     isn't what you came for (a saved voice's source rarely is). Replacing the
     clip and changing the engine both sit behind deliberate gates: the first
     because the recording flow is long, the second because it changes results
     dramatically AND swaps step 3's controls. --}}
@php
    use App\Services\Tts\ModelCatalog;

    $models = ModelCatalog::all();
    $presetEngines = collect($models)->filter(fn ($entry) => ($entry['preset_voices'] ?? []) !== [])->keys();
    $currentPreset = old('preset_voice', $voice->settings['preset_voice'] ?? '');
    $hasClip = (bool) $voice->reference_audio_path;
    $selectClass = 'w-full rounded-[9px] border border-edge bg-inset px-3.5 py-3 text-[15px] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
    // A voice with no clip yet has nothing to collapse behind — open it.
    $collapsed = $hasClip && ! $errors->any();
@endphp

<x-voice.step step="source" number="2" title="Voice source" hint="what the voice is made from"
              state="done" :collapsed="$collapsed">
    <x-slot:summary>
        @if($hasClip)
            <span class="aplayer aplayer--take shrink-0" data-clip-current>
                <button type="button" class="aplayer__btn" aria-label="Play the reference clip"><span class="aplayer__icon"></span></button>
                <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                <span class="aplayer__time">0:00 / 0:00</span>
                <audio class="aplayer__native" preload="none" src="{{ route('admin.voices.clip', $voice) }}"></audio>
            </span>
            <span class="font-mono text-[13px] text-zinc-500">{{ $clipLine }}</span>
        @else
            <span class="text-[13px] text-zinc-500">no reference clip</span>
        @endif
        <span class="text-[13.5px] text-zinc-400">Engine: <strong class="font-semibold text-zinc-200" data-engine-label>{{ $engineLabel }}</strong></span>
    </x-slot:summary>

    {{-- The stored clip, playable and inspectable. Everything in the meta line
         is read from the file's own header — no claimed loudness we didn't measure. --}}
    @if($hasClip)
        <div data-current-clip class="mb-3.5 flex flex-wrap items-center gap-4 rounded-[12px] border border-ok/30 bg-inset px-[18px] py-3.5">
            <span class="aplayer aplayer--chunk min-w-[220px] flex-1">
                <button type="button" class="aplayer__btn" aria-label="Play the reference clip"><span class="aplayer__icon"></span></button>
                <span class="aplayer__track"><span class="aplayer__fill"></span><span class="aplayer__knob"></span></span>
                <span class="aplayer__time">0:00 / 0:00</span>
                <audio class="aplayer__native" preload="metadata" src="{{ route('admin.voices.clip', $voice) }}"></audio>
            </span>
            <div class="text-right">
                <div class="text-[13.5px] font-semibold text-zinc-200">Current reference clip</div>
                <div class="font-mono text-xs text-zinc-500">{{ $clipLine }}</div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" data-disclosure-toggle="replace-clip"
                        data-open-label="Replace ▾" data-close-label="Cancel replace ▴"
                        class="rounded-[8px] bg-accent px-3.5 py-2 text-[13px] font-semibold text-accent-on transition hover:brightness-110">Replace ▾</button>
                <a href="{{ route('admin.voices.clip', [$voice, 'download' => 1]) }}" download
                   aria-label="Download the reference clip" title="Download the reference clip"
                   class="rounded-[8px] border border-edge px-3 py-2 text-[13px] text-zinc-300 transition hover:bg-white/5">↓</a>
                {{-- Removing is what lets a built-in voice actually be heard: a
                     stored clip always wins at render time. Like everything else
                     here it's a PENDING change until the save bar is used. --}}
                <button type="button" data-remove-clip
                        class="rounded-[8px] border border-edge px-3 py-2 text-[13px] text-zinc-300 transition hover:border-bad/50 hover:text-bad">Remove</button>
            </div>
        </div>
        <input type="hidden" name="remove_clip" value="0" data-remove-clip-field
               data-dirty-group="reference clip" data-dirty-value="clip removed">
        <p data-clip-help class="mb-[18px] text-[12.5px] leading-relaxed text-zinc-500">Replace opens Record / Upload — recording tips appear there, not before. Cleanup runs on the new clip; you preview before it saves.</p>

        {{-- Stands in for the card once removal is pending; filled by initVoiceFlow(). --}}
        <div data-remove-clip-note class="mb-[18px] hidden flex-wrap items-center gap-3 rounded-[12px] border border-warn/30 bg-warn/[0.05] px-[18px] py-3.5">
            <p class="min-w-0 flex-1 text-[13px] leading-relaxed text-zinc-300" data-remove-clip-text></p>
            <button type="button" data-keep-clip
                    class="shrink-0 rounded-[8px] border border-edge px-3.5 py-[7px] text-[13px] text-zinc-300 transition hover:bg-white/5">Keep the clip</button>
        </div>
    @endif

    {{-- Record / Upload. Hidden behind Replace while a clip already exists. --}}
    <div data-disclosure="replace-clip" @class(['mb-[18px]', 'hidden' => $hasClip])>
        @include('admin.voices._clip_source', [
            'replace' => true,
            'fileHelp' => 'Leave empty to keep the current clip ('.($hasClip ? 'present' : 'none').').',
        ])
    </div>

    {{-- Engine, behind a gate: rare to change, dramatic when you do. --}}
    <div class="flex flex-wrap items-center gap-3.5 rounded-[12px] border border-white/9 bg-inset px-[18px] py-3.5">
        <div class="min-w-0 flex-1 text-[13.5px] text-zinc-400">
            <span class="font-semibold text-zinc-300">Engine:</span>
            <span class="text-zinc-200" data-engine-label>{{ $engineLabel }}</span>
            <span class="text-[12.5px] text-zinc-500" data-engine-source-note>· {{ $hasClip ? 'clones from the clip above' : 'no clip — speaks through a built-in voice' }}</span>
        </div>
        <button type="button" data-engine-gate
                class="shrink-0 rounded-[8px] border border-edge px-3.5 py-[7px] text-[13px] text-zinc-300 transition hover:bg-white/5">Change engine…</button>
    </div>
    <p class="mt-2 text-xs leading-relaxed text-zinc-500">Changing the engine dramatically changes results — each has its own knobs and per-character rate. Your {{ $engineLabel }} tuning won't carry over.</p>

    {{-- The picker itself, revealed only after the gate is acknowledged. It
         stays in the DOM either way so initVoiceEngineToggle() can drive the
         engine-scoped controls from it. --}}
    <div data-engine-picker class="mt-4 hidden grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
            <label for="voice-model" class="mb-2 block text-[13px] font-semibold text-zinc-300">Model</label>
            <select id="voice-model" name="model" data-dirty-group="engine" data-rail-source="source" class="{{ $selectClass }}">
                @foreach($models as $key => $entry)
                    <option value="{{ $key }}" @selected($engineModel === $key)>{{ $entry['label'] ?? $key }}</option>
                @endforeach
            </select>
            <p class="mt-2 text-[12.5px] leading-relaxed text-zinc-500">Chatterbox is the expressive classic; Turbo is faster, supports sound tags like [laugh], and offers built-in voices; Qwen3 TTS speaks ten languages, offers built-in voices, and takes a free-text style note.</p>
        </div>
        <div data-engine-only="{{ $presetEngines->implode(' ') }}" @class(['hidden' => ! $presetEngines->contains($engineModel)])>
            <label for="preset-voice" class="mb-2 block text-[13px] font-semibold text-zinc-300">Built-in voice <span class="font-normal text-zinc-500">(used when there's no reference clip)</span></label>
            <select id="preset-voice" name="preset_voice" data-dirty-group="built-in voice" class="{{ $selectClass }}">
                <option value="">— none, use the reference clip —</option>
                @foreach($presetEngines as $engineKey)
                    @foreach(ModelCatalog::presetVoices($engineKey) as $preset)
                        <option value="{{ $preset }}" data-model="{{ $engineKey }}"
                                @selected($engineModel === $engineKey && $currentPreset === $preset)
                                @class(['hidden' => $engineModel !== $engineKey])>{{ $preset }}</option>
                    @endforeach
                @endforeach
            </select>
            <p data-clip-path-hint data-engine-only="chatterbox-turbo" @class(['mt-2 text-[12.5px] leading-relaxed text-zinc-500', 'hidden' => $engineModel !== 'chatterbox-turbo'])>Turbo needs a reference clip <strong>longer than 5 seconds</strong> — or one of these built-ins instead of a clip.</p>
            <p data-clip-path-hint data-engine-only="qwen3-tts" @class(['mt-2 text-[12.5px] leading-relaxed text-zinc-500', 'hidden' => $engineModel !== 'qwen3-tts'])>Qwen clones from a reference clip of <strong>at least 3 seconds</strong> (aim for 15–20s) — or speaks through one of these built-ins instead.</p>
        </div>
    </div>

    {{-- Qwen's voice_clone mode reads better when it knows what the clip says —
         the transcript describes the SOURCE, so it belongs to this step. --}}
    {{-- Two wrappers, deliberately: the outer one is owned by the engine toggle,
         the inner by pending removal. Sharing a node would have them fight over
         the same `hidden` class. --}}
    <div data-engine-only="qwen3-tts" @class(['mt-5', 'hidden' => $engineModel !== 'qwen3-tts'])>
      <div data-clip-transcript>
        <label for="reference-text" class="mb-2 block text-[13px] font-semibold text-zinc-300">Clip transcript <span class="font-normal text-zinc-500">(optional, improves the clone)</span></label>
        <textarea id="reference-text" name="reference_text" rows="3" maxlength="2000"
                  data-dirty-group="clip transcript"
                  placeholder="Type exactly what's said in the reference clip…"
                  class="{{ $inputClass }}">{{ old('reference_text', $voice->settings['reference_text'] ?? '') }}</textarea>
        <p class="mt-2 text-[12.5px] leading-relaxed text-zinc-500">Qwen3 TTS clones more faithfully when it can read along with the clip. Leave blank to let it listen unaided.</p>
      </div>
    </div>
</x-voice.step>
