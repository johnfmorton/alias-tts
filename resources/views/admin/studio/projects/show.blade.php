<x-layout :title="$project->title" :heading="false">

    @php
        $chunkStyles = [
            'completed' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
            'stale'     => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
            'failed'    => 'border-red-500/30 bg-red-500/10 text-red-300',
            'pending'   => 'border-zinc-700 bg-zinc-800 text-zinc-400',
        ];
        $hasFinal = (bool) $project->final_audio_path;
        $statusVal = $project->status->value;
        $statusBadgeClass = $statusVal === 'ready' ? $chunkStyles['completed'] : ($statusVal === 'stale' ? $chunkStyles['stale'] : $chunkStyles['pending']);
    @endphp

    <div id="studio-project"
         data-has-final="{{ $hasFinal ? '1' : '0' }}"
         data-rebuild-url="{{ route('admin.studio.projects.rebuild', $project) }}"
         data-final-url="{{ route('admin.studio.projects.audio', $project) }}"
         data-preview-url="{{ route('admin.studio.projects.preview', $project) }}"
         data-rename-url="{{ route('admin.studio.projects.update', $project) }}"
         data-dismiss-url="{{ route('admin.studio.projects.dismiss-failure', $project) }}"
         data-voice-url="{{ route('admin.studio.projects.voice', $project) }}"
         data-format-url="{{ route('admin.studio.projects.format', $project) }}"
         data-insert-url="{{ route('admin.studio.projects.chunks.store', $project) }}"
         data-seal-url="{{ route('admin.studio.projects.seal', $project) }}"
         data-unseal-url="{{ route('admin.studio.projects.unseal', $project) }}"
         data-receipt-url="{{ route('admin.studio.projects.receipt', $project) }}"
         data-verify-base="{{ route('verify') }}"
         data-generate-pace-ms="{{ (int) config('tts.studio_generate_pace_ms', 800) }}">

        @if($project->origin === 'api_failure')
            <div id="project-failure-notice" class="mb-6 rounded-xl border border-red-500/30 bg-red-500/10 px-5 py-4 text-sm text-red-200">
                <div class="flex items-start justify-between gap-4">
                    <div class="font-medium text-red-300">Recovered from a failed API generation</div>
                    <button type="button" id="project-dismiss-failure"
                            title="Clear the failure flag and keep this as a regular project."
                            class="shrink-0 rounded-lg border border-red-500/30 px-2.5 py-1 text-xs text-red-300 hover:bg-red-500/10">Dismiss</button>
                </div>
                @if($project->failure_reason)
                    <p class="mt-1 text-red-200/90">The provider reported: <span class="font-mono text-xs">{{ $project->failure_reason }}</span></p>
                @endif
                @if(! is_null($project->failed_chunk_index))
                    <p class="mt-1 text-red-200/80">Generation failed at chunk #{{ $project->failed_chunk_index + 1 }} — edit that sentence, generate the chunks, and build the final.</p>
                @else
                    <p class="mt-1 text-red-200/80">Edit the text as needed, then generate the chunks and build the final.</p>
                @endif
            </div>
        @endif

        {{-- Sticky command bar — merges the old toolbar and the "Final audio" card into
             one pinned, two-row header so the final audio is always one tap away. --}}
        <div class="sticky top-0 z-30 -mx-4 mb-6 border-b border-white/[0.09] bg-sticky px-4 py-3.5 shadow-[0_12px_26px_-14px_rgba(0,0,0,0.85)]">
            {{-- Row 1: back · title (own line so it can wrap) · rename · voice · chunks · status --}}
            <div class="mb-3.5 flex flex-wrap items-center gap-3">
                <a href="{{ route('admin.studio.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">← Projects</a>
                <span id="project-title-label" class="text-lg font-bold tracking-[-0.2px] text-zinc-100">{{ $project->title }}</span>
                <button type="button" id="project-rename"
                        class="rounded-[7px] border border-white/12 px-2.5 py-[5px] text-xs text-zinc-400 hover:bg-white/[0.04] hover:text-zinc-200">Rename</button>
                <div id="project-rename-form" class="hidden w-full max-w-xl items-center gap-2">
                    <input type="text" id="project-title-input" value="{{ $project->title }}" maxlength="200"
                           class="min-w-0 flex-1 rounded-lg border border-white/12 bg-inset px-3 py-1.5 text-base text-zinc-100 focus:border-accent/50 focus:outline-none">
                    <button type="button" id="project-rename-save"
                            class="shrink-0 rounded-lg border border-accent/40 bg-accent/[0.08] px-3 py-1.5 text-sm text-accent hover:bg-accent/[0.14]">Save</button>
                    <button type="button" id="project-rename-cancel"
                            class="shrink-0 rounded-lg border border-white/12 px-3 py-1.5 text-sm text-zinc-400 hover:bg-white/[0.04]">Cancel</button>
                </div>
                <div class="ml-auto flex items-center gap-3">
                    <label class="flex items-center gap-2 text-xs text-zinc-500" title="Changing the voice marks generated chunks for regeneration.">
                        <span class="text-zinc-400">Voice</span>
                        <select id="project-voice"
                                class="rounded-[8px] border border-white/12 bg-inset px-2.5 py-1.5 text-sm text-zinc-200 focus:border-accent/50 focus:outline-none">
                            @foreach($voices as $v)
                                <option value="{{ $v->slug }}" @selected($project->voice && $project->voice->id === $v->id)>{{ $v->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-xs text-zinc-500" title="Final audio format. MP3 is compressed; WAV is uncompressed (~10× larger). Changing it rebuilds the final in the new format.">
                        <span class="text-zinc-400">Format</span>
                        <select id="project-format"
                                class="rounded-[8px] border border-white/12 bg-inset px-2.5 py-1.5 text-sm text-zinc-200 focus:border-accent/50 focus:outline-none">
                            @foreach($outputFormats as $token => $optLabel)
                                <option value="{{ $token }}" @selected($project->output_format === $token)>{{ $optLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                    <span class="text-sm text-zinc-400">{{ $chunks->count() }} chunks</span>
                    @if(\App\Support\GenerationCost::enabled())
                        {{-- Lifetime estimate — counts every render ever, so deleting
                             takes/chunks never lowers it (see GenerationCost). --}}
                        <span id="project-spend" class="cursor-help text-sm text-zinc-500"
                              title="{{ \App\Support\GenerationCost::title($project->spent_characters, 'project') }}">est. spend {{ \App\Support\GenerationCost::label($project->spent_characters) }}</span>
                    @endif
                    <span id="project-status" class="inline-flex rounded-md border px-2 py-0.5 text-xs {{ $statusBadgeClass }}">{{ $statusVal }}</span>
                </div>
            </div>

            {{-- Row 2: hero transport (the final audio artifact) + state-aware actions (4B) --}}
            <div class="flex flex-wrap items-center gap-4">
                <div id="project-final-player" class="aplayer aplayer--hero min-w-[300px] flex-1 {{ $hasFinal ? '' : 'hidden' }}">
                    <button type="button" class="aplayer__btn" aria-label="Play or pause the final audio"><span class="aplayer__icon"></span></button>
                    <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                    <span class="aplayer__time">0:00 / 0:00</span>
                    <audio id="project-final-audio" class="aplayer__native" preload="metadata" @if($hasFinal) src="{{ route('admin.studio.projects.audio', $project) }}" @endif></audio>
                </div>
                @unless($hasFinal)
                    <div id="project-final-placeholder" class="min-w-[300px] flex-1 text-sm text-zinc-600">No final audio yet — generate the chunks, then build the final.</div>
                @endunless

                {{-- Action cluster. Looks (primary / outline / disabled) are set by
                     reflectActionState() in app.js from the current project state. --}}
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" id="project-generate-all" class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">▶ Generate remaining</button>
                    <button type="button" id="project-rebuild" class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">↻ Build final</button>
                    {{-- Draft download (bare final audio) — hidden once approved; the
                         approved-version package below supersedes it. --}}
                    <a id="project-download" href="{{ route('admin.studio.projects.audio', $project) }}" download class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">↓ Download draft version</a>
                    {{-- Approve ⇆ approved-download share one slot: "Approve as final" shows
                         until approved, then the approved-version download replaces it in
                         place as the primary action (toggled by reflectActionState). --}}
                    <button type="button" id="project-seal"
                            title="Approve this cut as the final deliverable and record who approved it. Editing the project afterward clears the approval."
                            class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">🔒 Approve as final</button>
                    <a id="project-receipt" href="{{ route('admin.studio.projects.receipt', $project) }}" download
                       title="Download the approved version (.zip): the final audio and a provenance report, with a link to verify the file online."
                       class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition {{ $project->isSealed() ? '' : 'hidden' }}">⤓ Download approved version</a>

                    {{-- Overflow: rare + destructive actions (design turn 3) --}}
                    <div class="relative">
                        <button type="button" id="project-overflow" aria-label="More actions"
                                class="grid h-[38px] w-[38px] place-items-center rounded-[9px] border border-white/14 text-lg text-zinc-300 hover:bg-white/[0.04]">⋯</button>
                        <div id="project-overflow-menu" class="absolute top-[44px] right-0 z-40 hidden w-56 rounded-[12px] border border-white/10 bg-menu p-1.5 shadow-[0_20px_40px_-12px_rgba(0,0,0,0.7)]">
                            {{-- Undo an approval made by mistake. Shown only while approved
                                 (block/hidden toggled together — never left both — by reflectSeal). --}}
                            <button type="button" id="project-unseal"
                                    title="Remove the approval so you can edit or re-approve. The audio is kept."
                                    class="w-full rounded-lg px-3 py-2 text-left text-sm text-zinc-300 hover:bg-white/[0.04] {{ $project->isSealed() ? 'block' : 'hidden' }}">↺ Unapprove</button>
                            <a href="{{ route('admin.studio.projects.edit', $project) }}"
                               class="block rounded-lg px-3 py-2 text-sm text-zinc-300 hover:bg-white/[0.04]">↺ Start over</a>
                            <form method="POST" action="{{ route('admin.studio.projects.duplicate', $project) }}" id="project-duplicate-form">
                                @csrf
                                <button type="submit" id="project-duplicate"
                                        title="Make an independent copy of this project — its own text, chunks, and audio. Changes to either project never affect the other."
                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm text-zinc-300 hover:bg-white/[0.04]">⧉ Duplicate project</button>
                            </form>
                            <form method="POST" action="{{ route('admin.studio.projects.destroy', $project) }}"
                                  onsubmit="return confirm('Delete this project and all its audio?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-bad hover:bg-white/[0.04]">Delete project</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Approved-final badge + status line (toggled in JS; see initStudioProject) --}}
            <div id="project-seal-badge" data-sha256="{{ $project->final_sha256 }}"
                 class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-ok/30 bg-ok/10 px-3 py-2 text-sm text-ok {{ $project->isSealed() ? '' : 'hidden' }}">
                <span class="font-medium">✓ Approved final<span id="project-seal-approver">{{ $project->isSealed() ? ' — approved by '.$project->sealApprover() : '' }}</span><span id="project-seal-when">{{ $project->isSealed() ? ' · '.optional($project->sealed_at)->toDayDateTimeString() : '' }}</span></span>
                <span id="project-seal-hash" class="font-mono text-xs text-ok/80">{{ $project->isSealed() ? substr((string) $project->final_sha256, 0, 12) : '' }}</span>
                <button type="button" id="project-seal-copy"
                        class="rounded-md border border-ok/50 px-2 py-0.5 text-xs text-ok hover:bg-ok/20">Copy verify link</button>
            </div>
            <div id="project-final-status" class="mt-2 text-sm text-zinc-400" role="status" aria-live="polite"></div>
        </div>

        @if(config('tts.asr.enabled'))
            {{-- Explain the per-chunk QA badges below (the acronym is otherwise unexplained on the page). --}}
            <p class="mb-3 text-xs leading-relaxed text-zinc-500">
                <span class="font-medium text-zinc-400">QA</span> (quality assurance) checks each generated chunk by
                transcribing it with speech recognition and comparing it back to the script. A badge flags a possible
                truncation, a junk or loud tail (<span class="font-mono">TAIL</span>/<span class="font-mono">TAILNOISE</span>),
                or a mid-stream pause or boundary hum (<span class="font-mono">PAUSE</span>/<span class="font-mono">BNDNOISE</span>) —
                hover a badge for its measurements. <span class="text-emerald-400">QA&nbsp;✓</span> means it passed.
            </p>
        @endif

        {{-- Chunks, with an inline "Preview stitch" connector between any two adjacent GENERATED chunks --}}
        <div class="space-y-3">
            {{-- Insert a new (empty) chunk at this gap. Always available, unlike the seam. --}}
            <div class="chunk-insert flex justify-center" data-position="0">
                <button type="button" class="rounded-full border border-zinc-800 px-3 py-0.5 text-xs text-zinc-600 hover:border-zinc-600 hover:text-zinc-300">+ insert chunk</button>
            </div>

            @php
                // What a blank per-chunk knob falls back to: the project's resolved
                // tuning (stored at creation) mapped to Chatterbox's native knobs via
                // the same formula the provider uses, so the inherited value shown is
                // exactly what would render. Native keys win if the project stores them.
                $inheritNative = \App\Services\Tts\ChatterboxTuning::resolveNative(is_array($project->settings) ? $project->settings : []);
                $inheritExaggeration = number_format($inheritNative['exaggeration'], 2);
                $inheritCfg = number_format($inheritNative['cfg_weight'], 2);
                // Temperature is native-only (no EL twin), so read it straight from the
                // project snapshot, falling back to the system default for pre-existing
                // projects that predate the temperature knob.
                $inheritTemperature = number_format((float) (($project->settings['temperature'] ?? null)
                    ?? config('tts.default_voice_settings.temperature', 0.8)), 2);
                // Seed the chunk field inherits: the project's pinned seed, or blank
                // (a random draw) when the project isn't pinned.
                $inheritSeedText = $project->seed ? (string) $project->seed : 'random';
            @endphp
            @foreach($chunks as $chunk)
                <div class="studio-chunk rounded-xl border border-zinc-800 bg-zinc-900/50 p-4"
                     data-chunk-id="{{ $chunk->id }}"
                     data-generate-url="{{ route('admin.studio.projects.chunks.generate', [$project, $chunk]) }}"
                     data-patch-url="{{ route('admin.studio.projects.chunks.update', [$project, $chunk]) }}"
                     data-tuning-url="{{ route('admin.studio.projects.chunks.tuning', [$project, $chunk]) }}"
                     data-reroll-url="{{ route('admin.studio.projects.chunks.reroll', [$project, $chunk]) }}"
                     data-preview-tuning-url="{{ route('admin.studio.projects.chunks.preview-tuning', [$project, $chunk]) }}"
                     data-use-preview-url="{{ route('admin.studio.projects.chunks.use-preview', [$project, $chunk]) }}"
                     data-delete-url="{{ route('admin.studio.projects.chunks.destroy', [$project, $chunk]) }}"
                     data-audio-url="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}"
                     data-takes-url="{{ route('admin.studio.projects.chunks.takes.index', [$project, $chunk]) }}"
                     data-takes='@json($takesByChunk[$chunk->id])'>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-sm text-zinc-400">
                            <span class="font-mono text-zinc-300">#{{ $chunk->position + 1 }}</span>
                            <span class="chunk-chars">{{ $chunk->characters }} chars</span>
                            @if(\App\Support\GenerationCost::enabled())
                                {{-- This chunk's lifetime render spend; hidden until the first
                                     take. JS toggles ONLY `hidden` (no competing display class). --}}
                                <span class="chunk-spend cursor-help text-zinc-500 {{ $chunk->spent_characters > 0 ? '' : 'hidden' }}"
                                      title="{{ \App\Support\GenerationCost::title($chunk->spent_characters, 'chunk') }}">{{ \App\Support\GenerationCost::label($chunk->spent_characters) }}</span>
                            @endif
                            <span class="chunk-status inline-flex rounded-md border px-2 py-0.5 text-xs {{ $chunkStyles[$chunk->status->value] ?? $chunkStyles['pending'] }}">{{ $chunk->status->value }}</span>
                            @php $asrBadge = $chunk->asrBadge(); @endphp
                            {{-- ASR transcript-QA verdict; only when the chunk's current audio was scored. --}}
                            <span class="chunk-asr-badge {{ $asrBadge ? 'inline-flex rounded-md border px-2 py-0.5 text-xs '.($asrBadge['tone'] === 'ok' ? $chunkStyles['completed'] : $chunkStyles['failed']) : 'hidden' }}"
                                  @if($asrBadge) title="{{ $asrBadge['title'] }}" @endif>{{ $asrBadge['text'] ?? '' }}</span>
                            <span class="chunk-dirty hidden rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">● unsaved</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-1.5 text-xs text-zinc-500">
                                <span class="text-zinc-400">Voice</span>
                                <select class="chunk-voice rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                                        data-voice-url="{{ route('admin.studio.projects.chunks.voice', [$project, $chunk]) }}"
                                        data-inherits="{{ $chunk->voice_id ? '0' : '1' }}"
                                        title="Voice for this chunk. Follows the project voice until you pick one here.">
                                    @foreach($voices as $v)
                                        <option value="{{ $v->slug }}" @selected(($chunk->voice_id ?? $project->voice_id) === $v->id)>{{ $v->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="button" class="chunk-revert hidden rounded-lg border border-zinc-700 px-3 py-1.5 text-sm text-zinc-400 hover:bg-zinc-800">Revert</button>
                            {{-- Save applies only to unsaved edits (disabled when clean); Regenerate
                                 renders the SAVED text, so it's disabled while the text is dirty.
                                 initStudioProject keeps both in sync as the user types. --}}
                            <button type="button" class="chunk-save rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent" disabled>Save text</button>
                            <button type="button" class="chunk-generate rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent"
                                    title="Render this chunk's audio from its current text and tuning.">▶ {{ $chunk->isCompleted() ? 'Regenerate' : 'Generate' }}</button>
                            @if($chunks->count() > 1)
                                {{-- Delete this chunk (two-step inline confirm). Hidden entirely for a
                                     one-chunk project — a project needs at least one chunk. --}}
                                <button type="button" class="chunk-delete rounded-lg border border-zinc-700 px-2.5 py-1.5 text-sm text-zinc-500 hover:border-red-700/60 hover:text-red-300"
                                        title="Delete this chunk.">🗑</button>
                                <span class="chunk-delete-confirm hidden items-center gap-1.5">
                                    <span class="text-xs text-red-300">Delete chunk?</span>
                                    <button type="button" class="chunk-delete-yes rounded-lg border border-red-700/60 bg-red-500/10 px-3 py-1.5 text-sm text-red-300 hover:bg-red-500/20">Confirm</button>
                                    <button type="button" class="chunk-delete-no rounded-lg border border-zinc-700 px-3 py-1.5 text-sm text-zinc-400 hover:bg-zinc-800">Cancel</button>
                                </span>
                            @endif
                        </div>
                    </div>

                    <textarea class="chunk-text mt-2 w-full rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-2 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                              rows="2" data-original="{{ $chunk->text }}">{{ $chunk->text }}</textarea>

                    <div class="aplayer aplayer--chunk mt-3 rounded-[12px] border border-white/8 bg-inset px-3.5 py-2.5 {{ $chunk->isCompleted() ? '' : 'hidden' }}">
                        <button type="button" class="aplayer__btn" aria-label="Play chunk audio"><span class="aplayer__icon"></span></button>
                        <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                        <span class="aplayer__time">0:00 / 0:00</span>
                        <audio class="chunk-audio aplayer__native" preload="metadata"
                               @if($chunk->isCompleted()) src="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}" @endif></audio>
                    </div>

                    {{-- Take history + per-chunk tuning override + re-roll (a fresh random take). --}}
                    <details class="chunk-tune mt-3 text-sm text-zinc-400" @if(!empty($chunk->settings) || $chunk->takes->count() > 1) open @endif>
                        <summary class="cursor-pointer select-none text-xs hover:text-zinc-200">Takes &amp; tuning</summary>

                        {{-- Every render is kept here — audition a prior take, re-select the
                             one that sounded best, or delete the duds. Populated by the JS from
                             data-takes (and refreshed after each render). --}}
                        <ul class="chunk-takes mt-2 space-y-1.5"></ul>

                        {{-- Row 1: the tuning controls — three native knobs + the seed pin.
                             Row 2 (below) carries the actions, so four controls never fight
                             the buttons for one line. --}}
                        <div class="mt-3 flex flex-wrap items-end gap-3">
                            @if($presets->isNotEmpty())
                                {{-- Fills the knobs below with a named preset's values;
                                     nothing is saved until "Save tuning" / a preview is kept. --}}
                                <label class="flex flex-col gap-1 text-xs text-zinc-500">Preset
                                    <select class="chunk-preset rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-300">
                                        <option value="" selected>Apply…</option>
                                        @foreach($presets as $preset)
                                            <option value="{{ $preset->id }}" data-exaggeration="{{ $preset->exaggeration }}" data-cfg="{{ $preset->cfg_weight }}" data-temperature="{{ $preset->temperature }}">{{ $preset->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            <x-tuning-knob knob="exaggeration" label="Exaggeration" hint="neutral 0.5"
                                           :min="0.25" :max="2" :step="0.05"
                                           :value="$chunk->settings['exaggeration'] ?? ''" :placeholder="$inheritExaggeration"
                                           inputClass="chunk-exaggeration" class="w-44" />
                            <x-tuning-knob knob="cfg_weight" label="CFG / Pace"
                                           :min="0.2" :max="1" :step="0.05"
                                           :value="$chunk->settings['cfg_weight'] ?? ''" :placeholder="$inheritCfg"
                                           inputClass="chunk-cfg" class="w-44" />
                            <x-tuning-knob knob="temperature" label="Temperature" hint="neutral 0.8"
                                           :min="0.5" :max="1.5" :step="0.05"
                                           :value="$chunk->settings['temperature'] ?? ''" :placeholder="$inheritTemperature"
                                           inputClass="chunk-temperature" class="w-44" />
                            {{-- Seed pin — not a knob (integer, no slider, no neutral value).
                                 Blank inherits the project seed (or rolls random). A pin only
                                 biases the draw; Chatterbox is not bit-reproducible even so. --}}
                            <div class="flex min-w-[9rem] flex-col gap-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs text-zinc-500">Seed <span class="text-zinc-600">blank = random</span></span>
                                </div>
                                <span class="flex items-center gap-1.5">
                                    <input type="number" min="0" step="1"
                                           value="{{ $chunk->settings['seed'] ?? '' }}" placeholder="{{ $inheritSeedText }}"
                                           class="chunk-seed w-full rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1 text-right text-sm tabular-nums">
                                    <button type="button" class="chunk-seed-random rounded-lg border border-zinc-700 px-1.5 py-1 text-xs text-zinc-400 hover:bg-zinc-800"
                                            title="Clear to a random seed" aria-label="Clear to a random seed">🎲</button>
                                </span>
                                <div class="text-[10px] text-zinc-600">pins the draw, not the result</div>
                            </div>
                        </div>

                        {{-- Row 2: actions on their own line. --}}
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button type="button" class="chunk-tune-preview rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">▶ Preview</button>
                            <button type="button" class="chunk-tune-keep hidden rounded-lg border border-emerald-600/50 bg-emerald-500/10 px-3 py-1.5 text-sm text-emerald-300 hover:bg-emerald-500/20"
                                    title="Save the exact clip you just previewed as this chunk's audio, with these settings. No re-generation, so it sounds identical to the preview.">✓ Use this take</button>
                            <button type="button" class="chunk-tune-save rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">Save tuning</button>
                            <button type="button" class="chunk-reroll rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800"
                                    title="Another take of the same text and tuning — use it when the words and settings are right but you want a different delivery.">⟳ Re-roll</button>
                        </div>
                        <div class="aplayer aplayer--chunk chunk-tune-player mt-2 hidden rounded-[12px] border border-white/8 bg-inset px-3.5 py-2.5">
                            <button type="button" class="aplayer__btn" aria-label="Play tuning preview"><span class="aplayer__icon"></span></button>
                            <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                            <span class="aplayer__time">0:00 / 0:00</span>
                            <audio class="chunk-tune-audio aplayer__native"></audio>
                        </div>
                        <p class="mt-1.5 text-xs text-zinc-500">Every render is kept in the list above — play any take, <span class="text-zinc-400">Select</span> the one that sounded best, or <span class="text-zinc-400">Delete</span> the duds (older takes are pruned automatically). Blank inherits the project's setting (the value shown in each field). <span class="text-zinc-400">Temperature</span> is sampling randomness — lower is flatter and steadier, higher is livelier but less predictable. <span class="text-zinc-400">Seed</span> pins the random draw so a take can be re-tried, but Chatterbox isn't bit-for-bit reproducible even with a seed, so a pin only gets you close — each take shows the seed it used. <span class="text-zinc-400">Preview</span> auditions the typed settings (saved as a take, but not selected). Like what you hear? <span class="text-zinc-400">Use this take</span> keeps that exact clip as the chunk's audio. <span class="text-zinc-400">Save tuning</span> only stores the numbers and marks the chunk stale, so <span class="text-zinc-400">Generate</span> (top) renders a fresh take. <span class="text-zinc-400">Re-roll</span> gives you another take of the same text and tuning.</p>
                    </details>
                </div>

                @unless($loop->last)
                    @php $next = $chunks->get($loop->index + 1); @endphp
                    <div class="chunk-seam flex flex-col items-center {{ $chunk->isCompleted() && $next->isCompleted() ? '' : 'hidden' }}"
                         data-prev="{{ $chunk->id }}" data-next="{{ $next->id }}">
                        <span class="h-3 w-px bg-zinc-700"></span>
                        <button type="button" class="seam-preview rounded-full border border-zinc-700 bg-zinc-800 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-300 hover:bg-zinc-700">▶ Preview stitch</button>
                        <span class="h-3 w-px bg-zinc-700"></span>
                        <div class="seam-player mt-1 hidden w-full max-w-2xl">
                            <div class="aplayer aplayer--chunk rounded-[12px] border border-white/8 bg-inset px-3.5 py-2.5">
                                <button type="button" class="aplayer__btn" aria-label="Play stitched preview"><span class="aplayer__icon"></span></button>
                                <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                                <span class="aplayer__time">0:00 / 0:00</span>
                                <audio class="seam-audio aplayer__native"></audio>
                            </div>
                            <div class="seam-status mt-1 text-center text-xs text-zinc-400" role="status" aria-live="polite"></div>
                        </div>
                    </div>
                @endunless

                <div class="chunk-insert flex justify-center" data-position="{{ $loop->iteration }}">
                    <button type="button" class="rounded-full border border-zinc-800 px-3 py-0.5 text-xs text-zinc-600 hover:border-zinc-600 hover:text-zinc-300">+ insert chunk</button>
                </div>
            @endforeach
        </div>
    </div>
</x-layout>
