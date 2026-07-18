{{--
    Per-chunk QA badge + hover/focus popover (design "QA Badge States").
    `$badge` is TtsChunk::asrBadge() output (or null). The .chunk-asr-badge
    wrapper always renders so setChunkAsrBadge() in app.js can rebuild it live;
    its inner structure here is MIRRORED by renderQaBadge() there — keep the two
    in lockstep. Tone → pill palette matches the sibling status/skip pills; the
    popover chrome is styled by .qa-popover in app.css.
--}}
@php
    $qaTones = [
        'ok' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
        'fixed' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        'bad' => 'border-red-500/30 bg-red-500/10 text-red-300',
        'reviewed' => 'border-zinc-700 bg-zinc-800 text-zinc-400',
    ];
@endphp
<span class="chunk-asr-badge qa-badge-wrap {{ $badge ? 'relative inline-flex' : 'hidden' }}">
    @if($badge)
        <button type="button"
                class="qa-badge cursor-help rounded-md border px-2 py-0.5 text-xs {{ $qaTones[$badge['tone']] ?? $qaTones['bad'] }}"
                aria-haspopup="dialog" aria-expanded="false">{{ $badge['text'] }}</button>
        <span class="qa-popover qa-popover--{{ $badge['tone'] }} hidden" role="tooltip">
            <span class="qa-popover__arrow" aria-hidden="true"></span>
            @if($badge['heading'])
                <span class="qa-popover__head">
                    <span class="qa-popover__icon" aria-hidden="true">{{ in_array($badge['tone'], ['fixed', 'bad'], true) ? '⚠' : '✓' }}</span>
                    <span>{{ $badge['heading'] }}</span>
                </span>
            @endif
            <span class="qa-popover__body">{{ $badge['body'] }}@if($badge['fix']) @if($badge['fix']['label'] !== ''){{-- --}}<strong>{{ $badge['fix']['label'] }}</strong> @endif{{ $badge['fix']['text'] }}@endif</span>
            @if($badge['actions'])
                <span class="qa-popover__foot">
                    @if($badge['prompt'])<span class="qa-popover__prompt">{{ $badge['prompt'] }}</span>@endif
                    @foreach($badge['actions'] as $i => $act)
                        @if($i > 0)<span class="qa-popover__sep" aria-hidden="true">·</span>@endif
                        <button type="button" class="qa-act" data-qa-act="{{ $act['act'] }}">{{ $act['label'] }}</button>
                    @endforeach
                </span>
            @endif
        </span>
    @endif
</span>
