@props([
    'step',
    'number',
    'title',
    'hint' => null,
    'state' => 'todo',
    'collapsed' => false,
])
{{-- One card in the voice pipeline. A step that supplies a `summary` slot is
     collapsible: the summary row stands in for the card until it's opened, so
     the page shows only the job you came for. `state` drives the card outline
     (see app.css) and is kept in step with the rail by initVoiceFlow(). --}}
<section data-voice-step="{{ $step }}" data-state="{{ $state }}" class="scroll-mt-6">
    @isset($summary)
        <div data-step-summary
             @class([
                 'flex-wrap items-center gap-x-4 gap-y-2 rounded-[14px] border border-white/8 bg-panel px-6 py-4',
                 'flex' => $collapsed,
                 'hidden' => ! $collapsed,
             ])>
            <span class="shrink-0 text-[15px] font-bold text-zinc-100">{{ $number }} · {{ $title }}</span>
            {{ $summary }}
            <button type="button" data-step-toggle
                    class="ml-auto shrink-0 rounded-[8px] border border-edge px-3.5 py-[7px] text-[13px] text-zinc-300 transition hover:bg-white/5">Open ▾</button>
        </div>
    @endisset

    <div data-step-body @class(['rounded-[14px] border border-white/8 bg-panel p-6', 'hidden' => $collapsed])>
        <div class="mb-[18px] flex items-baseline justify-between gap-4">
            <h2 class="text-base font-bold text-zinc-100">{{ $number }} · {{ $title }}</h2>
            <div class="flex items-baseline gap-3">
                @if($hint)<span class="text-right text-[12.5px] text-zinc-500">{{ $hint }}</span>@endif
                @isset($summary)
                    <button type="button" data-step-toggle class="shrink-0 text-[13px] text-zinc-400 transition hover:text-zinc-200">Close ▴</button>
                @endisset
            </div>
        </div>
        {{ $slot }}
    </div>
</section>
