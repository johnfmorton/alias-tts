{{-- The app's standard audio player: a skinned wrapper around a hidden native
     <audio> (the `.aplayer__native`), wired up by enhanceStudioPlayers() in
     app.js. Weights: hero (final audio) | chunk (medium) | take (small, muted).
     JS targets the native element by `audio-id`; playAudio() reveals the
     wrapper, so a player that starts `hidden` shows on first playback. --}}
@props(['variant' => 'chunk', 'audioId' => null, 'label' => 'Play audio'])
<div {{ $attributes->merge(['class' => 'aplayer aplayer--'.$variant]) }}>
    <button type="button" class="aplayer__btn" aria-label="{{ $label }}"><span class="aplayer__icon"></span></button>
    <div class="aplayer__track"><div class="aplayer__fill"></div><div class="aplayer__knob"></div></div>
    <span class="aplayer__time">0:00 / 0:00</span>
    <audio @if($audioId) id="{{ $audioId }}" @endif class="aplayer__native" preload="none"></audio>
</div>
