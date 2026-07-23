{{-- Shared term/respelling fields for the create + edit forms. $entry is null on create. --}}
<div>
    <label for="term" class="mb-1.5 block text-sm font-medium">Term</label>
    <input id="term" name="term" value="{{ old('term', $entry->term ?? '') }}" required placeholder="e.g. DDEV"
           class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 font-mono text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
    <p class="mt-1 text-xs text-zinc-500">Copied verbatim from your text (exact characters and casing).</p>
</div>
<div>
    <label for="phonetic" class="mb-1.5 block text-sm font-medium">Respelling</label>
    <div class="flex items-center gap-2">
        <input id="phonetic" name="phonetic" value="{{ old('phonetic', $entry->phonetic ?? '') }}" required placeholder="e.g. dee dev"
               class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
        <button type="button" data-pron-test data-url="{{ route('admin.pronunciations.test') }}" data-input="#phonetic"
                title="Hear this respelling spoken by your default voice"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-700 px-3 py-2 text-sm text-zinc-300 hover:bg-zinc-800">▶ Test</button>
    </div>
    <p class="mt-1 text-xs text-zinc-500">Plain ASCII the voice reads correctly. Keep a brand name's leading capital ("Aileus" for "Alias"); avoid lone capitals mid-word — the voice reads those as emphasis.</p>
    <p id="pron-test-status" role="status" aria-live="polite" class="mt-1 text-sm text-zinc-400"></p>
</div>
<div>
    <span class="mb-1.5 block text-sm font-medium">Applies to</span>
    @php($entryEngines = $entry->engines ?? null)
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-edge bg-zinc-900 px-3 py-2.5">
        @foreach(\App\Services\Tts\ModelCatalog::all() as $engineKey => $engineEntry)
            <label class="inline-flex items-center gap-1.5 text-sm text-zinc-300">
                <input type="checkbox" name="engines[]" value="{{ $engineKey }}"
                       @checked(in_array($engineKey, old('engines', $entryEngines ?? array_keys(\App\Services\Tts\ModelCatalog::all())), true))
                       class="rounded border-zinc-700 bg-zinc-950 text-cyan-500 focus:ring-cyan-500/30">
                {{ $engineEntry['label'] ?? $engineKey }}
            </label>
        @endforeach
    </div>
    <p class="mt-1 text-xs text-zinc-500">Engines pronounce differently — limit a respelling to the engines that need it (Qwen often reads terms correctly that Chatterbox can't). All checked = applies everywhere.</p>
</div>
<div>
    <label for="match_mode" class="mb-1.5 block text-sm font-medium">Match</label>
    @php($mm = old('match_mode', $entry->match_mode ?? 'case_sensitive'))
    <select id="match_mode" name="match_mode" class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
        <option value="case_sensitive" @selected($mm === 'case_sensitive')>Exact case (DDEV only)</option>
        <option value="case_insensitive" @selected($mm === 'case_insensitive')>Any case (DDEV / ddev / Ddev)</option>
    </select>
</div>
<div class="grid grid-cols-2 gap-4">
    <div>
        <label for="category" class="mb-1.5 block text-sm font-medium">Category <span class="text-zinc-500">(optional)</span></label>
        @php($cat = old('category', $entry->category ?? ''))
        <select id="category" name="category" class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            <option value="" @selected($cat === '')>—</option>
            @foreach($categories as $c)
                <option value="{{ $c }}" @selected($cat === $c)>{{ str_replace('_', ' ', $c) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="confidence" class="mb-1.5 block text-sm font-medium">Confidence <span class="text-zinc-500">(optional)</span></label>
        @php($conf = old('confidence', $entry->confidence ?? ''))
        <select id="confidence" name="confidence" class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
            <option value="" @selected($conf === '')>—</option>
            @foreach($confidences as $c)
                <option value="{{ $c }}" @selected($conf === $c)>{{ $c }}</option>
            @endforeach
        </select>
    </div>
</div>
<div>
    <label for="note" class="mb-1.5 block text-sm font-medium">Note <span class="text-zinc-500">(optional)</span></label>
    <input id="note" name="note" value="{{ old('note', $entry->note ?? '') }}"
           class="w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
</div>
