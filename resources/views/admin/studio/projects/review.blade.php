<x-layout title="Review pronunciations" description="We spotted terms a TTS voice often mispronounces. Approve a respelling to apply it now and teach it to your dictionary — next time it's automatic.">
    <form method="POST" action="{{ route('admin.studio.projects.apply') }}" class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
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
                    <label class="flex items-center gap-2" title="Apply this respelling and save it to your dictionary">
                        <input type="checkbox" name="approve[]" value="{{ $i }}" @checked(!empty($s['checked']))
                               class="h-4 w-4 rounded border-zinc-600 bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30">
                        <span class="font-mono text-sm text-zinc-200">{{ $s['term'] }}</span>
                    </label>

                    <span class="text-zinc-500" aria-hidden="true">&rarr;</span>

                    <input name="substitutions[{{ $i }}][phonetic]" value="{{ $s['phonetic'] }}" aria-label="Spoken respelling for {{ $s['term'] }}"
                           id="pron-phonetic-{{ $i }}"
                           class="w-48 rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">

                    <button type="button" data-pron-test data-url="{{ route('admin.pronunciations.test') }}"
                            data-input="#pron-phonetic-{{ $i }}" data-voice="{{ $voice->id }}"
                            title="Hear this respelling spoken by the project's voice — edits to the field count"
                            class="inline-flex items-center gap-1.5 rounded-md border border-zinc-700 px-2.5 py-1 text-xs text-zinc-300 hover:bg-zinc-800">▶ Test</button>

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
                        <span class="rounded bg-zinc-800 px-2 py-0.5 text-xs text-zinc-500" title="You skipped this term before, so it stays unchecked — check it to approve it after all.">skipped before</span>
                    @endif
                    @if(!empty($s['note']))
                        <span class="w-full text-xs text-zinc-500 sm:w-auto sm:flex-1">{{ $s['note'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-xs text-zinc-500">Unchecked terms are skipped — your text is created as-is for those, and the skip is remembered so they won't be pre-checked next time. Edit a respelling before applying if it reads wrong, and use <span class="text-zinc-400">▶ Test</span> to hear it before you decide.</p>
        <p id="pron-test-status" role="status" aria-live="polite" class="text-sm text-zinc-400"></p>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Apply approved &amp; continue</button>
            <a href="{{ route('admin.studio.projects.create') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Back</a>
        </div>

        @if($provenance)
            <p class="text-xs text-zinc-600">Detected via {{ $provenance['provider'] ?? 'llm' }}@isset($provenance['model']) · {{ $provenance['model'] }}@endisset.</p>
        @endif
    </form>
</x-layout>
