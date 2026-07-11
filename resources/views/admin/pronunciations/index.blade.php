<x-layout title="Pronunciations" description="Your personal respelling dictionary — applied to your text before it reaches the voice. Private to you.">
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
                        <td class="px-4 py-3 text-zinc-400">{{ $entry->match_mode === 'case_insensitive' ? 'any case' : 'exact case' }}</td>
                        <td class="px-4 py-3 text-zinc-400">{{ $entry->category ? str_replace('_', ' ', $entry->category) : '—' }}</td>
                        <td class="px-4 py-3 text-zinc-400">{{ $entry->source }}</td>
                        <td class="px-4 py-3">
                            @if($entry->approved)
                                <span class="rounded-md bg-emerald-500/10 px-2 py-1 text-xs text-emerald-400">Yes</span>
                            @else
                                <span class="rounded-md bg-amber-500/10 px-2 py-1 text-xs text-amber-400">Pending</span>
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
