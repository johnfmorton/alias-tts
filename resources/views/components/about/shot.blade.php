{{-- A screenshot in a browser frame. Drop a real capture at
     public/images/about/{file} and it replaces the placeholder automatically —
     no template change needed. The URL pill doubles as the capture note: it
     names the page the screenshot should show. --}}
@props(['file', 'url', 'note'])
@php
    $path = 'images/about/'.$file;
    $src = file_exists(public_path($path)) ? asset($path) : null;
@endphp
<figure {{ $attributes }}>
    <div class="overflow-hidden rounded-xl border border-zinc-800 bg-[#0d0d10] shadow-[0_24px_70px_-32px_rgba(0,0,0,0.9)]">
        <div class="flex items-center gap-1.5 border-b border-zinc-800/80 bg-white/[0.03] px-4 py-2.5">
            <span class="h-2.5 w-2.5 rounded-full bg-zinc-700"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-zinc-700"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-zinc-700"></span>
            <span class="ml-3 min-w-0 flex-1 truncate rounded-md bg-black/40 px-3 py-1 font-mono text-[11px] text-zinc-500">{{ $url }}</span>
        </div>
        @if ($src)
            <img src="{{ $src }}" alt="{{ $note }}" loading="lazy" class="block w-full">
        @else
            <div class="relative flex aspect-[16/10] flex-col items-center justify-center gap-3 px-6 text-center">
                <svg viewBox="0 0 34 24" class="pointer-events-none absolute left-1/2 top-1/2 h-40 w-auto -translate-x-1/2 -translate-y-1/2 opacity-[0.05]" aria-hidden="true">
                    @foreach ([[0, 10], [5, 15], [10, 20], [15, 24], [20, 18], [25, 12], [30, 7]] as [$x, $h])
                        <rect x="{{ $x }}" y="{{ (24 - $h) / 2 }}" width="4" height="{{ $h }}" rx="2" fill="#a1a1aa"/>
                    @endforeach
                </svg>
                <span class="text-sm font-medium text-zinc-500">Screenshot on the way</span>
                <span class="max-w-sm text-xs leading-relaxed text-zinc-600">{{ $note }}</span>
                <code class="rounded bg-white/[0.04] px-2 py-1 font-mono text-[11px] text-zinc-600">public/{{ $path }}</code>
            </div>
        @endif
    </div>
    @if ($src)
        <figcaption class="mt-3 text-center text-xs leading-relaxed text-zinc-500">{{ $note }}</figcaption>
    @endif
</figure>
