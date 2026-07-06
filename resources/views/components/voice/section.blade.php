@props(['label', 'hint' => null, 'labelClass' => 'text-accent'])
{{-- One redesign section: an uppercase label (+ optional hint) above a panel
     card. Used on the voice create/edit pages. `labelClass` = text-accent
     (default) or text-ok for "Tune by ear". --}}
<section class="mb-[26px]">
    <div class="mb-3.5 flex items-center gap-2">
        <span class="text-xs font-bold uppercase tracking-[0.1em] {{ $labelClass }}">{{ $label }}</span>
        @if($hint)<span class="text-xs text-zinc-500">{{ $hint }}</span>@endif
    </div>
    <div {{ $attributes->merge(['class' => 'rounded-[14px] border border-white/8 bg-panel p-6']) }}>
        {{ $slot }}
    </div>
</section>
