{{-- One-time secrets (set-password link / temp password) surfaced after
     create / invite / force-reset. Rendered on both the list and detail
     pages — whichever the action redirects back to. --}}
@if(session('reveals'))
    <div class="mb-6 space-y-4 rounded-[12px] border border-accent/30 bg-accent/[0.06] p-4">
        @foreach(session('reveals') as $reveal)
            <div>
                <div class="mb-2 text-[13px] text-zinc-400">{{ $reveal['label'] }}</div>
                <div class="flex items-center gap-2">
                    <input type="text" readonly value="{{ $reveal['value'] }}" onfocus="this.select()"
                           class="w-full rounded-[8px] border border-edge bg-inset px-3 py-2 font-mono text-[13px] text-zinc-200 focus:outline-none">
                    <button type="button" data-copy="{{ $reveal['value'] }}"
                            class="inline-flex shrink-0 items-center justify-center rounded-[9px] border border-white/14 px-4 py-[9px] text-sm text-zinc-300 transition hover:bg-white/[0.04]">Copy</button>
                </div>
            </div>
        @endforeach
        <div class="text-xs text-zinc-500">Shown once — copy what you need now.</div>
    </div>
@endif
