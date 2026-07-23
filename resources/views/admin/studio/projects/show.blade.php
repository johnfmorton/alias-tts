<x-layout :title="$project->title" :heading="false">

    @php
        $chunkStyles = [
            'completed' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
            'stale'     => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
            'failed'    => 'border-red-500/30 bg-red-500/10 text-red-300',
            'pending'   => 'border-zinc-700 bg-zinc-800 text-zinc-400',
            // Virtual status: waiting its turn in an active background run
            // (cyan = the run's color). Must match STATUS_STYLES in app.js.
            'queued'    => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',
        ];
        $hasFinal = (bool) $project->final_audio_path;
        $statusVal = $project->status->value;
        $statusBadgeClass = $statusVal === 'ready' ? $chunkStyles['completed'] : ($statusVal === 'stale' ? $chunkStyles['stale'] : $chunkStyles['pending']);
    @endphp

    {{-- A big project takes a beat to arrive, parse, and wire up (a card + player
         per chunk) — without a signal that read as the page freezing. This veil
         paints with the first bytes of the page and covers the not-yet-interactive
         markup; app.js removes it the moment initStudioProject() finishes (see the
         try/finally around that call). Small projects load too fast to need it. --}}
    @if($chunks->count() >= 20)
        <div id="studio-loading" class="fixed inset-0 z-[60] flex cursor-wait items-center justify-center bg-zinc-950/90">
            <div class="flex flex-col items-center gap-3" role="status">
                <svg class="size-8 animate-spin text-cyan-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/>
                </svg>
                <p class="text-sm font-medium text-zinc-200">Loading project…</p>
                <p class="text-xs text-zinc-500">Preparing {{ $chunks->count() }} audio clips.</p>
            </div>
        </div>
    @endif

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
         data-generate-remaining-url="{{ route('admin.studio.projects.generate-remaining', $project) }}"
         data-generation-status-url="{{ route('admin.studio.projects.generation-status', $project) }}"
         data-estimate-url="{{ route('admin.studio.projects.estimate', $project) }}"
         {{-- Built-in Delivery archetypes (Steady/Balanced/Expressive) per engine,
              in native knob values — the JS applies them on a chip click and matches
              the sliders against them to light the active chip (or none = Custom). --}}
         data-delivery-presets='@json(\App\Services\Tts\DeliveryPresets::all())'
         data-active-run="{{ $hasActiveRun ? '1' : '0' }}"
         data-active-run-type="{{ $activeRunType ?? '' }}">

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

        {{-- Project header — 11C "player-forward": one sticky card, three rows.
             Row 1 (identity): ← Projects · title · Rename · economics chips · status
             · project-scope ⋯ menu. Row 2: the final-audio hero player. Row 3
             (controls): Voice/Format config on the left, the audio-output action
             cluster on the right. Every element id and data hook is unchanged —
             initStudioProject()/reflectActionState()/renderSpend() drive looks,
             text, and visibility exactly as before; this is a layout + skin pass. --}}
        <div class="sticky top-0 z-30 mb-6 rounded-2xl border border-white/[0.09] bg-sticky px-5 py-4 shadow-[0_16px_40px_-10px_rgba(0,0,0,0.7)] sm:px-6 sm:py-5">
            {{-- Row 1: identity + economics chips + project-scope menu --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <a href="{{ route('admin.studio.index') }}" class="inline-flex items-center gap-1.5 text-sm text-zinc-400 hover:text-zinc-200">← Projects</a>
                <span class="hidden h-[18px] w-px bg-white/10 sm:block" aria-hidden="true"></span>
                <span id="project-title-label" class="text-lg font-bold tracking-[-0.2px] text-zinc-100">{{ $project->title }}</span>
                @if($foreignOwner)
                    {{-- Always-visible ownership flag for SuperAdmin support visits;
                         the #foreign-guard dialog below gates the first actual edit. --}}
                    <span class="inline-flex cursor-help items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300"
                          title="This project belongs to {{ $foreignOwner }}. You can open it because you're a SuperAdmin — edits here change their work.">⚠ {{ $foreignOwner }}'s project</span>
                @endif
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

                {{-- Economics as compact stat chips (value over key), then status +
                     the project-scope ⋯ menu — lifecycle actions kept apart from the
                     audio-output buttons in row 3. --}}
                <div class="ml-auto flex items-center gap-2">
                    <div class="flex min-w-[62px] flex-col items-center rounded-[9px] border border-white/[0.08] bg-inset px-3 py-1.5">
                        <span class="font-mono text-[13px] font-semibold text-zinc-200">{{ $chunks->count() }}</span>
                        <span class="text-[10px] uppercase tracking-wide text-zinc-500">chunks</span>
                    </div>
                    @if(\App\Support\GenerationCost::enabled())
                        {{-- Lifetime spend — counts every render ever, so deleting
                             takes/chunks never lowers it (see GenerationCost). Priced
                             per engine; the tooltip spells out the per-model breakdown.
                             The value is viewer-aware (marked-up for limited users,
                             actual for SuperAdmins) and refreshed live by renderSpend()
                             into .stat-value. --}}
                        <div id="project-spend" class="flex min-w-[62px] cursor-help flex-col items-center rounded-[9px] border border-white/[0.08] bg-inset px-3 py-1.5"
                             title="{{ $projectSpendReadout['title'] }}">
                            <span class="stat-value font-mono text-[13px] font-semibold text-zinc-200">{{ $projectSpendReadout['label'] }}</span>
                            <span class="text-[10px] uppercase tracking-wide text-zinc-500">spend</span>
                        </div>
                    @endif
                    {{-- The OWNER's remaining prepaid credit; absent = unlimited. JS
                         refreshes .stat-value + its low-balance color from spend.balance
                         after every render. --}}
                    @if($creditBalance !== null)
                        <div id="credit-balance" class="flex min-w-[62px] cursor-help flex-col items-center rounded-[9px] border border-white/[0.08] bg-inset px-3 py-1.5"
                             title="Prepaid credit remaining for this project's owner. New generation pauses when it reaches $0 — existing audio stays available.">
                            <span class="stat-value font-mono text-[13px] font-semibold {{ $creditBalance <= 0 ? 'text-amber-400' : 'text-ok' }}">{{ \App\Services\Credit\CreditService::formatMicro($creditBalance) }}</span>
                            <span class="text-[10px] uppercase tracking-wide text-zinc-500">credit</span>
                        </div>
                    @endif
                    <span id="project-status" class="inline-flex rounded-md border px-2 py-0.5 text-xs {{ $statusBadgeClass }}">{{ $statusVal }}</span>
                    <span class="h-[22px] w-px bg-white/10" aria-hidden="true"></span>
                    {{-- Project-scope menu (Start over, Duplicate, Clean up, Download
                         archive, Delete) — rare + lifecycle actions, kept with identity
                         and away from the export buttons (design turn 3). --}}
                    <div class="relative">
                        <button type="button" id="project-overflow" aria-label="More actions"
                                class="grid h-[38px] w-[38px] place-items-center rounded-[9px] border border-white/14 text-lg text-zinc-300 hover:bg-white/[0.04]">⋯</button>
                        <div id="project-overflow-menu" class="absolute top-[44px] right-0 z-40 hidden w-56 rounded-[12px] border border-white/10 bg-menu p-1.5 shadow-[0_20px_40px_-12px_rgba(0,0,0,0.7)]">
                            {{-- Undo an approval made by mistake. Shown only while approved
                                 (block/hidden toggled together — never left both — by reflectSeal). --}}
                            <button type="button" id="project-unseal"
                                    title="Remove the approval so you can edit or re-approve. The audio is kept."
                                    class="w-full rounded-lg px-3 py-2 text-left text-sm text-zinc-300 hover:bg-white/[0.04] {{ $project->isSealed() ? 'block' : 'hidden' }}">↺ Unapprove</button>
                            <a href="{{ route('admin.studio.projects.revise', $project) }}"
                               title="Paste the updated text and re-render only the chunks that changed — everything else keeps its audio."
                               data-busy data-busy-label="Opening Revise text…"
                               class="block rounded-lg px-3 py-2 text-sm text-zinc-300 hover:bg-white/[0.04]">✎ Revise text</a>
                            <a href="{{ route('admin.studio.projects.edit', $project) }}"
                               class="block rounded-lg px-3 py-2 text-sm text-zinc-300 hover:bg-white/[0.04]">↺ Start over</a>
                            <form method="POST" action="{{ route('admin.studio.projects.duplicate', $project) }}" id="project-duplicate-form">
                                @csrf
                                <button type="submit" id="project-duplicate"
                                        title="Make an independent copy of this project — its own text, chunks, and audio. Changes to either project never affect the other."
                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm text-zinc-300 hover:bg-white/[0.04]">⧉ Duplicate project</button>
                            </form>
                            <form method="POST" action="{{ route('admin.studio.projects.cleanup', $project) }}"
                                  data-busy data-busy-label="Cleaning up…"
                                  data-confirm="Every take except each chunk's selected one is deleted permanently. The audio in use, the final, and any approval are kept."
                                  data-confirm-title="Clean up this project?"
                                  data-confirm-label="Clean up project">
                                @csrf
                                <button type="submit"
                                        title="Free up space by deleting the alternate takes you didn't pick. What you hear doesn't change."
                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm text-zinc-300 hover:bg-white/[0.04]">✂ Clean up project</button>
                            </form>
                            {{-- Everything-zip (approved audio + receipt + every clip) for keeping a
                                 local record before deleting the project. Needs an approved final —
                                 clicking earlier surfaces the server's message in the status line. --}}
                            <a id="project-archive" href="{{ route('admin.studio.projects.archive', $project) }}" download
                               title="Download a .zip of everything — the approved audio, its receipt, and every clip — so you can keep a local record and delete the project from the site."
                               class="block rounded-lg px-3 py-2 text-sm text-zinc-300 hover:bg-white/[0.04]">⤓ Download archive</a>
                            <form method="POST" action="{{ route('admin.studio.projects.destroy', $project) }}"
                                  data-busy data-busy-label="Deleting…"
                                  data-confirm="The project and all its audio are deleted permanently."
                                  data-confirm-title="Delete this project?"
                                  data-confirm-label="Delete project">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-bad hover:bg-white/[0.04]">Delete project</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Row 2: the final-audio hero player (the artifact this whole page builds) --}}
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <div id="project-final-player" class="aplayer aplayer--hero min-w-[300px] flex-1 {{ $hasFinal ? '' : 'hidden' }}">
                    <button type="button" class="aplayer__btn" aria-label="Play or pause the final audio"><span class="aplayer__icon"></span></button>
                    <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                    <span class="aplayer__time">0:00 / 0:00</span>
                    <audio id="project-final-audio" class="aplayer__native" preload="metadata" @if($hasFinal) src="{{ route('admin.studio.projects.audio', $project) }}" @endif></audio>
                </div>
                @unless($hasFinal)
                    <div id="project-final-placeholder" class="min-w-[300px] flex-1 text-sm text-zinc-600">No final audio yet — generate the chunks, then build the final.</div>
                @endunless
            </div>

            {{-- Row 3: Voice/Format config (left) + the audio-output action cluster
                 (right). Action looks (primary / outline / seal / disabled) and
                 visibility are set by reflectActionState() from the current state. --}}
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-zinc-500" title="Changing the voice marks generated chunks for regeneration.">
                    <span class="text-zinc-400">Voice</span>
                    <select id="project-voice"
                            class="rounded-[8px] border border-edge bg-inset px-2.5 py-1.5 text-sm text-zinc-200 focus:border-accent/50 focus:outline-none">
                        @foreach($voices as $v)
                            <option value="{{ $v->slug }}" data-model="{{ \App\Services\Tts\ModelCatalog::forVoice($v) }}" @selected($project->voice && $project->voice->id === $v->id)>{{ $v->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2 text-xs text-zinc-500" title="Final audio format. MP3 is compressed; WAV is uncompressed (~10× larger). Changing it rebuilds the final in the new format.">
                    <span class="text-zinc-400">Format</span>
                    <select id="project-format"
                            class="rounded-[8px] border border-edge bg-inset px-2.5 py-1.5 text-sm text-zinc-200 focus:border-accent/50 focus:outline-none">
                        @foreach($outputFormats as $token => $optLabel)
                            <option value="{{ $token }}" @selected($project->output_format === $token)>{{ $optLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <button type="button" id="project-generate-all" class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">▶ Generate remaining</button>
                    {{-- Stop the background run (shown only while one is in flight;
                         see reflectActionState). The clip being rendered still lands. --}}
                    <button type="button" id="project-generate-stop"
                            title="Stop the background run — the clip being rendered finishes and is kept."
                            class="hidden items-center gap-1.5 rounded-[9px] border border-red-500/30 px-4 py-[9px] text-sm text-red-400 transition hover:bg-red-500/10">■ Stop</button>
                    <button type="button" id="project-rebuild" class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">↻ Build final</button>
                    {{-- Preview download (bare final audio) — hidden once approved; the
                         approved-version package below supersedes it. --}}
                    <a id="project-download" href="{{ route('admin.studio.projects.audio', $project) }}" download class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">↓ Download preview</a>
                    {{-- Approve ⇆ approved-download share one slot: "Approve as final" shows
                         until approved, then the approved-version download replaces it in
                         place as the primary action (toggled by reflectActionState). --}}
                    <button type="button" id="project-seal"
                            title="Approve this cut as the final deliverable and record who approved it. Editing the project afterward clears the approval."
                            class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">🔒 Approve as final</button>
                    <a id="project-receipt" href="{{ route('admin.studio.projects.receipt', $project) }}" download
                       title="Download the approved version (.zip): the final audio and a provenance report, with a link to verify the file online."
                       class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition {{ $project->isSealed() ? '' : 'hidden' }}">⤓ Download approved version</a>
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
            {{-- Pre-run estimate ("About 2 min to generate the N remaining clips").
                 Server-rendered for the initial paint, then kept current by
                 refreshEstimate() in initStudioProject as the outstanding set
                 changes (edits, generate, skip, voice switch). Its own element so
                 transient status messages below never clobber it; hidden during a
                 run (the live ETA shows in #project-final-status instead). --}}
            {{-- Both lines sit under the row-3 action cluster (Generate remaining /
                 Stop / Build final), so they're right-justified next to the buttons
                 they describe. Alignment + top margin come from the #project-*
                 CSS id rules in app.css — NOT utility classes — because setStatus()
                 rewrites #project-final-status's className on every run message. --}}
            <div id="project-generate-estimate" class="text-sm text-zinc-500 {{ $preRunEstimate ? '' : 'hidden' }}" role="status">{{ $preRunEstimate ?? '' }}</div>
            {{-- Why Build final is missing while an edit is pending: a stitch now
                 would speak audio that no longer matches the screen. Text +
                 visibility are owned by reflectActionState() in app.js. --}}
            <div id="project-dirty-hint" class="hidden text-sm text-amber-400" role="status"></div>
            <div id="project-final-status" class="text-sm text-zinc-400" role="status" aria-live="polite"></div>
        </div>

        @if(config('tts.asr.enabled'))
            {{-- First-run QA orientation (design 10E): a dismissible one-liner that
                 replaces the standing paragraph every returning user re-scrolled past.
                 Starts hidden and initStudioProject reveals it only when the per-user
                 localStorage flag is absent — so it never flashes for returning users
                 and is gone for good once dismissed. --}}
            <div id="qa-intro" class="mb-3 hidden items-center gap-3 rounded-xl border border-accent/30 bg-accent/5 px-4 py-2.5">
                <span class="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full border border-accent/50 text-[11px] font-medium text-accent" aria-hidden="true">i</span>
                <span class="flex-1 text-sm leading-relaxed text-zinc-300">Every chunk is auto-checked for glitches, and small issues are fixed for you. Hover any <span class="font-semibold text-emerald-300">QA</span> badge for details.</span>
                <button type="button" id="qa-intro-dismiss" class="flex-shrink-0 text-sm text-zinc-500 hover:text-zinc-300">Got it ✕</button>
            </div>
        @endif

        {{-- Chunks, joined by a "seam" connector between each adjacent pair that pairs
             Preview stitch (live once both sides have audio) with Insert chunk (design 8A). --}}
        <div class="space-y-3">
            {{-- Insert a new (empty) chunk at this gap. Always available, unlike the
                 seam's Preview stitch. Rendered as a quiet connector line to match the
                 seams that sit between chunks (design 8A). --}}
            <div class="flex items-center gap-3.5">
                <span class="h-px flex-1 bg-white/10"></span>
                <button type="button" data-position="0"
                        class="seam-insert inline-flex items-center gap-1.5 text-xs font-medium text-zinc-400 hover:text-zinc-200">
                    <span class="text-sm leading-none">+</span> Insert chunk
                </button>
                <span class="h-px flex-1 bg-white/10"></span>
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
                // Turbo's knob dialect, resolved the same way the provider would.
                $inheritTurbo = \App\Services\Tts\ChatterboxTurboTuning::resolveNative(is_array($project->settings) ? $project->settings : []);
                $inheritTopP = number_format($inheritTurbo['top_p'], 2);
                $inheritTopK = (string) $inheritTurbo['top_k'];
                $inheritRepPenalty = number_format($inheritTurbo['repetition_penalty'], 2);
                // Seed the chunk field inherits: the project's pinned seed, or blank
                // (a random draw) when the project isn't pinned.
                $inheritSeedText = $project->seed ? (string) $project->seed : 'random';
            @endphp
            @foreach($chunks as $chunk)
                @php
                    // Set while this chunk is waiting its turn in an active
                    // background run — the pill shows its place in line and the
                    // render button reads "Queued" (kept fresh by the poll).
                    $queueLabel = $queuedLabels[$chunk->id] ?? null;
                @endphp
                <div class="studio-chunk rounded-xl border border-zinc-800 bg-zinc-900/50 p-4"
                     data-chunk-id="{{ $chunk->id }}"
                     data-queued="{{ $queueLabel ? '1' : '0' }}"
                     data-queue-url="{{ route('admin.studio.projects.chunks.queue', [$project, $chunk]) }}"
                     data-patch-url="{{ route('admin.studio.projects.chunks.update', [$project, $chunk]) }}"
                     data-qa-dismiss-url="{{ route('admin.studio.projects.chunks.qa-dismiss', [$project, $chunk]) }}"
                     data-delete-url="{{ route('admin.studio.projects.chunks.destroy', [$project, $chunk]) }}"
                     data-audio-url="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}"
                     data-takes-url="{{ route('admin.studio.projects.chunks.takes.index', [$project, $chunk]) }}"
                     data-skip-url="{{ route('admin.studio.projects.chunks.skip', [$project, $chunk]) }}"
                     data-skipped="{{ $chunk->skipped ? '1' : '0' }}"
                     data-takes='@json($takesByChunk[$chunk->id])'>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-sm text-zinc-400">
                            <span class="chunk-no font-mono text-zinc-300">#{{ $chunk->position + 1 }}</span>
                            <span class="chunk-chars">{{ $chunk->characters }} chars</span>
                            @if(\App\Support\GenerationCost::enabled())
                                {{-- This chunk's lifetime render spend; hidden until the first
                                     take. JS toggles ONLY `hidden` (no competing display class).
                                     Priced per engine via the chunk's counter split; the label
                                     is viewer-aware (see spendReadout()). --}}
                                <span class="chunk-spend cursor-help text-zinc-500 {{ $chunk->spent_characters > 0 ? '' : 'hidden' }}"
                                      title="{{ $chunkSpendReadouts[$chunk->id]['title'] }}">{{ $chunkSpendReadouts[$chunk->id]['label'] }}</span>
                            @endif
                            <span class="chunk-status inline-flex rounded-md border px-2 py-0.5 text-xs {{ $queueLabel ? $chunkStyles['queued'] : ($chunkStyles[$chunk->status->value] ?? $chunkStyles['pending']) }}">{{ $queueLabel ?? $chunk->status->value }}</span>
                            {{-- ASR transcript-QA verdict + hover/focus popover (design "QA Badge
                                 States"); only when the chunk's current audio was scored. The
                                 markup here is mirrored by renderQaBadge() in app.js. --}}
                            @include('admin.studio.projects._qa-badge', ['badge' => $chunk->asrBadge()])
                            {{-- "Present but silent": shown while the chunk is skipped. Class strings
                                 must stay identical to setChunkSkipped() in app.js. --}}
                            <span class="chunk-skip-pill {{ $chunk->skipped ? 'inline-flex' : 'hidden' }} rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">skipped</span>
                            <span class="chunk-dirty hidden rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">● unsaved</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="flex items-center gap-1.5 text-xs text-zinc-500">
                                <span class="text-zinc-400">Voice</span>
                                <select class="chunk-voice rounded-lg border border-edge bg-zinc-950 px-2 py-1.5 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                                        data-voice-url="{{ route('admin.studio.projects.chunks.voice', [$project, $chunk]) }}"
                                        data-inherits="{{ $chunk->voice_id ? '0' : '1' }}"
                                        title="Voice for this chunk. Follows the project voice until you pick one here.">
                                    @foreach($voices as $v)
                                        <option value="{{ $v->slug }}" data-model="{{ \App\Services\Tts\ModelCatalog::forVoice($v) }}" @selected(($chunk->voice_id ?? $project->voice_id) === $v->id)>{{ $v->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="button" class="chunk-revert hidden rounded-lg border border-zinc-700 px-3 py-1.5 text-sm text-zinc-400 hover:bg-zinc-800">Revert</button>
                            {{-- Regenerate is the ONE action: it saves a pending text edit
                                 AND the tuning panel below, then renders — what's on screen
                                 is always exactly what renders. There is deliberately no
                                 save-text-without-render (saved words with stale audio would
                                 lie). data-base carries the verb setGenerateLabel renders
                                 (Generate until the chunk has audio, then Regenerate). --}}
                            <button type="button" class="chunk-generate rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-transparent"
                                    data-base="{{ $chunk->isCompleted() ? 'Regenerate' : 'Generate' }}"
                                    title="Render this chunk with the text and Delivery settings shown — they're saved as part of the click.">{{ $queueLabel ? '⏳ Queued' : '▶ '.($chunk->isCompleted() ? 'Regenerate' : 'Generate') }}</button>
                            {{-- Skip toggle: leave this chunk out of the stitched final without deleting
                                 it. Reversible, so no confirm step. Class strings must stay identical to
                                 setChunkSkipped() in app.js. --}}
                            <button type="button"
                                    class="chunk-skip rounded-lg border px-2.5 py-1.5 text-sm {{ $chunk->skipped
                                        ? 'border-amber-500/40 bg-amber-500/10 text-amber-300'
                                        : 'border-zinc-700 text-zinc-500 hover:border-amber-700/60 hover:text-amber-300' }}"
                                    title="{{ $chunk->skipped ? 'Include this chunk in the final audio.' : 'Skip this chunk in the final audio.' }}">{{ $chunk->skipped ? '🔇' : '🔊' }}</button>
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

                    {{-- Chunk-scoped feedback (select's restore hint, skip state, voice-change
                         hint, errors) lands here on the card it belongs to — the header status
                         line is for project-wide messages only. Written by chunkNotice() in
                         app.js; empty:hidden keeps the row out of the layout when clear, so
                         the element must stay literally empty (no whitespace) in this markup. --}}
                    <div class="chunk-notice mt-2 text-sm empty:hidden text-zinc-400" role="status" aria-live="polite"></div>

                    @php $chunkModel = \App\Services\Tts\ModelCatalog::forVoice($chunk->voice ?? $project->voice); @endphp
                    <textarea class="chunk-text mt-2 w-full rounded-lg border border-edge bg-zinc-950 px-3 py-2 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30"
                              rows="2" data-original="{{ $chunk->text }}">{{ $chunk->text }}</textarea>

                    {{-- Turbo renders these tags as actual sounds; a click inserts one
                         at the cursor. Engine-scoped like the knobs (swapped live by
                         syncKnobEngines via data-engine-help); the wrapper is a plain
                         block so `hidden` alone is safe — the flex lives one level in. --}}
                    <div data-engine-help="chatterbox-turbo" @class(['chunk-sound-tags', 'hidden' => $chunkModel !== 'chatterbox-turbo'])>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <span class="cursor-help text-[11px] uppercase tracking-wide text-zinc-500" title="Chatterbox Turbo renders these as actual sounds, not words. They work best mid-sentence — a tag at the very end of a chunk can trip take QA.">Sound tags</span>
                            {{-- Chips show the literal token they insert; the brackets are
                                 dimmed like editor syntax so the tag words stay scannable. --}}
                            @foreach(\App\Services\Tts\ParalinguisticTags::TAGS as $tag)
                                <button type="button" class="chunk-tag-insert whitespace-nowrap rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-0.5 font-mono text-xs text-zinc-200 transition hover:border-cyan-400/40 hover:bg-cyan-500/10 hover:text-cyan-100 active:bg-cyan-500/20"
                                        data-tag="[{{ $tag }}]" title="Insert [{{ $tag }}] at the cursor"><span class="text-zinc-500">[</span>{{ $tag }}<span class="text-zinc-500">]</span></button>
                            @endforeach
                        </div>
                    </div>

                    {{-- data-duration-ms: the selected take's recorded length, so the
                         readout shows the duration without any metadata request.
                         preload="none" (like the take players) — with it on "metadata"
                         a big project fired one ranged-audio request per chunk on page
                         load, enough to saturate the server and freeze the page. --}}
                    @php $selectedTakeDuration = collect($takesByChunk[$chunk->id]['takes'])->firstWhere('selected', true)['duration_ms'] ?? null; @endphp
                    <div class="aplayer aplayer--chunk mt-3 rounded-[12px] border border-white/8 bg-inset px-3.5 py-2.5 {{ $chunk->isCompleted() ? '' : 'hidden' }}"
                         @if($selectedTakeDuration) data-duration-ms="{{ $selectedTakeDuration }}" @endif>
                        <button type="button" class="aplayer__btn" aria-label="Play chunk audio"><span class="aplayer__icon"></span></button>
                        <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                        <span class="aplayer__time">0:00 / 0:00</span>
                        <audio class="chunk-audio aplayer__native" preload="none"
                               @if($chunk->isCompleted()) src="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}" @endif></audio>
                    </div>

                    {{-- Take history + per-chunk tuning override. --}}
                    <details class="chunk-tune mt-3 text-sm text-zinc-400" @if(!empty($chunk->settings) || $chunk->takes->count() > 1) open @endif>
                        <summary class="cursor-pointer select-none text-xs hover:text-zinc-200">Takes &amp; tuning</summary>

                        {{-- Every render is kept here — audition a prior take, Select the one
                             that sounded best (which also restores the text + tuning it was
                             rendered from), or delete the duds. Populated by the JS from
                             data-takes (and refreshed after each render). --}}
                        <ul class="chunk-takes mt-2 space-y-1.5"></ul>

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
                                   value="{{ $chunk->settings['seed'] ?? '' }}" placeholder="{{ $inheritSeedText }}"
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
                             user (localStorage). ($chunkModel is set above, by the sound-tags row.) --}}
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
                                               :value="$chunk->settings['exaggeration'] ?? ''" :placeholder="$inheritExaggeration"
                                               inputClass="chunk-exaggeration" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox'" />
                                <x-tuning-knob knob="cfg_weight" label="CFG / Pace"
                                               hint="higher = measured read, lower = quicker · neutral 0.5"
                                               help="Pacing steadiness — higher sticks closer to a measured read, lower is quicker and looser."
                                               :min="0.2" :max="1" :step="0.05"
                                               :value="$chunk->settings['cfg_weight'] ?? ''" :placeholder="$inheritCfg"
                                               inputClass="chunk-cfg" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox'" />
                                <x-tuning-knob knob="top_p" label="Top-p"
                                               hint="how adventurous each step can be · neutral 0.95"
                                               help="Limits the pool of likely next sounds. Lower is focused and consistent, higher allows more varied phrasing."
                                               :min="0.5" :max="1" :step="0.01"
                                               :value="$chunk->settings['top_p'] ?? ''" :placeholder="$inheritTopP"
                                               inputClass="chunk-top-p" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox-turbo'" />
                                <x-tuning-knob knob="top_k" label="Top-k"
                                               hint="size of that pool · neutral 1000"
                                               help="How many candidate sounds Top-p draws from. Smaller is tighter and more predictable, larger is more varied."
                                               :min="1" :max="2000" :step="1"
                                               :value="$chunk->settings['top_k'] ?? ''" :placeholder="$inheritTopK"
                                               inputClass="chunk-top-k" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox-turbo'" />
                                <x-tuning-knob knob="repetition_penalty" label="Rep. penalty"
                                               hint="nudge up if syllables stutter · neutral 1.2"
                                               help="Discourages repeated sounds — nudge it up if syllables or words stutter."
                                               :min="1" :max="2" :step="0.05"
                                               :value="$chunk->settings['repetition_penalty'] ?? ''" :placeholder="$inheritRepPenalty"
                                               inputClass="chunk-repetition-penalty" :reset="false" :rail="false" class="w-full" :hidden="$chunkModel !== 'chatterbox-turbo'" />
                                <x-tuning-knob knob="temperature" label="Temperature"
                                               hint="lower = steadier, higher = livelier · neutral 0.8"
                                               help="Sampling randomness — lower is flatter and steadier, higher is livelier but less predictable."
                                               :min="0.5" :max="1.5" :step="0.05"
                                               :value="$chunk->settings['temperature'] ?? ''" :placeholder="$inheritTemperature"
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
                                                <option value="{{ $lang }}" @selected(($chunk->settings['language'] ?? '') === $lang)>{{ $lang === 'auto' ? 'Auto-detect' : $lang }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="text-[11.5px] text-zinc-500">which language the text is read as · auto detects</div>
                                </div>
                                <div class="tuning-knob relative w-full {{ $chunkModel === 'qwen3-tts' ? 'flex' : 'hidden' }} flex-col gap-1" data-knob="style_instruction">
                                    <span class="text-sm font-semibold text-zinc-300">Style note</span>
                                    <input type="text" maxlength="{{ \App\Services\Tts\Qwen3TtsTuning::STYLE_INSTRUCTION_MAX }}"
                                           value="{{ $chunk->settings['style_instruction'] ?? '' }}"
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
                    </details>
                </div>

                @unless($loop->last)
                    @php
                        $next = $chunks->get($loop->index + 1);
                        // A skipped neighbor drops this join from the final, so the seam
                        // hides entirely; otherwise the connector shows but Preview stitch
                        // only goes live once both sides have audio (refreshSeams() mirrors
                        // this live as chunks are generated, edited, or skipped).
                        $seamSkipped = $chunk->skipped || $next->skipped;
                        $seamReady = $chunk->isCompleted() && $next->isCompleted() && ! $seamSkipped;
                    @endphp
                    {{-- The seam between two adjacent chunks (design 8A): one quiet
                         connector line pairing both actions — Preview stitch (live only
                         when both neighbors have audio) and Insert chunk (always). The
                         stitched preview drops in below, reusing the standard player. --}}
                    <div class="chunk-seam py-1 {{ $seamSkipped ? 'hidden' : '' }}"
                         data-prev="{{ $chunk->id }}" data-next="{{ $next->id }}">
                        <div class="flex items-center gap-3.5">
                            <span class="h-px flex-1 bg-white/10"></span>
                            <button type="button" @disabled(! $seamReady)
                                    title="{{ $seamReady ? '' : 'Generate both chunks to preview how they stitch together.' }}"
                                    class="seam-preview inline-flex items-center gap-1.5 text-xs font-semibold text-zinc-400 hover:text-zinc-200 disabled:text-zinc-600 disabled:cursor-not-allowed"><span class="seam-glyph text-[9px] leading-none text-accent">▶</span><span class="seam-label">Preview stitch</span></button>
                            <span class="h-3.5 w-px bg-white/[0.14]"></span>
                            <button type="button" data-position="{{ $loop->iteration }}"
                                    class="seam-insert inline-flex items-center gap-1.5 text-xs font-medium text-zinc-400 hover:text-zinc-200">
                                <span class="text-sm leading-none">+</span> Insert chunk
                            </button>
                            <span class="h-px flex-1 bg-white/10"></span>
                        </div>
                        {{-- The disabled reason, visible without hovering (design state 1);
                             refreshSeams() toggles this in step with the button. --}}
                        <p class="seam-hint mt-2 text-center text-[11.5px] leading-snug text-zinc-500 {{ $seamReady ? 'hidden' : '' }}">Generate both chunks to preview how they stitch together.</p>
                        {{-- Transient stitched preview — the same player used per chunk,
                             never a saved take. --}}
                        <div class="seam-player mt-3 hidden">
                            <div class="aplayer aplayer--chunk rounded-xl border border-white/8 bg-inset px-4 py-3.5">
                                <button type="button" class="aplayer__btn" aria-label="Play stitched preview"><span class="aplayer__icon"></span></button>
                                <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                                <span class="aplayer__time">0:00 / 0:00</span>
                                <audio class="seam-audio aplayer__native"></audio>
                            </div>
                            <div class="mt-2"><span class="seam-status text-xs text-ok" role="status" aria-live="polite"></span></div>
                        </div>
                    </div>
                @endunless

                @if($loop->last)
                    {{-- After the last chunk: insert only — there's no following chunk to
                         stitch, so this seam carries just the Insert action. --}}
                    <div class="flex items-center gap-3.5">
                        <span class="h-px flex-1 bg-white/10"></span>
                        <button type="button" data-position="{{ $loop->iteration }}"
                                class="seam-insert inline-flex items-center gap-1.5 text-xs font-medium text-zinc-400 hover:text-zinc-200">
                            <span class="text-sm leading-none">+</span> Insert chunk
                        </button>
                        <span class="h-px flex-1 bg-white/10"></span>
                    </div>
                @endif
            @endforeach
        </div>

        @if($foreignOwner)
            {{-- Warning shown before a SuperAdmin's FIRST edit of someone else's
                 project (see the foreign-project guard in initStudioProject).
                 `hidden` is toggled together with `flex` in JS — never both static. --}}
            <div id="foreign-guard" role="alertdialog" aria-modal="true" aria-labelledby="foreign-guard-title"
                 class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
                <div class="w-full max-w-md rounded-xl border border-amber-500/40 bg-zinc-900 p-6 shadow-[0_24px_48px_-12px_rgba(0,0,0,0.8)]">
                    <h2 id="foreign-guard-title" class="text-base font-semibold text-amber-300">⚠ This is {{ $foreignOwner }}'s project</h2>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-300">
                        You can open it because you're a SuperAdmin, but edits here change
                        <span class="font-medium text-zinc-100">{{ $foreignOwner }}'s</span> text, audio, and takes — not a copy.
                        To experiment safely, duplicate the project and work on your own copy instead.
                    </p>
                    <div class="mt-5 flex flex-wrap items-center justify-end gap-2.5">
                        {{-- Default/active choice (focused on open, so Enter keeps it): the
                             safe option, styled green and prominent. Duplicate = caution
                             (yellow); Edit their project = hazard (red). --}}
                        <button type="button" id="foreign-guard-cancel"
                                class="rounded-lg bg-ok px-3.5 py-2 text-sm font-semibold text-zinc-950 ring-2 ring-ok/60 ring-offset-2 ring-offset-zinc-900 transition hover:bg-ok/90 focus:outline-none">Keep read-only</button>
                        <button type="button" id="foreign-guard-duplicate"
                                class="rounded-lg border border-warn/50 bg-warn/10 px-3.5 py-2 text-sm font-medium text-warn transition hover:bg-warn/20"
                                title="Make your own independent copy — {{ $foreignOwner }}'s project is left untouched.">⧉ Duplicate instead</button>
                        <button type="button" id="foreign-guard-continue"
                                class="rounded-lg border border-bad/50 bg-bad/10 px-3.5 py-2 text-sm font-medium text-bad transition hover:bg-bad/20">Edit their project</button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-layout>
