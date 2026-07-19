<x-layout title="Voices" description="Each voice maps a voice_id to a reference clip for zero-shot cloning. Your voices are yours alone — other users only ever see the built-ins and their own.">
    <x-getting-started page="voices" title="Welcome to Voices">
        <p class="mt-1.5 max-w-[760px] text-sm text-zinc-400">A voice is a short reference recording plus your saved delivery settings. Record or upload 15–20 seconds of clean speech to clone a voice of your own, or start with the built-in defaults.</p>
        <ul class="mt-3 max-w-[760px] list-disc space-y-1.5 pl-5 text-[13px] leading-relaxed text-zinc-400">
            <li>Your voices are private — other users only ever see the built-ins and their own.</li>
            <li>Drag to reorder: your order drives every voice dropdown, and the first voice is pre-selected for new projects.</li>
            <li>Open a voice to tune its delivery by ear and save presets you can reuse anywhere.</li>
        </ul>
    </x-getting-started>

    <div class="mb-4 flex flex-wrap items-center justify-end gap-3">
        {{-- SuperAdmin-only: the owner filter, landing on the admin's own voices. --}}
        @if($showOwner)
            <x-owner-filter :action="route('admin.voices.index')" :owners="$owners" :owner-id="$ownerId" />
        @endif

        {{-- Single "Add voice" entry point: the main segment goes straight to the
             New voice screen (the common path); the caret menu adds the import
             path, replacing the old always-visible Choose File → Import pair. --}}
        <div id="add-voice" class="relative">
            <div class="flex items-stretch">
                <a id="add-voice-main" href="{{ route('admin.voices.create') }}"
                   class="inline-flex items-center rounded-l-[9px] bg-accent px-4 py-[9px] text-sm font-semibold text-accent-on transition hover:bg-accent/90">+ Add voice</a>
                <button type="button" id="add-voice-caret" aria-haspopup="menu" aria-expanded="false"
                        aria-controls="add-voice-menu" aria-label="More ways to add a voice"
                        class="inline-flex w-9 items-center justify-center rounded-r-[9px] border-l border-accent-on/25 bg-accent text-sm leading-none text-accent-on transition hover:bg-accent/90">▾</button>
            </div>

            <div id="add-voice-menu" role="menu" aria-labelledby="add-voice-caret"
                 class="absolute right-0 top-[calc(100%+8px)] z-20 hidden w-[300px] rounded-[13px] border border-white/12 bg-menu p-1.5 shadow-[0_24px_50px_-14px_rgba(0,0,0,0.7)]">
                <a role="menuitem" href="{{ route('admin.voices.create') }}"
                   class="flex items-start gap-3 rounded-[9px] border border-accent/20 bg-accent/[0.07] p-3 hover:bg-accent/[0.12] focus:outline-none focus-visible:ring-1 focus-visible:ring-accent/60">
                    <span aria-hidden="true" class="flex h-8 w-8 flex-shrink-0 items-end justify-center gap-[2px] rounded-[9px] border border-accent/30 bg-accent/[0.12] pb-[9px]">
                        <span class="h-[7px] w-[2.5px] rounded-sm bg-accent"></span>
                        <span class="h-[13px] w-[2.5px] rounded-sm bg-accent"></span>
                        <span class="h-[9px] w-[2.5px] rounded-sm bg-accent"></span>
                    </span>
                    <span>
                        <span class="block text-sm font-semibold text-zinc-100">Record or upload a clip</span>
                        <span class="mt-0.5 block text-xs leading-snug text-zinc-500">Create a new voice from a reference recording.</span>
                    </span>
                </a>
                <div class="mx-2 my-1.5 h-px bg-white/[0.07]"></div>
                <button type="button" role="menuitem" id="add-voice-import"
                        class="flex w-full items-start gap-3 rounded-[9px] p-3 text-left hover:bg-white/[0.04] focus:outline-none focus-visible:ring-1 focus-visible:ring-accent/60">
                    <span aria-hidden="true" class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-[9px] border border-white/14 bg-white/[0.05] text-[15px] text-zinc-300">↧</span>
                    <span>
                        <span class="block text-sm font-semibold text-zinc-200">Import a voice file…</span>
                        <span class="mt-0.5 block text-xs leading-snug text-zinc-500">Restore a voice exported from Alias <span class="font-mono">(.zip)</span>.</span>
                    </span>
                </button>
            </div>

            <form id="voice-import-form" method="POST" action="{{ route('admin.voices.import') }}"
                  enctype="multipart/form-data" class="hidden" aria-hidden="true">
                @csrf
                <input type="file" name="archive" accept=".zip" id="voice-import-file" tabindex="-1">
            </form>
        </div>
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
                            <button data-copy="{{ $voice->slug }}" class="text-left font-mono text-zinc-200 hover:text-cyan-400" title="Click to copy">{{ $voice->slug }}</button>
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
                            <x-aplayer variant="take" audio-id="audio-{{ $voice->id }}" label="Play voice sample" class="mt-2 hidden w-64 max-w-full" />
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
                                    <form method="POST" action="{{ route('admin.voices.destroy', $voice) }}"
                                          data-confirm="The voice and its reference clip are deleted permanently."
                                          data-confirm-title="Delete this voice?"
                                          data-confirm-label="Delete voice">@csrf @method('DELETE')
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
