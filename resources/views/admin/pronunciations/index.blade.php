<x-layout title="Pronunciations" description="Your personal respelling dictionary — applied to your text before it reaches the voice. Private to you.">
    <x-getting-started page="pronunciations" title="Welcome to Pronunciations">
        <p class="mt-1.5 max-w-[760px] text-sm text-zinc-400">When a voice mispronounces a name, an acronym, or a bit of jargon, add a respelling here — like “DDEV” said as “dee dev.” Respellings are applied to your text before it reaches the voice, on every generation.</p>
        <ul class="mt-3 max-w-[760px] list-disc space-y-1.5 pl-5 text-[13px] leading-relaxed text-zinc-400">
            <li>Alias also suggests respellings when you create a Studio project — the ones you approve are saved here automatically.</li>
            <li>Press ▶ next to any respelling to hear your default voice say it before you commit.</li>
            <li>Your dictionary is private to you and applies only to your own text.</li>
        </ul>
    </x-getting-started>

    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.pronunciations.create') }}" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Add pronunciation</a>
    </div>

    <p id="pron-test-status" role="status" aria-live="polite" class="mb-2 text-sm text-zinc-400"></p>

    <div class="overflow-x-auto rounded-xl border border-zinc-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-900/60 text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-4 py-3">Term</th>
                    <th class="px-4 py-3">Respelling</th>
                    <th class="px-4 py-3">Match</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Source</th>
                    <th class="px-4 py-3">Approved</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800">
                @forelse($entries as $entry)
                    <tr class="align-top hover:bg-zinc-900/40">
                        <td class="px-4 py-3 font-mono text-zinc-200">{{ $entry->term }}</td>
                        <td class="px-4 py-3 text-zinc-300">
                            <div class="flex items-center gap-2">
                                <span>{{ $entry->phonetic }}</span>
                                <button type="button" data-pron-test data-url="{{ route('admin.pronunciations.test') }}"
                                        data-phonetic="{{ $entry->phonetic }}" aria-label="Hear “{{ $entry->phonetic }}”"
                                        title="Hear this respelling spoken by your default voice"
                                        class="inline-flex items-center rounded-md border border-zinc-700 px-2 py-0.5 text-xs text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">▶</button>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-zinc-400">
                            {{ $entry->match_mode === 'case_insensitive' ? 'any case' : 'exact case' }}
                            @if($entry->engines)
                                {{-- Scoped entry: only these engines get the respelling. --}}
                                <span class="mt-1 block text-xs text-cyan-500/80" title="Other engines read the term as written">
                                    {{ collect($entry->engines)->map(fn ($k) => \App\Services\Tts\ModelCatalog::label($k))->implode(' · ') }} only
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-400">{{ $entry->category ? str_replace('_', ' ', $entry->category) : '—' }}</td>
                        <td class="px-4 py-3 text-zinc-400">{{ $entry->source }}</td>
                        <td class="px-4 py-3">
                            @if($entry->approved)
                                <span class="rounded-md bg-emerald-500/10 px-2 py-1 text-xs text-emerald-400">Yes</span>
                            @else
                                <span class="rounded-md bg-zinc-500/10 px-2 py-1 text-xs text-zinc-400">No</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <a href="{{ route('admin.pronunciations.edit', $entry) }}" class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800">Edit</a>
                                <form method="POST" action="{{ route('admin.pronunciations.destroy', $entry) }}"
                                      data-confirm="New generations will say the term as written, without the respelling."
                                      data-confirm-title="Remove this pronunciation?"
                                      data-confirm-label="Remove">@csrf @method('DELETE')
                                    <button class="rounded-md border border-red-500/30 px-2.5 py-1 text-xs text-red-400 hover:bg-red-500/10">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-zinc-500">No pronunciations yet. <a class="text-cyan-400 hover:underline" href="{{ route('admin.pronunciations.create') }}">Add one</a>, or they’ll accumulate as you create Studio projects.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
