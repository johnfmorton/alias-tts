<x-layout title="Studio" description="Edit generated speech like a document — fix one sentence, swap in a better take, or retune the voice without rebuilding the whole file.">
    @php
        // Persisted in the URL (?tab=projects|inspector) so refresh/back and the
        // paginator (which reloads the page) land on the right view. app.js swaps
        // the panels client-side on click without a reload.
        $activeTab = request('tab') === 'inspector' ? 'inspector' : 'projects';

        // Regular users only ever see their own projects, so the Owner column is
        // SuperAdmin-only — the grid drops that track for everyone else. (All
        // four literals must stay in this file for Tailwind's scanner to emit
        // them — never build these class names with string manipulation.)
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $projectGridHead = $isSuperAdmin
            ? 'grid-cols-[2.6fr_0.8fr_1.2fr_0.9fr_1fr]'
            : 'grid-cols-[2.6fr_0.8fr_1.2fr_1fr]';
        $projectGridRow = $isSuperAdmin
            ? 'sm:grid-cols-[2.6fr_0.8fr_1.2fr_0.9fr_1fr]'
            : 'sm:grid-cols-[2.6fr_0.8fr_1.2fr_1fr]';

        $projectStyles = [
            'ready' => 'border-ok/25 bg-ok/10 text-ok',
            'stale' => 'border-warn/30 bg-warn/10 text-warn',
            'draft' => 'border-white/12 text-zinc-400',
        ];
        $badgeBase = 'inline-flex shrink-0 items-center rounded-lg border px-[11px] py-1 text-[12.5px] font-semibold';
    @endphp

    <x-getting-started page="studio" title="Welcome to Studio">
        <p class="mt-1.5 max-w-[760px] text-sm text-zinc-400">Studio is where a script becomes finished audio. A <strong class="font-semibold text-zinc-300">project</strong> splits your text into chunks — short passages generated one at a time, so fixing one flubbed sentence never means re-rendering the whole piece.</p>
        <ul class="mt-3 max-w-[760px] list-disc space-y-1.5 pl-5 text-[13px] leading-relaxed text-zinc-400">
            <li>Every render is kept as a <strong class="font-semibold text-zinc-300">take</strong> — regenerate a chunk until it reads the way you want, then keep the best one.</li>
            <li>When the whole read sounds right, download the stitched file — or seal it and get a verifiable receipt of exactly what you approved.</li>
            <li>The <strong class="font-semibold text-zinc-300">Inspector</strong> tab shows how any text will be cleaned up and split into chunks, before you spend a render.</li>
        </ul>
    </x-getting-started>

    {{-- Segmented tab control — only one view is on screen at a time. --}}
    <div data-studio-tabs role="tablist" aria-label="Studio views"
         class="mb-[22px] inline-flex gap-1 rounded-xl border border-white/10 bg-panel p-1">
        <button type="button" data-studio-tab="projects" role="tab"
                aria-selected="{{ $activeTab === 'projects' ? 'true' : 'false' }}"
                class="inline-flex cursor-pointer items-center gap-2 rounded-[9px] px-[18px] py-[9px] text-sm font-semibold transition {{ $activeTab === 'projects' ? 'bg-accent text-accent-on' : 'text-zinc-400 hover:text-zinc-100' }}">
            Projects
            <span data-tab-count
                  class="rounded-full px-[7px] py-px text-[11px] font-bold leading-none {{ $activeTab === 'projects' ? 'bg-accent-on/25' : 'bg-white/8' }}">{{ $projects->total() }}</span>
        </button>
        <button type="button" data-studio-tab="inspector" role="tab"
                aria-selected="{{ $activeTab === 'inspector' ? 'true' : 'false' }}"
                class="inline-flex cursor-pointer items-center gap-2 rounded-[9px] px-[18px] py-[9px] text-sm font-semibold transition {{ $activeTab === 'inspector' ? 'bg-accent text-accent-on' : 'text-zinc-400 hover:text-zinc-100' }}">
            Inspector
        </button>
    </div>

    {{-- ── View 1 — Projects ─────────────────────────────────────────────── --}}
    <div data-studio-panel="projects" @unless($activeTab === 'projects') class="hidden" @endunless>
        <div class="overflow-hidden rounded-[14px] border border-white/8 bg-panel">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.07] px-[22px] py-[18px]">
                <p class="text-[12.5px] text-zinc-400">Every project keeps its takes — pick the best read for each chunk.</p>
                <div class="flex flex-wrap items-center gap-3">
                    {{-- SuperAdmin-only: the owner filter, landing on the admin's own
                         projects; the hidden tab field lands the reload on this view. --}}
                    @if(auth()->user()->isSuperAdmin())
                        <x-owner-filter :action="route('admin.studio.index')" :owners="$owners" :owner-id="$ownerId">
                            <input type="hidden" name="tab" value="projects">
                        </x-owner-filter>
                    @endif
                    <a href="{{ route('admin.studio.projects.create') }}"
                       class="shrink-0 rounded-[9px] bg-accent px-4 py-[9px] text-[13.5px] font-semibold text-accent-on transition hover:bg-cyan-400">New project</a>
                </div>
            </div>

            @if($projects->total() === 0)
                <p class="px-[22px] py-6 text-sm text-zinc-500">{{ $ownerId !== null ? 'No projects for this owner.' : 'No projects yet.' }}</p>
            @else
                {{-- Column head (desktop only; on mobile each row carries its own meta line). --}}
                <div class="hidden {{ $projectGridHead }} gap-3 border-b border-white/[0.06] px-[22px] py-[11px] text-[11px] font-bold tracking-[0.6px] text-zinc-500 uppercase sm:grid">
                    <div>Name</div><div>Chunks</div><div>Updated</div>@if($isSuperAdmin)<div>Owner</div>@endif<div class="text-right">Status</div>
                </div>

                @php
                    // A code glyph shared by the API-origin badges — signals "made by a
                    // programmatic call" without leaning on color alone.
                    $apiGlyph = '<svg viewBox="0 0 20 20" fill="currentColor" class="size-3" aria-hidden="true"><path fill-rule="evenodd" d="M6.28 5.22a.75.75 0 0 1 0 1.06L2.56 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L.97 10.53a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Zm7.44 0a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 1 1-1.06-1.06L17.44 10l-3.72-3.72a.75.75 0 0 1 0-1.06ZM11.377 2.011a.75.75 0 0 1 .612.867l-2.5 14.5a.75.75 0 0 1-1.478-.255l2.5-14.5a.75.75 0 0 1 .866-.612Z" clip-rule="evenodd" /></svg>';
                @endphp

                @foreach($projects as $project)
                    <a href="{{ route('admin.studio.projects.show', $project) }}"
                       data-busy data-busy-label="Opening project…"
                       class="grid grid-cols-[1fr_auto] items-center gap-3 border-b border-white/[0.05] px-[22px] py-[15px] transition last:border-b-0 hover:bg-white/[0.03] {{ $projectGridRow }}">
                        <div class="min-w-0">
                            <div class="truncate text-[15px] font-semibold text-zinc-100">{{ $project->title }}</div>
                            <div class="mt-0.5 text-xs text-zinc-500 sm:hidden">{{ $project->chunks_count }} chunk(s) · {{ $project->updated_at->diffForHumans() }}@if($isSuperAdmin && $project->relationLoaded('user') && $project->user) · {{ $project->user->name }}@endif</div>
                        </div>
                        <div class="hidden text-[13px] text-zinc-400 sm:block">{{ $project->chunks_count }}</div>
                        <div class="hidden text-[13px] text-zinc-400 sm:block">{{ $project->updated_at->diffForHumans() }}</div>
                        @if($isSuperAdmin)
                            <div class="hidden truncate text-[13px] text-zinc-400 sm:block">{{ $project->relationLoaded('user') && $project->user ? $project->user->name : '—' }}</div>
                        @endif
                        <div class="flex items-center justify-end gap-2">
                            @if($project->origin === 'api_failure')
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-md border border-red-500/30 bg-red-500/10 px-2 py-0.5 text-xs text-red-300" title="{{ $project->failure_reason }}">{!! $apiGlyph !!}<span class="hidden sm:inline">API failure</span></span>
                            @elseif($project->origin === 'api')
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-md border border-violet-500/30 bg-violet-500/10 px-2 py-0.5 text-xs text-violet-300" title="Generated by a text-to-speech API call (ElevenLabs / OpenAI compatible).">{!! $apiGlyph !!}<span class="hidden sm:inline">API</span></span>
                            @elseif($project->origin === 'api_project')
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-md border border-violet-500/30 bg-violet-500/10 px-2 py-0.5 text-xs text-violet-300" title="Created by a /v1/projects API call — chunked and ready to generate.">{!! $apiGlyph !!}<span class="hidden sm:inline">API project</span></span>
                            @endif
                            <span class="{{ $badgeBase }} {{ $projectStyles[$project->status->value] ?? $projectStyles['draft'] }}">{{ $project->status->value }}</span>
                        </div>
                    </a>
                @endforeach

                {{-- Pagination footer — 6 per page; label + count stay in sync with the total. --}}
                <div class="flex items-center justify-between gap-3 bg-inset px-[22px] py-[14px]">
                    <span class="text-[12.5px] text-zinc-500">{{ $projects->firstItem() }}–{{ $projects->lastItem() }} of {{ $projects->total() }} projects</span>
                    @if($projects->hasPages())
                        @php
                            $last = $projects->lastPage();
                            $cur = $projects->currentPage();
                            $from = max(1, $cur - 1);
                            $to = min($last, $cur + 1);
                            $pageClass = 'inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-lg border border-white/10 px-1 text-[13px] text-zinc-300 transition hover:bg-white/[0.04]';
                            $activeClass = 'inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-lg bg-accent px-1 text-[13px] font-bold text-accent-on';
                            $chev = 'inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg border border-white/10 text-zinc-300 transition hover:bg-white/[0.04]';
                            $chevOff = 'inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg border border-white/10 text-zinc-600';
                            $ellipsis = 'px-1 text-zinc-600';
                        @endphp
                        <div class="flex items-center gap-1.5">
                            @if($projects->onFirstPage())
                                <span class="{{ $chevOff }}" aria-hidden="true">‹</span>
                            @else
                                <a href="{{ $projects->previousPageUrl() }}" class="{{ $chev }}" aria-label="Previous page">‹</a>
                            @endif

                            @if($from > 1)
                                <a href="{{ $projects->url(1) }}" class="{{ $pageClass }}">1</a>
                                @if($from > 2)<span class="{{ $ellipsis }}">…</span>@endif
                            @endif

                            @for($p = $from; $p <= $to; $p++)
                                @if($p === $cur)
                                    <span class="{{ $activeClass }}" aria-current="page">{{ $p }}</span>
                                @else
                                    <a href="{{ $projects->url($p) }}" class="{{ $pageClass }}">{{ $p }}</a>
                                @endif
                            @endfor

                            @if($to < $last)
                                @if($to < $last - 1)<span class="{{ $ellipsis }}">…</span>@endif
                                <a href="{{ $projects->url($last) }}" class="{{ $pageClass }}">{{ $last }}</a>
                            @endif

                            @if($projects->hasMorePages())
                                <a href="{{ $projects->nextPageUrl() }}" class="{{ $chev }}" aria-label="Next page">›</a>
                            @else
                                <span class="{{ $chevOff }}" aria-hidden="true">›</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- ── View 2 — Inspector ────────────────────────────────────────────── --}}
    <div data-studio-panel="inspector" @unless($activeTab === 'inspector') class="hidden" @endunless>
        <p class="mb-4 text-[12.5px] text-zinc-400">Paste a block of text to see exactly how it will be cleaned, chunked, and priced — audition chunks, then create a project from your findings. Chunk renders you make here carry over into the project.</p>

        <div id="studio"
             data-preview-url="{{ route('admin.studio.preview') }}"
             data-synthesize-url="{{ route('admin.studio.synthesize') }}"
             data-stitch-url="{{ route('admin.studio.stitch') }}"
             data-concat-url="{{ route('admin.studio.concat') }}"
             data-advanced-url="{{ route('admin.studio.advanced') }}"
             data-suggestions-url="{{ route('admin.studio.pronunciation.suggestions') }}"
             data-approve-url="{{ route('admin.studio.pronunciation.approve') }}"
             data-create-project-url="{{ route('admin.studio.projects.from-inspector') }}">

            {{-- Input --}}
            <div class="mb-6 rounded-[14px] border border-white/8 bg-panel p-5">
                <label for="studio-text" class="mb-1.5 block text-sm font-medium">Text</label>
                <textarea id="studio-text" rows="8"
                          class="w-full rounded-lg border border-edge bg-inset px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
                          placeholder="Paste a block of text…"></textarea>

                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <label for="studio-voice" class="mb-1.5 block text-sm text-zinc-400">Voice</label>
                        @if($voices->isEmpty())
                            <p class="text-sm text-zinc-500">No voices — <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.create') }}">add one</a> to generate audio.</p>
                        @else
                            <select id="studio-voice" class="rounded-lg border border-edge bg-inset px-3 py-2 text-sm">
                                @foreach($voices as $v)
                                    <option value="{{ $v->slug }}" data-model="{{ \App\Services\Tts\ModelCatalog::forVoice($v) }}">{{ $v->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <button type="button" id="studio-preview"
                            class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-accent-on hover:bg-cyan-400">
                        Preview chunks
                    </button>
                </div>

                {{-- Advanced tuning: a per-user toggle (off by default) reveals the
                     Chatterbox knobs. These shape THIS preview only — a voice's saved
                     sound is tuned (A/B bench, presets) on its edit page. --}}
                <label class="mt-4 inline-flex cursor-pointer items-center gap-2 text-sm text-zinc-300">
                    <input id="studio-advanced-toggle" type="checkbox" @checked(auth()->user()->studio_advanced)
                           class="rounded border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
                    Advanced tuning <span class="text-xs text-zinc-500">— per-preview knobs</span>
                </label>

                <div id="studio-advanced" @unless(auth()->user()->studio_advanced) class="hidden" @endunless>
                    {{-- Single-shot native knobs (Whole / Stitched / per-chunk Generate).
                         The chosen voice's engine decides which set shows (JS syncs on
                         voice change); the first voice in the list is the initial pick. --}}
                    @php $inspectorModel = \App\Services\Tts\ModelCatalog::forVoice($voices->first()); @endphp
                    <div id="studio-knobs" class="mt-3 flex flex-wrap gap-4 rounded-lg border border-white/8 bg-inset/60 p-3 text-sm text-zinc-400">
                        <x-tuning-knob knob="exaggeration" label="Exaggeration" hint="neutral 0.5"
                                       :min="0.25" :max="2" :step="0.05" placeholder="0.50" inputClass="studio-exaggeration" class="w-48" :hidden="$inspectorModel !== 'chatterbox'" />
                        <x-tuning-knob knob="cfg_weight" label="CFG / Pace"
                                       :min="0.2" :max="1" :step="0.05" placeholder="0.50" inputClass="studio-cfg" class="w-48" :hidden="$inspectorModel !== 'chatterbox'" />
                        <x-tuning-knob knob="top_p" label="Top-p" hint="neutral 0.95"
                                       :min="0.5" :max="1" :step="0.01" placeholder="0.95" inputClass="studio-top-p" class="w-48" :hidden="$inspectorModel !== 'chatterbox-turbo'" />
                        <x-tuning-knob knob="top_k" label="Top-k" hint="neutral 1000"
                                       :min="1" :max="2000" :step="1" placeholder="1000" inputClass="studio-top-k" class="w-48" :hidden="$inspectorModel !== 'chatterbox-turbo'" />
                        <x-tuning-knob knob="repetition_penalty" label="Rep. penalty" hint="neutral 1.2"
                                       :min="1" :max="2" :step="0.05" placeholder="1.20" inputClass="studio-repetition-penalty" class="w-48" :hidden="$inspectorModel !== 'chatterbox-turbo'" />
                        <x-tuning-knob knob="temperature" label="Temperature" hint="neutral 0.8"
                                       :min="0.5" :max="1.5" :step="0.05" placeholder="0.80" inputClass="studio-temperature" class="w-48" />
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">
                        These knobs shape this preview only — nothing is saved. Like what you hear?
                        Make it the voice's default sound with the tuning bench on
                        <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.index') }}">its edit page</a>.
                    </p>
                </div>

                <div id="studio-status" class="mt-3 text-sm text-zinc-400" role="status" aria-live="polite"></div>
            </div>

            {{-- Results (revealed after a preview) --}}
            <div id="studio-results" class="hidden space-y-6">

                {{-- Normalized text + whole/stitched actions --}}
                <div class="rounded-[14px] border border-white/8 bg-panel">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/8 px-5 py-4">
                        <div>
                            <h2 class="font-semibold">Normalized text</h2>
                            <p class="mt-1 text-sm text-zinc-400">
                                Cleaned and normalized text of <span id="studio-norm-chars">0</span> chars,
                                split into <span id="studio-chunk-count">0</span> chunk(s), as shown below.
                            </p>
                            <p id="studio-estimate" class="mt-1 hidden text-sm text-zinc-400">
                                Estimated cost: <span id="studio-estimate-label" class="cursor-help font-medium text-zinc-200" title=""></span>
                                <span id="studio-balance" class="ml-2 hidden rounded-full border border-white/12 px-2 py-0.5 text-xs text-zinc-400"></span>
                            </p>
                        </div>
                        {{-- ONE render action: the production stitch — the only render that
                             sounds like what the app actually delivers. (A whole-text-as-one-
                             call button used to sit here as a seam-vs-model diagnostic; it was
                             removed because no delivery path ever produces that audio, so it
                             only ever billed money for an unrepresentative render. The concat
                             bar below covers seam diagnostics on the exact bytes heard.) --}}
                        <button type="button" id="studio-stitch" @disabled($voices->isEmpty())
                                title="Renders every chunk and joins them with the same trim and seam silence as a project's final file — this is what listeners actually get. Bills each chunk, like generating them all once."
                                class="rounded-lg border border-accent/50 bg-accent/10 px-3 py-2 text-sm text-accent hover:bg-accent/20 disabled:opacity-40">
                            ▶ Preview final audio
                        </button>
                    </div>
                    <div class="space-y-3 p-5">
                        <p id="studio-normalized" class="rounded-lg bg-inset p-3 text-sm break-words whitespace-pre-wrap text-zinc-300"></p>
                        <x-aplayer audio-id="studio-whole-audio" label="Play final-audio preview" class="hidden w-full" />
                    </div>
                </div>

                {{-- Pronunciation: dictionary respellings already applied above, plus
                     new LLM suggestions (when the pre-processor is enabled) with a
                     one-click add. Hidden until there is something to say. --}}
                <div id="studio-pron" class="hidden rounded-[14px] border border-white/8 bg-panel">
                    <div class="border-b border-white/8 px-5 py-4">
                        <h2 class="font-semibold">Pronunciation</h2>
                        <p class="mt-1 text-sm text-zinc-400">
                            Respellings from <a class="text-cyan-400 hover:underline" href="{{ route('admin.pronunciations.index') }}">your dictionary</a>
                            are already applied to the text above; new suggestions can be added to it here.
                        </p>
                    </div>
                    <div class="space-y-3 p-5">
                        <p id="studio-pron-applied" class="hidden rounded-lg bg-inset p-3 text-sm text-zinc-300"></p>
                        <div id="studio-pron-status" class="text-sm text-zinc-400" role="status" aria-live="polite"></div>
                        <ul id="studio-pron-suggestions" class="space-y-2"></ul>
                    </div>
                </div>

                {{-- Per-chunk cards --}}
                <div>
                    <h2 class="mb-3 font-semibold">Chunks <span class="text-sm font-normal text-zinc-500">— each plays the raw Chatterbox output</span></h2>

                    {{-- Concatenation preview: stitch the chunks you've generated through
                         the real production trim + seam join, to catch dropped words. --}}
                    <div id="studio-concat-bar" class="mb-4 hidden space-y-3 rounded-[14px] border border-white/8 bg-panel p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" id="studio-concat"
                                    class="rounded-lg border border-accent/50 bg-accent/10 px-3 py-2 text-sm text-accent hover:bg-accent/20">
                                ▶ Concatenate selected
                            </button>
                            <span class="text-sm text-zinc-400">
                                Tick “stitch test” on generated chunks, then hear them through production's trim + seam join.
                                One chunk alone tests the trim; two adjacent test the seam.
                            </span>
                        </div>
                        <div id="studio-concat-status" class="text-sm text-zinc-400" role="status" aria-live="polite"></div>
                        <x-aplayer audio-id="studio-concat-audio" label="Play stitched result" class="hidden w-full" />
                    </div>

                    <ol id="studio-chunks" class="space-y-3"></ol>
                </div>

                {{-- Closing CTA: keep the findings — create an editable project.
                     Stashed chunk renders ride along as real takes (no re-billing). --}}
                <div class="rounded-[14px] border border-accent/30 bg-panel p-5">
                    <h2 class="font-semibold">Create a project from this</h2>
                    <p class="mt-1 text-sm text-zinc-400">
                        Keep this breakdown as an editable Studio project — regenerate chunks one at a time, pick takes, and build the final file.<span id="studio-carry-note" class="hidden text-zinc-300"></span>
                    </p>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="max-w-md grow">
                            <label for="studio-project-title" class="mb-1.5 block text-sm text-zinc-400">Title <span class="text-xs text-zinc-500">— optional</span></label>
                            <input id="studio-project-title" type="text" maxlength="200"
                                   class="w-full rounded-lg border border-edge bg-inset px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
                                   placeholder="Named from the text if left blank">
                        </div>
                        <button type="button" id="studio-create-project" @disabled($voices->isEmpty())
                                class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-accent-on hover:bg-cyan-400 disabled:opacity-40">
                            Create project
                        </button>
                    </div>
                    <div id="studio-create-status" class="mt-2 text-sm text-zinc-400" role="status" aria-live="polite"></div>
                </div>
            </div>
        </div>
    </div>
</x-layout>
