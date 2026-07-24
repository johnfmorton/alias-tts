{{-- Recording-quality guidance. Folded behind a disclosure so it appears where
     you'd act on it — inside the Record flow — rather than greeting everyone who
     opens the page. It is NOT tied to the cleanup feature: good mic technique is
     the single biggest factor in clone quality, so the plain-upload fallback
     carries the same panel under its own disclosure id. --}}
@php($tipsId = $tipsId ?? 'recording-tips')
<button type="button" data-disclosure-toggle="{{ $tipsId }}"
        data-open-label="Recording tips ▾" data-close-label="Recording tips ▴"
        class="mb-3 text-[12.5px] text-accent transition hover:brightness-110">Recording tips ▾</button>
<div data-disclosure="{{ $tipsId }}" class="mb-4 hidden rounded-[12px] border border-accent/30 bg-accent/5 px-5 py-4">
    <div class="text-[13px] font-bold text-zinc-100">Get a great recording</div>
    <p class="mt-1 max-w-[760px] text-[12.5px] leading-relaxed text-zinc-400">Your voice will only ever sound as good as this clip. The most common mistake: speaking too softly.</p>
    <ul class="mt-2.5 grid max-w-[760px] grid-cols-1 gap-x-6 gap-y-1.5 text-[12.5px] leading-relaxed text-zinc-400 sm:grid-cols-2">
        <li class="flex gap-2"><span class="text-accent" aria-hidden="true">•</span>Speak at full conversational volume — as if the listener is across the room.</li>
        <li class="flex gap-2"><span class="text-accent" aria-hidden="true">•</span>Stay 6–12 inches from the microphone.</li>
        <li class="flex gap-2"><span class="text-accent" aria-hidden="true">•</span>Quiet room, no echo — soft furnishings beat bare walls.</li>
        <li class="flex gap-2"><span class="text-accent" aria-hidden="true">•</span>Steady, natural pacing — read, don't perform.</li>
        <li class="flex gap-2 sm:col-span-2"><span class="text-accent" aria-hidden="true">•</span>Aim for 15–20 seconds of clean speech — longer clips are trimmed automatically at a natural pause (the engines only listen to the first ~15 seconds).</li>
    </ul>
</div>
