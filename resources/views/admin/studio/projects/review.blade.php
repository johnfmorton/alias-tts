<x-layout title="Review pronunciations" description="Choose which respellings to apply. Applied terms are saved to your dictionary so next time it's automatic; skipped terms are left as written and remembered.">
    @php($applyCount = collect($suggestions)->filter(fn ($s) => ! empty($s['checked']))->count())

    <form method="POST" action="{{ route('admin.studio.projects.apply') }}" data-pron-review class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
        @csrf

        {{-- Carry the original project params straight through. --}}
        <input type="hidden" name="title" value="{{ $params['title'] }}">
        <input type="hidden" name="text" value="{{ $params['text'] }}">
        <input type="hidden" name="voice" value="{{ $params['voice'] }}">
        @if($params['seed'] !== null)<input type="hidden" name="seed" value="{{ $params['seed'] }}">@endif
        @if($params['preset'] !== null)<input type="hidden" name="preset" value="{{ $params['preset'] }}">@endif
        @if($params['exaggeration'] !== null)<input type="hidden" name="exaggeration" value="{{ $params['exaggeration'] }}">@endif
        @if($params['cfg_weight'] !== null)<input type="hidden" name="cfg_weight" value="{{ $params['cfg_weight'] }}">@endif
        @if($params['temperature'] !== null)<input type="hidden" name="temperature" value="{{ $params['temperature'] }}">@endif

        <div class="space-y-2">
            @foreach($suggestions as $i => $s)
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-950/50 p-3">
                    {{-- Decision: an explicit two-state control, not a bare checkbox.
                         The checkbox is the submitted source of truth (unchecked =
                         skipped); the segments are its visible, no-JS-safe face. --}}
                    <div class="inline-flex shrink-0 overflow-hidden rounded-lg border border-edge text-xs font-medium" role="group" aria-label="Apply or skip {{ $s['term'] }}">
                        <input type="checkbox" name="approve[]" id="approve-{{ $i }}" value="{{ $i }}" @checked(!empty($s['checked']))
                               data-pron-toggle aria-label="Apply the respelling for {{ $s['term'] }}"
                               class="peer sr-only">
                        <label for="approve-{{ $i }}" data-seg="apply"
                               class="cursor-pointer select-none px-3 py-1.5 text-zinc-500 transition-colors peer-checked:bg-cyan-500 peer-checked:text-zinc-950">Apply</label>
                        <label for="approve-{{ $i }}" data-seg="skip"
                               class="cursor-pointer select-none border-l border-edge bg-zinc-800/80 px-3 py-1.5 text-zinc-200 transition-colors peer-checked:bg-transparent peer-checked:text-zinc-500">Skip</label>
                    </div>

                    <span class="font-mono text-sm text-zinc-200">{{ $s['term'] }}</span>
                    <span class="text-zinc-500" aria-hidden="true">&rarr;</span>

                    <input name="substitutions[{{ $i }}][phonetic]" value="{{ $s['phonetic'] }}" aria-label="Spoken respelling for {{ $s['term'] }}"
                           id="pron-phonetic-{{ $i }}"
                           class="w-48 rounded-lg border border-edge bg-zinc-950 px-2 py-1 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">

                    <button type="button" data-pron-test data-url="{{ route('admin.pronunciations.test') }}"
                            data-input="#pron-phonetic-{{ $i }}" data-voice="{{ $voice->id }}"
                            title="Hear this respelling spoken by the project's voice — edits to the field count"
                            class="inline-flex items-center gap-1.5 rounded-md border border-zinc-700 px-2.5 py-1 text-xs text-zinc-300 hover:bg-zinc-800">&#9654; Test</button>

                    <input type="hidden" name="substitutions[{{ $i }}][term]" value="{{ $s['term'] }}">
                    <input type="hidden" name="substitutions[{{ $i }}][category]" value="{{ $s['category'] ?? '' }}">
                    <input type="hidden" name="substitutions[{{ $i }}][confidence]" value="{{ $s['confidence'] ?? '' }}">
                    <input type="hidden" name="substitutions[{{ $i }}][note]" value="{{ $s['note'] ?? '' }}">

                    @if(!empty($s['category']))
                        <span class="rounded bg-zinc-800 px-2 py-0.5 text-xs text-zinc-400">{{ $s['category'] }}</span>
                    @endif
                    @if(!empty($s['confidence']))
                        @php($c = $s['confidence'])
                        <span class="rounded px-2 py-0.5 text-xs {{ $c === 'high' ? 'bg-emerald-500/15 text-emerald-300' : ($c === 'low' ? 'bg-zinc-700/40 text-zinc-400' : 'bg-amber-500/15 text-amber-300') }}">{{ $c }}</span>
                    @endif
                    @if(!empty($s['previously_rejected']))
                        <span class="rounded bg-zinc-800 px-2 py-0.5 text-xs text-zinc-500" title="You skipped this term before, so it starts on Skip — switch it to Apply to use it after all.">skipped before</span>
                    @endif
                    @if(!empty($s['note']))
                        <span class="w-full text-xs text-zinc-500 sm:w-auto sm:flex-1">{{ $s['note'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-xs text-zinc-500"><span class="text-zinc-400">Apply</span> teaches the respelling to your dictionary and uses it now. <span class="text-zinc-400">Skip</span> leaves the term as written and remembers not to pre-check it again. Edit a respelling before applying if it reads wrong, and use <span class="text-zinc-400">&#9654; Test</span> to hear it first.</p>
        <p id="pron-test-status" role="status" aria-live="polite" class="text-sm text-zinc-400"></p>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Apply and continue</button>
            <p data-pron-tally data-total="{{ count($suggestions) }}" role="status" aria-live="polite" class="text-sm text-zinc-400">{{ $applyCount }} will be applied &middot; {{ count($suggestions) - $applyCount }} skipped</p>
            <a href="{{ route('admin.studio.projects.create') }}" class="ml-auto text-sm text-zinc-400 hover:text-zinc-200">Back</a>
        </div>

        @if($provenance)
            <p class="text-xs text-zinc-600">Detected via {{ $provenance['provider'] ?? 'llm' }}@isset($provenance['model']) &middot; {{ $provenance['model'] }}@endisset.</p>
        @endif
    </form>

    {{-- Already-decided terms that apply to this text with no checkbox to reach
         (approved entries are filtered out of the suggestions above). Surfaced
         so a silent respelling is visible — and removable — before you commit. --}}
    @if($autoApplied->isNotEmpty())
        <section data-pron-applied class="mt-5 space-y-3 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-medium text-zinc-200">Already in your dictionary</h2>
                <a href="{{ route('admin.pronunciations.index') }}" class="text-xs text-cyan-400 hover:text-cyan-300">Manage dictionary &rarr;</a>
            </div>
            <p class="text-xs text-zinc-500">These respellings are saved from before and will be applied to this text automatically. Remove one to stop using it — here and in future projects.</p>
            <ul class="space-y-2">
                @foreach($autoApplied as $e)
                    <li data-pron-applied-row class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-950/50 p-3">
                        <span class="font-mono text-sm text-zinc-200">{{ $e->term }}</span>
                        <span class="text-zinc-500" aria-hidden="true">&rarr;</span>
                        <span class="font-mono text-sm text-zinc-400">{{ $e->phonetic }}</span>
                        <button type="button" data-pron-remove
                                data-url="{{ route('admin.pronunciations.destroy', $e) }}"
                                data-term="{{ $e->term }}"
                                class="ml-auto inline-flex items-center rounded-md border border-zinc-700 px-2.5 py-1 text-xs text-zinc-300 hover:border-red-500/40 hover:bg-red-500/10 hover:text-red-300">Remove</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layout>
