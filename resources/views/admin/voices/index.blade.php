<x-layout title="Voices" description="Each voice maps a voice_id to a reference clip for zero-shot cloning.">
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.voices.create') }}" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Add voice</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-900/60 text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-4 py-3">voice_id</th>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Reference</th>
                    <th class="px-4 py-3">Seed</th>
                    <th class="px-4 py-3">Preview</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800">
                @forelse($voices as $voice)
                    <tr class="align-top hover:bg-zinc-900/40">
                        <td class="px-4 py-3">
                            <button data-copy="{{ $voice->slug }}" class="font-mono text-zinc-200 hover:text-cyan-400" title="Click to copy">{{ $voice->slug }}</button>
                        </td>
                        <td class="px-4 py-3 text-zinc-300">{{ $voice->name }}</td>
                        <td class="px-4 py-3">
                            @if($voice->reference_audio_path)
                                <span class="rounded-md bg-emerald-500/10 px-2 py-1 text-xs text-emerald-400">Yes</span>
                            @else
                                <span class="rounded-md bg-zinc-700/40 px-2 py-1 text-xs text-zinc-400">None</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-zinc-400">{{ $voice->settings['seed'] ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <button data-test-voice="{{ route('admin.voices.test', $voice) }}" data-audio-target="#audio-{{ $voice->id }}"
                                    @disabled(! $voice->reference_audio_path)
                                    class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40">Test</button>
                            <audio id="audio-{{ $voice->id }}" controls class="mt-2 hidden w-44"></audio>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('admin.voices.destroy', $voice) }}" onsubmit="return confirm('Delete this voice and its reference clip?')">@csrf @method('DELETE')
                                <button class="rounded-md border border-red-500/30 px-2.5 py-1 text-xs text-red-400 hover:bg-red-500/10">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-500">No voices yet. <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.create') }}">Add one</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
