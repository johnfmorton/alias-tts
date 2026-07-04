<x-layout title="Voices" description="Each voice maps a voice_id to a reference clip for zero-shot cloning. Your voices are yours alone — other users only ever see the built-ins and their own.">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="POST" action="{{ route('admin.voices.import') }}" enctype="multipart/form-data"
              data-busy data-busy-label="Importing…" class="flex items-center gap-2">
            @csrf
            <input type="file" name="archive" accept=".zip" required
                   class="block text-sm text-zinc-400 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:px-3 file:py-1.5 file:text-sm file:text-zinc-200 hover:file:bg-zinc-700">
            <button type="submit" class="rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800">Import</button>
        </form>
        <a href="{{ route('admin.voices.create') }}" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Add voice</a>
    </div>

    @if($voices->count() > 1)
        <p class="mb-2 text-xs text-zinc-500">Drag <span class="font-mono">⋮⋮</span> to reorder — this order is yours alone and drives every voice dropdown; the first voice is pre-selected for new projects.</p>
    @endif

    <div class="overflow-x-auto rounded-xl border border-zinc-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-900/60 text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="w-8 px-2 py-3"></th>
                    <th class="px-4 py-3">voice_id</th>
                    <th class="px-4 py-3">Name</th>
                    @if($showOwner)
                        <th class="px-4 py-3">Owner</th>
                    @endif
                    <th class="px-4 py-3">Reference</th>
                    <th class="px-4 py-3">Preview</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody id="voices-rows" data-order-url="{{ route('admin.voices.order') }}" class="divide-y divide-zinc-800">
                @forelse($voices as $voice)
                    <tr class="align-top hover:bg-zinc-900/40" data-voice-id="{{ $voice->id }}">
                        <td class="px-2 py-3">
                            <button type="button" data-drag-handle title="Drag to reorder"
                                    class="cursor-grab select-none px-1 text-zinc-600 hover:text-zinc-300 active:cursor-grabbing">⋮⋮</button>
                        </td>
                        <td class="px-4 py-3">
                            <button data-copy="{{ $voice->slug }}" class="font-mono text-zinc-200 hover:text-cyan-400" title="Click to copy">{{ $voice->slug }}</button>
                        </td>
                        <td class="px-4 py-3 text-zinc-300">
                            {{ $voice->name }}
                            @if($voice->isBuiltin())
                                <span class="ml-1 rounded-md bg-cyan-500/10 px-2 py-0.5 text-xs text-cyan-300" title="Built-in default voice — always available, protected from deletion">built-in</span>
                            @endif
                        </td>
                        @if($showOwner)
                            <td class="px-4 py-3 text-zinc-400">
                                {{ $voice->user?->name ?? 'Shared' }}
                            </td>
                        @endif
                        <td class="px-4 py-3">
                            @if($voice->reference_audio_path)
                                <span class="rounded-md bg-emerald-500/10 px-2 py-1 text-xs text-emerald-400">Yes</span>
                            @else
                                <span class="rounded-md bg-zinc-700/40 px-2 py-1 text-xs text-zinc-400">None</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <button data-test-voice="{{ route('admin.voices.test', $voice) }}" data-audio-target="#audio-{{ $voice->id }}"
                                    class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40">Test</button>
                            <audio id="audio-{{ $voice->id }}" controls class="mt-2 hidden w-44"></audio>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                @if($voice->isManagedBy(auth()->user()))
                                    <a href="{{ route('admin.voices.edit', $voice) }}" class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800">Edit</a>
                                @endif
                                <form method="POST" action="{{ route('admin.voices.duplicate', $voice) }}">@csrf
                                    <button class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800"
                                            title="{{ $voice->isManagedBy(auth()->user()) ? 'Clone this voice, clip and tuning included' : 'Shared voices can\'t be tuned directly — clone one you own' }}">Duplicate</button>
                                </form>
                                <a href="{{ route('admin.voices.export', $voice) }}" class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800">Export</a>
                                @if($voice->isManagedBy(auth()->user()) && ! $voice->isBuiltin())
                                    <form method="POST" action="{{ route('admin.voices.destroy', $voice) }}" onsubmit="return confirm('Delete this voice and its reference clip?')">@csrf @method('DELETE')
                                        <button class="rounded-md border border-red-500/30 px-2.5 py-1 text-xs text-red-400 hover:bg-red-500/10">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showOwner ? 7 : 6 }}" class="px-4 py-10 text-center text-zinc-500">No voices yet. <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.create') }}">Add one</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p id="voices-order-status" class="mt-2 text-xs text-zinc-500" role="status" aria-live="polite"></p>
</x-layout>
