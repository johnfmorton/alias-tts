@props(['steps', 'note' => null])
{{-- The step rail shared by Add a voice and Edit voice. The rail NEVER changes:
     Identity → Voice source → Delivery defaults, whatever the engine. Each entry
     carries its own state (done / current / todo / locked) as a `data-state`
     attribute that initVoiceFlow() flips live — the styling for each state lives
     in app.css, so nothing here duplicates it. Clicking a step opens and scrolls
     to its card. --}}
<nav {{ $attributes->merge(['class' => 'flex flex-col gap-1 lg:sticky lg:top-5']) }} aria-label="Voice setup steps" data-voice-rail>
    @foreach($steps as $step)
        <button type="button" class="vrail__item" data-rail-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}"
                @disabled($step['state'] === 'locked')>
            <span class="vrail__mark" aria-hidden="true">
                <span class="vrail__check">✓</span>
                <span class="vrail__num">{{ $step['number'] }}</span>
                <span class="vrail__lock">🔒</span>
            </span>
            <span class="min-w-0">
                <span class="vrail__name block text-sm font-semibold text-zinc-300">{{ $step['name'] }}</span>
                <span class="vrail__meta block truncate text-xs text-zinc-500" data-rail-meta
                      @if($step['tone'] ?? null) data-tone="{{ $step['tone'] }}" @endif>{{ $step['meta'] }}</span>
            </span>
        </button>
    @endforeach

    @if($note)
        <p class="mt-[18px] rounded-[10px] border border-white/8 bg-panel p-3.5 text-[12.5px] leading-[1.55] text-zinc-500">{!! $note !!}</p>
    @endif
</nav>
