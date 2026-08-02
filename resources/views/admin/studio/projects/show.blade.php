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
        // A built final is only offered for playback while it still speaks the
        // project. Once a chunk changes the project goes Stale, and those bytes
        // come off the transport rather than sit playable under a "Build final"
        // prompt — where the obvious read is "what I just heard is what I'd
        // ship". The JS mirrors this on every state change (setFinalCurrent).
        $finalCurrent = $hasFinal && $statusVal === 'ready';
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

    @php
        $lazyPlayers = (bool) config('tts.studio_lazy_players');
        $slimCards = (bool) config('tts.studio_slim_cards');
    @endphp
    <div id="studio-project"
         data-lazy-players="{{ $lazyPlayers ? '1' : '0' }}"
         data-has-final="{{ $hasFinal ? '1' : '0' }}"
         data-rebuild-url="{{ route('admin.studio.projects.rebuild', $project) }}"
         data-final-url="{{ route('admin.studio.projects.audio', $project) }}"
         data-timeline-url="{{ route('admin.studio.projects.timeline', $project) }}"
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

        @if($slimCards)
            {{-- Slim cards: the lists every card repeats render ONCE here; cards
                 ship a stub (selected voice option only, empty tag row) and JS
                 mounts the full content per card as it nears the viewport
                 (ensureVoiceOptions / mountTagChips). ~0.8 MB on 147 chunks. --}}
            <template id="chunk-voice-options-template">
                @foreach($voices as $v)
                    <option value="{{ $v->slug }}" data-model="{{ \App\Services\Tts\ModelCatalog::forVoice($v) }}">{{ $v->name }}</option>
                @endforeach
            </template>
            <template id="chunk-tag-chips-template">
                @foreach(\App\Services\Tts\ParalinguisticTags::TAGS as $tag)
                    <button type="button" class="chunk-tag-insert whitespace-nowrap rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-0.5 font-mono text-xs text-zinc-200 transition hover:border-cyan-400/40 hover:bg-cyan-500/10 hover:text-cyan-100 active:bg-cyan-500/20"
                            data-tag="[{{ $tag }}]" title="Insert [{{ $tag }}] at the cursor"><span class="text-zinc-500">[</span>{{ $tag }}<span class="text-zinc-500">]</span></button>
                @endforeach
            </template>
        @endif

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
             Row 1 (identity): ← All projects · title · Rename · economics chips · status
             · project-scope ⋯ menu. Row 2: the final-audio hero player. Row 3
             (controls): Voice/Format config on the left, the audio-output action
             cluster on the right. Every element id and data hook is unchanged —
             initStudioProject()/reflectActionState()/renderSpend() drive looks,
             text, and visibility exactly as before; this is a layout + skin pass.

             Below sm (design 12B) the header is ordinary page content — it scrolls
             away so chunks own the screen — and the transport pins at the BOTTOM
             instead: rows 2–3, the status pill, the ⋯ menu, and the status lines
             reparent into the production sheet (#project-sheet) attached to the
             bottom dock. initStudioMobileDock() moves the same nodes back and
             forth on the 640px boundary, so every id keeps a single instance. --}}
        <div id="project-sticky-header" class="mb-6 rounded-2xl border border-white/[0.09] bg-sticky px-5 py-4 sm:sticky sm:top-0 sm:z-30 sm:px-6 sm:py-5 sm:shadow-[0_16px_40px_-10px_rgba(0,0,0,0.7)]">
            {{-- Row 1: identity + economics chips + project-scope menu --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <a href="{{ route('admin.studio.index') }}" class="inline-flex items-center gap-1.5 text-sm text-zinc-400 hover:text-zinc-200">← All projects</a>
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
                    {{-- The divider pairs the pill with the ⋯ menu; both live in the
                         mobile sheet instead, so it hides with them below sm. --}}
                    <span class="h-[22px] w-px bg-white/10 max-sm:hidden" aria-hidden="true"></span>
                    {{-- Project-scope menu (Start over, Duplicate, Clean up, Download
                         archive, Delete) — rare + lifecycle actions, kept with identity
                         and away from the export buttons (design turn 3). --}}
                    <div class="relative" id="project-overflow-wrap">
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
                            <form method="POST" action="{{ route('admin.studio.projects.duplicate', $project) }}" id="project-duplicate-form"
                                  data-busy data-busy-label="Duplicating…">
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
            <div id="project-player-row" class="mt-4 flex flex-wrap items-center gap-4">
                <div id="project-final-player" class="aplayer aplayer--hero min-w-[300px] flex-1 {{ $finalCurrent ? '' : 'hidden' }}">
                    <button type="button" class="aplayer__btn" aria-label="Play or pause the final audio"><span class="aplayer__icon"></span></button>
                    <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                    <span class="aplayer__time">0:00 / 0:00</span>
                    {{-- No src for a superseded final: nothing to play, and nothing
                         to spend a metadata request (a presign redirect) on. The
                         next stitch sets it (applyStitchResult). --}}
                    <audio id="project-final-audio" class="aplayer__native" preload="metadata" @if($finalCurrent) src="{{ route('admin.studio.projects.audio', $project) }}" @endif></audio>
                </div>
                {{-- Follow playback: while the final plays, highlight + scroll to the
                     chunk being heard (initStudioFollowPlayback). Pressed state and
                     enablement are wholly JS-driven: disabled until a timeline for
                     the CURRENT final loads (older finals have none until rebuilt). --}}
                <button type="button" id="follow-toggle" aria-pressed="false" disabled
                        class="hidden items-center gap-1.5 rounded-[9px] border border-white/10 px-3 py-[7px] text-xs text-zinc-400 transition hover:border-cyan-400/40 hover:text-cyan-100 disabled:cursor-not-allowed disabled:opacity-40 aria-pressed:border-cyan-400/40 aria-pressed:bg-cyan-500/10 aria-pressed:text-cyan-200"
                        title="While the final plays, scroll the page to the chunk you're hearing.">↧ Follow</button>
                {{-- Stands in for the player whenever there's nothing current to
                     play — no final yet, or one the project has moved past. Always
                     in the DOM (setFinalCurrent swaps its text and visibility as
                     the final is built, superseded, and rebuilt). --}}
                <div id="project-final-placeholder" class="min-w-[300px] flex-1 text-sm text-zinc-600 {{ $finalCurrent ? 'hidden' : '' }}">{{ $hasFinal
                    ? 'Final audio is out of date — build it again to hear your changes.'
                    : 'No final audio yet — generate the chunks, then build the final.' }}</div>
            </div>

            {{-- Row 3: Voice/Format config (left) + the audio-output action cluster
                 (right). Action looks (primary / outline / seal / disabled) and
                 visibility are set by reflectActionState() from the current state. --}}
            <div class="mt-4 flex flex-wrap items-center gap-3">
                {{-- Voice/Format travel to the sheet as one group on mobile. --}}
                <div id="project-config-group" class="flex flex-wrap items-center gap-3">
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
                </div>
                <div id="project-actions" class="ml-auto flex flex-wrap items-center gap-2">
                    <button type="button" id="project-generate-all" class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">▶ Generate remaining</button>
                    {{-- Stop the background run (shown only while one is in flight;
                         see reflectActionState). The clip being rendered still lands. --}}
                    <button type="button" id="project-generate-stop"
                            title="Stop the background run — the clip being rendered finishes and is kept."
                            class="hidden items-center gap-1.5 rounded-[9px] border border-red-500/30 px-4 py-[9px] text-sm text-red-400 transition hover:bg-red-500/10">■ Stop</button>
                    <button type="button" id="project-rebuild" class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">↻ Build final</button>
                    {{-- Preview download (bare final audio) — hidden once approved; the
                         approved-version package below supersedes it. --}}
                    {{-- dl=1 keeps the download on the byte path (fingerprint filename + same-origin
     fetch); bare playback URLs redirect to presigned storage instead. --}}
                    <a id="project-download" href="{{ route('admin.studio.projects.audio', ['project' => $project, 'dl' => 1]) }}" download class="inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition">↓ Download preview</a>
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

            {{-- Approved-final badge + status lines, grouped so the mobile sheet can
                 adopt them whole (feedback must land where the buttons are). --}}
            <div id="project-status-lines">
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
                // The template's canonical engine (slim cards): render the panel as
                // the project's own engine so most cards mount without any knob
                // flips — ensureTune() re-syncs to each card's actual voice anyway.
                $projectModel = \App\Services\Tts\ModelCatalog::forVoice($project->voice);
            @endphp
            @if($slimCards)
                {{-- Slim cards: the tuning panel — the single heaviest per-card block
                     (~1.7 MB over 147 chunks) — renders ONCE here with every value
                     blank (= inherit). Cards ship only <details> + takes list plus
                     their overrides on data-tune-settings; ensureTune() in app.js
                     clones this and fills them in as the card nears the viewport. --}}
                <template id="chunk-tune-template">
                    @include('admin.studio.projects._tune-panel', ['chunk' => null, 'chunkModel' => $projectModel])
                </template>
            @endif
            @foreach($chunks as $chunk)
                @php
                    // Set while this chunk is waiting its turn in an active
                    // background run — the pill shows its place in line and the
                    // render button reads "Queued" (kept fresh by the poll).
                    $queueLabel = $queuedLabels[$chunk->id] ?? null;
                @endphp
                <div class="studio-chunk rounded-[14px] border border-white/[0.09] bg-panel p-4 sm:px-6 sm:py-5"
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
                    {{-- Identity (left) + controls (right) on one line. Below 640px
                         this wrapper dissolves — `display: contents` in app.css — and
                         its two halves become card-level items the mobile layout
                         orders independently: identity stays on top, the controls
                         drop to a footer under the player (design "mobile-chunk-card"
                         3A). Keep it the card's FIRST child: the skipped-chunk dim
                         (`> :not(:first-child)`) leaves exactly this row crisp. --}}
                    <div class="chunk-head flex flex-wrap items-center justify-between gap-2">
                        <div class="chunk-identity flex min-w-0 flex-wrap items-center gap-2 text-sm text-zinc-400">
                            <span class="chunk-no font-mono text-zinc-300">#{{ $chunk->position + 1 }}</span>
                            {{-- Reverse navigation: seek the FINAL player to where this
                                 chunk's audio sits and play from there — hear an edit in
                                 context without hunting the transport for a timestamp.
                                 Enabled by initStudioFollowPlayback once the current
                                 final's timeline lists this chunk; stays disabled for
                                 skipped chunks, chunks added since the last build, and
                                 finals from before timelines existed. --}}
                            <button type="button" class="chunk-play-final flex h-6 w-6 items-center justify-center rounded-md border border-zinc-700 p-0 text-zinc-500 transition hover:border-cyan-400/40 hover:text-cyan-200 disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:border-zinc-700 disabled:hover:text-zinc-500" disabled
                                    aria-label="Play the final audio from this chunk."
                                    title="Rebuild the final to enable play-from-here."><svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M4.5 2.8v10.4c0 .6.6.9 1.1.6l8.2-5.2c.4-.3.4-.9 0-1.2L5.6 2.2c-.5-.3-1.1 0-1.1.6z"></path></svg></button>
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
                            {{-- While pending edits exist this badge REPLACES the status + QA
                                 badges (they describe audio the panel no longer matches — the
                                 swap is CSS on data-dirty, see app.css). --}}
                            <span class="chunk-dirty hidden rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">edited — regenerate to hear</span>
                        </div>
                        {{-- Wraps below sm, where app.css also turns this into the card's
                             controls footer: Voice becomes a full-width labelled field on
                             its own line, then Regenerate takes the rest of the row beside
                             44px skip/delete targets, and the wider two-step delete confirm
                             gets a line to itself. min-w-0 lets the voice select shrink
                             instead of forcing the row wide. --}}
                        <div class="chunk-controls flex min-w-0 flex-wrap items-center justify-end gap-2">
                            <label class="chunk-voice-field flex min-w-0 items-center gap-1.5 text-xs text-zinc-500">
                                <span class="shrink-0 text-zinc-400">Voice</span>
                                @php $effectiveVoice = $slimCards ? $voices->firstWhere('id', $chunk->voice_id ?? $project->voice_id) : null; @endphp
                                <select class="chunk-voice min-w-0 max-w-[45vw] rounded-lg border border-edge bg-zinc-950 px-2 py-1.5 text-sm text-zinc-200 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30 sm:max-w-none"
                                        data-voice-url="{{ route('admin.studio.projects.chunks.voice', [$project, $chunk]) }}"
                                        data-inherits="{{ $chunk->voice_id ? '0' : '1' }}"
                                        @if($effectiveVoice) data-lazy-options="1" @endif
                                        title="Voice for this chunk. Follows the project voice until you pick one here.">
                                    {{-- Slim: only the selected option ships; ensureVoiceOptions()
                                         swaps in the full list (from the page template) on demand. --}}
                                    @if($effectiveVoice)
                                        <option value="{{ $effectiveVoice->slug }}" data-model="{{ \App\Services\Tts\ModelCatalog::forVoice($effectiveVoice) }}" selected>{{ $effectiveVoice->name }}</option>
                                    @else
                                        @foreach($voices as $v)
                                            <option value="{{ $v->slug }}" data-model="{{ \App\Services\Tts\ModelCatalog::forVoice($v) }}" @selected(($chunk->voice_id ?? $project->voice_id) === $v->id)>{{ $v->name }}</option>
                                        @endforeach
                                    @endif
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
                                 setChunkSkipped() in app.js, which toggles the icon pair (speaker-with-arcs
                                 = audible, speaker-with-× = skipped) instead of rewriting it. --}}
                            <button type="button"
                                    class="chunk-skip flex h-7 w-7 items-center justify-center rounded-lg border p-0 {{ $chunk->skipped
                                        ? 'border-amber-500/40 bg-amber-500/10 text-amber-300'
                                        : 'border-zinc-700 text-zinc-500 hover:border-amber-700/60 hover:text-amber-300' }}"
                                    aria-label="{{ $chunk->skipped ? 'Include this chunk in the final audio.' : 'Skip this chunk in the final audio.' }}"
                                    title="{{ $chunk->skipped ? 'Include this chunk in the final audio.' : 'Skip this chunk in the final audio.' }}"><svg class="chunk-skip-on {{ $chunk->skipped ? 'hidden' : '' }}" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 6v4h2.8L9 13V3L5.3 6H2.5z" fill="currentColor" stroke="none"></path><path d="M11 6.3c.9.9.9 2.5 0 3.4M12.9 4.6c1.8 1.9 1.8 4.9 0 6.8"></path></svg><svg class="chunk-skip-off {{ $chunk->skipped ? '' : 'hidden' }}" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 6v4h2.8L9 13V3L5.3 6H2.5z" fill="currentColor" stroke="none"></path><path d="M11 6.2l3.6 3.6M14.6 6.2L11 9.8"></path></svg></button>
                            @if($chunks->count() > 1)
                                {{-- Delete this chunk (two-step inline confirm). Hidden entirely for a
                                     one-chunk project — a project needs at least one chunk. --}}
                                <button type="button" class="chunk-delete flex h-7 w-7 items-center justify-center rounded-lg border border-zinc-700 p-0 text-zinc-500 hover:border-red-700/60 hover:text-red-300"
                                        aria-label="Delete this chunk."
                                        title="Delete this chunk."><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 4.5h11"></path><path d="M5.5 4.5V3.2c0-.4.3-.7.7-.7h3.6c.4 0 .7.3.7.7v1.3"></path><path d="M4 4.5l.7 8.3c0 .4.3.7.7.7h5.2c.4 0 .7-.3.7-.7l.7-8.3"></path><path d="M6.5 7v3.5M9.5 7v3.5"></path></svg></button>
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
                    {{-- The emphasized script field (design "chunk-script-field" 2A): the
                         wrapper's data-replicated-value drives the auto-grow replica and
                         MUST mirror the textarea (the input handler keeps them in sync).
                         Metrics (size/padding/border-width) live in .chunk-script in
                         app.css — shared with the replica — so only colors are utilities
                         here; setDirty() still owns the amber border-edge swap. --}}
                    <div class="chunk-script mt-3" data-replicated-value="{{ $chunk->text }}">
                        <textarea class="chunk-text w-full rounded-[10px] border-edge bg-inset text-zinc-100 transition-colors hover:border-zinc-400 hover:bg-panel hover:text-white focus:border-accent focus:text-white focus:outline-none focus:ring-[3px] focus:ring-accent/15"
                                  rows="1" data-original="{{ $chunk->text }}">{{ $chunk->text }}</textarea>
                        <span class="chunk-script-glyph" aria-hidden="true">✎</span>
                    </div>

                    {{-- Turbo renders these tags as actual sounds; a click inserts one
                         at the cursor. Engine-scoped like the knobs (swapped live by
                         syncKnobEngines via data-engine-help); the wrapper is a plain
                         block so `hidden` alone is safe — the flex lives one level in. --}}
                    <div data-engine-help="chatterbox-turbo" @class(['chunk-sound-tags', 'hidden' => $chunkModel !== 'chatterbox-turbo'])>
                        {{-- Chips show the literal token they insert; the brackets are
                             dimmed like editor syntax so the tag words stay scannable.
                             Slim: the row ships empty and mountTagChips() clones the
                             chips from the page template as the card nears view. --}}
                        <div class="chunk-tag-slot mt-1.5 flex flex-wrap items-center gap-1.5" @if($slimCards) data-lazy-chips="1" @endif>
                            <span class="cursor-help text-[11px] uppercase tracking-wide text-zinc-500" title="Chatterbox Turbo renders these as actual sounds, not words. They work best mid-sentence — a tag at the very end of a chunk can trip take QA.">Sound tags</span>
                            @unless($slimCards)
                                @foreach(\App\Services\Tts\ParalinguisticTags::TAGS as $tag)
                                    <button type="button" class="chunk-tag-insert whitespace-nowrap rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-0.5 font-mono text-xs text-zinc-200 transition hover:border-cyan-400/40 hover:bg-cyan-500/10 hover:text-cyan-100 active:bg-cyan-500/20"
                                            data-tag="[{{ $tag }}]" title="Insert [{{ $tag }}] at the cursor"><span class="text-zinc-500">[</span>{{ $tag }}<span class="text-zinc-500">]</span></button>
                                @endforeach
                            @endunless
                        </div>
                    </div>

                    {{-- data-duration-ms: the selected take's recorded length, so the
                         readout shows the duration without any metadata request.
                         preload="none" (like the take players) — with it on "metadata"
                         a big project fired one ranged-audio request per chunk on page
                         load, enough to saturate the server and freeze the page. --}}
                    @php $selectedTakeDuration = collect($takesByChunk[$chunk->id]['takes'])->firstWhere('selected', true)['duration_ms'] ?? null; @endphp
                    {{-- Lazy mode (tts.studio_lazy_players): no <audio> in the markup at
                         all — the URL parks on data-audio-src and ensureNative() builds
                         the element on first tap. WebKit allocates media plumbing per
                         <audio>, so hundreds of them froze iPad Safari. --}}
                    <div class="aplayer aplayer--chunk mt-3 rounded-[12px] border border-white/8 bg-inset px-3.5 py-2.5 {{ $chunk->isCompleted() ? '' : 'hidden' }}"
                         @if($selectedTakeDuration) data-duration-ms="{{ $selectedTakeDuration }}" @endif
                         @if($lazyPlayers) data-audio-src="{{ $chunk->isCompleted() ? route('admin.studio.projects.chunks.audio', [$project, $chunk]) : '' }}" data-native-class="chunk-audio" @endif>
                        <button type="button" class="aplayer__btn" aria-label="Play chunk audio"><span class="aplayer__icon"></span></button>
                        <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                        <span class="aplayer__time">0:00 / 0:00</span>
                        @unless($lazyPlayers)
                        <audio class="chunk-audio aplayer__native" preload="none"
                               @if($chunk->isCompleted()) src="{{ route('admin.studio.projects.chunks.audio', [$project, $chunk]) }}" @endif></audio>
                        @endunless
                    </div>

                    {{-- Take history + per-chunk tuning override. Slim cards ship only the
                         takes list; the chunk's overrides park on data-tune-settings and
                         ensureTune() clones the panel from #chunk-tune-template on mount. --}}
                    <details class="chunk-tune mt-3 text-sm text-zinc-400" @if(!empty($chunk->settings) || $chunk->takes->count() > 1) open @endif
                             @if($slimCards) data-lazy-tune="1" data-tune-settings='@json($chunk->settings ?: (object) [])' @endif>
                        <summary class="cursor-pointer select-none text-xs hover:text-zinc-200">Takes &amp; tuning</summary>

                        {{-- Every render is kept here — audition a prior take, Select the one
                             that sounded best (which also restores the text + tuning it was
                             rendered from), or delete the duds. Populated by the JS from
                             data-takes (and refreshed after each render). --}}
                        <ul class="chunk-takes mt-2 space-y-1.5"></ul>

                        @unless($slimCards)
                            @include('admin.studio.projects._tune-panel')
                        @endunless
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
                            {{-- Lazy mode: the seam audio only ever holds a stitched blob, so
                                 data-audio-src stays empty — ensureNative() builds a bare
                                 element when the preview is first stitched. --}}
                            <div class="aplayer aplayer--chunk rounded-xl border border-white/8 bg-inset px-4 py-3.5"
                                 @if($lazyPlayers) data-audio-src="" data-native-class="seam-audio" @endif>
                                <button type="button" class="aplayer__btn" aria-label="Play stitched preview"><span class="aplayer__icon"></span></button>
                                <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
                                <span class="aplayer__time">0:00 / 0:00</span>
                                @unless($lazyPlayers)
                                <audio class="seam-audio aplayer__native"></audio>
                                @endunless
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

        {{-- Mobile bottom transport dock (design 12B, ≤640px only). Nothing pins at
             the top on a phone — the header above scrolls away and this dock pins
             the transport where thumbs are: play, scrubber, a one-line status, and
             the primary download. The handle (tap or swipe up) expands the
             production sheet below. Show/hide + transitions live in app.css
             (mobile-nav-sheet pattern); initStudioMobileDock() drives everything. --}}
        <div id="project-dock" class="fixed inset-x-0 bottom-0 z-40 border-t border-accent/35 bg-sticky px-4 pb-[calc(env(safe-area-inset-bottom,0px)+8px)] shadow-[0_-14px_30px_-8px_rgba(0,0,0,0.8)] sm:hidden">
            <button type="button" id="dock-handle" aria-controls="project-sheet" aria-expanded="false"
                    aria-label="Open production controls"
                    class="mx-auto flex h-7 w-28 items-center justify-center">
                <span class="h-1 w-9 rounded-full bg-white/25" aria-hidden="true"></span>
            </button>
            <div class="flex items-center gap-3">
                {{-- Same anatomy classes as the page players so the icon, spinner,
                     and playing states come from the .aplayer CSS — but no
                     .aplayer__native inside: this transport drives the ONE final
                     audio element (inside the sheet's hero player) from outside. --}}
                <div id="dock-player" class="aplayer aplayer--dock shrink-0">
                    <button type="button" id="dock-play" class="aplayer__btn" aria-label="Play or pause the project audio"><span class="aplayer__icon"></span></button>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <div id="dock-track" class="relative h-1 flex-1 cursor-pointer rounded-full bg-white/15">
                            <div id="dock-fill" class="absolute inset-y-0 left-0 w-0 rounded-full bg-accent"></div>
                        </div>
                        <span id="dock-time" class="shrink-0 font-mono text-[10px] text-zinc-500">0:00 / 0:00</span>
                    </div>
                    {{-- One-line project status: mirrors the status pill (or the live
                         status/run message while one shows) + chunk count + spend.
                         The live region: with the sheet closed its status line is
                         visibility:hidden, so this is what announces on a phone. --}}
                    <div class="mt-1 flex items-center gap-1.5 text-[11px] leading-tight" role="status" aria-live="polite">
                        <span id="dock-dot" class="h-1.5 w-1.5 shrink-0 rounded-full bg-ok" aria-hidden="true"></span>
                        <span id="dock-status" class="truncate text-ok">{{ $statusVal }}</span>
                        <span id="dock-meta" class="truncate text-zinc-500"></span>
                    </div>
                </div>
                {{-- The everyday primary, abbreviated. Proxies whichever download
                     leads in the action cluster (preview, or the approved package
                     once sealed); dimmed while neither is available. Approve never
                     appears here — no irreversible action one accidental tap away. --}}
                <a id="dock-primary" href="{{ route('admin.studio.projects.audio', ['project' => $project, 'dl' => 1]) }}"
                   class="shrink-0 rounded-[9px] bg-accent px-3.5 py-2.5 text-xs font-bold text-accent-on">↓ Preview</a>
            </div>
        </div>

        {{-- Production sheet (dock expanded): full title, stats + status pill + ⋯
             menu, the hero player, Voice/Format, and the full action cluster —
             the pieces the desktop header shows in rows 2–3, adopted here below
             sm by initStudioMobileDock(). Approve arrives with them, full-width
             and last so it stays deliberate. --}}
        <div id="project-sheet-scrim" class="fixed inset-0 z-40 bg-black/55 sm:hidden" aria-hidden="true"></div>
        <div id="project-sheet" role="dialog" aria-modal="true" aria-label="Production controls"
             class="fixed inset-x-0 bottom-0 z-40 max-h-[85dvh] overflow-y-auto rounded-t-[18px] border-t border-accent/35 bg-sticky px-4 pb-[calc(env(safe-area-inset-bottom,0px)+12px)] shadow-[0_-18px_40px_-8px_rgba(0,0,0,0.85)] sm:hidden">
            <button type="button" id="sheet-handle" aria-label="Close production controls"
                    class="mx-auto flex h-8 w-28 items-center justify-center">
                <span class="h-1 w-9 rounded-full bg-white/25" aria-hidden="true"></span>
            </button>
            <div id="sheet-title" class="text-[15px] font-bold leading-snug text-zinc-100">{{ $project->title }}</div>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-zinc-500">
                <span><span id="sheet-chunks" class="font-semibold text-zinc-200">{{ $chunks->count() }}</span> chunks</span>
                @if(\App\Support\GenerationCost::enabled())
                    <span>spend <span id="sheet-spend" class="font-mono text-zinc-200">{{ $projectSpendReadout['label'] }}</span></span>
                @endif
                <span id="sheet-slot-pill" class="inline-flex"></span>
                <span id="sheet-slot-menu" class="ml-auto"></span>
            </div>
            <div id="sheet-slot-player"></div>
            <div id="sheet-slot-config" class="mt-3"></div>
            <div id="sheet-slot-actions" class="mt-3"></div>
            <div id="sheet-slot-status"></div>
        </div>

        {{-- Follow-playback's "come back" chip: appears when the user scrolls away
             while the final plays with Follow on (auto-scroll suspends so the page
             never fights their scroll); a tap re-centers the playing chunk and
             re-engages following. Positioned above the mobile dock; JS toggles
             hidden/inline-flex (initStudioFollowPlayback). --}}
        <button type="button" id="follow-resume"
                class="fixed bottom-[calc(env(safe-area-inset-bottom,0px)+72px)] left-1/2 z-30 hidden -translate-x-1/2 items-center gap-1.5 rounded-full border border-cyan-400/40 bg-zinc-900/95 px-4 py-2 text-xs font-medium text-cyan-200 shadow-[0_10px_30px_-8px_rgba(0,0,0,0.8)] transition hover:bg-cyan-500/15 sm:bottom-6">↧ Resume following</button>

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
