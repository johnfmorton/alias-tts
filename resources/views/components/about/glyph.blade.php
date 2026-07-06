{{-- Mini waveform: the logo's center-peaked bars at glyph scale. Single accent
     color per section (its position along the brand gradient); `gradient` renders
     the full spectrum. `gid` must be unique per gradient instance on a page. --}}
@props(['color' => '#22d3ee', 'gradient' => false, 'gid' => 'wfg', 'size' => 'h-[18px]'])
<svg viewBox="0 0 34 24" class="{{ $size }} w-auto shrink-0" aria-hidden="true">
    @if ($gradient)
        <defs>
            <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#22d3ee"/>
                <stop offset="0.22" stop-color="#009ff5"/>
                <stop offset="0.45" stop-color="#246cff"/>
                <stop offset="0.68" stop-color="#6164ff"/>
                <stop offset="1" stop-color="#b129ff"/>
            </linearGradient>
        </defs>
    @endif
    @foreach ([[0, 10], [5, 15], [10, 20], [15, 24], [20, 18], [25, 12], [30, 7]] as [$x, $h])
        <rect x="{{ $x }}" y="{{ (24 - $h) / 2 }}" width="4" height="{{ $h }}" rx="2"
              fill="{{ $gradient ? "url(#$gid)" : $color }}"/>
    @endforeach
</svg>
