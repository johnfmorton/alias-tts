{{-- A screenshot in a browser frame. Drop a real capture at
     public/images/about/{file} and it replaces the placeholder automatically —
     no template change needed. The URL pill doubles as the capture note: it
     names the page the screenshot should show.

     Real captures open in a lightbox that keeps the metaphor: the whole
     "window" lifts off the page (FLIP transform, Apple-sheet easing) and the
     first traffic light becomes a live macOS-style close control. Esc, click,
     or scroll dismisses it; reduced-motion gets a plain crossfade. --}}
@props(['file', 'url', 'note'])
@php
    $path = 'images/about/'.$file;
    $src = file_exists(public_path($path)) ? asset($path) : null;
@endphp
<figure {{ $attributes }}>
    @if ($src)
    <button type="button" data-shot-trigger class="shot-trigger group block w-full cursor-zoom-in text-left"
            aria-label="View full size — {{ $note }}" aria-haspopup="dialog">
    @endif
        <div data-shot-frame class="overflow-hidden rounded-xl border border-zinc-800 bg-[#0d0d10] shadow-[0_24px_70px_-32px_rgba(0,0,0,0.9)] transition-colors duration-300 group-hover:border-zinc-600">
            <div data-shot-chrome class="flex items-center gap-1.5 border-b border-zinc-800/80 bg-white/[0.03] px-4 py-2.5">
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
    </button>
    <figcaption class="mt-3 text-center text-xs leading-relaxed text-zinc-500">{{ $note }}</figcaption>
    @endif
</figure>

@if ($src)
@once
<style>
    .shot-trigger:focus-visible { outline: 2px solid #22d3ee; outline-offset: 4px; border-radius: .85rem; }

    dialog.shot-lb {
        position: fixed; inset: 0; width: 100%; height: 100%;
        max-width: none; max-height: none; margin: 0; padding: 0; border: 0;
        background: transparent; overflow: hidden; cursor: zoom-out;
    }
    dialog.shot-lb::backdrop { background: transparent; }

    /* The scrim borrows the page's light-source motif: near-black with a faint
       glow where the window lands. */
    .shot-lb-backdrop {
        position: absolute; inset: 0; opacity: 0;
        background:
            radial-gradient(52% 42% at 50% 44%, rgba(97,100,255,.13), rgba(34,211,238,.05) 52%, transparent 76%),
            rgba(9,9,11,.93);
    }

    .shot-lb-window {
        position: fixed; margin: 0; transform-origin: top left; will-change: transform;
        box-shadow: 0 60px 160px -28px rgba(0,0,0,.95);
    }
    .shot-lb-window .font-mono { font-size: 12px; color: #9d9da6; }

    /* In the expanded window the first traffic light is live — red like the
       real thing, with the × surfacing on hover, exactly as macOS does it. */
    .shot-lb-close {
        position: relative; display: grid; place-items: center;
        height: 10px; width: 10px; flex: none; border-radius: 9999px;
        border: 0; padding: 0; background: #ff5f57; cursor: pointer;
    }
    .shot-lb-close::before { content: ''; position: absolute; inset: -8px; }
    .shot-lb-close::after {
        content: '\d7'; font: 700 11px/1 ui-sans-serif, system-ui;
        color: rgba(77,0,0,.75); opacity: 0; transition: opacity .15s ease;
    }
    .shot-lb-close:hover::after, .shot-lb-close:focus-visible::after { opacity: 1; }
    .shot-lb-close:focus-visible { outline: 2px solid #22d3ee; outline-offset: 3px; }

    .shot-lb-caption {
        position: fixed; left: 50%; margin: 0; max-width: min(46rem, 88vw);
        transform: translateX(-50%) translateY(6px); opacity: 0; pointer-events: none;
        text-align: center; font-size: 13px; line-height: 1.6; color: #a1a1aa;
        transition: opacity .32s ease, transform .32s cubic-bezier(.32,.72,0,1);
    }
    .shot-lb-caption.is-in { opacity: 1; transform: translateX(-50%) translateY(0); }

    @media (prefers-reduced-motion: reduce) {
        .shot-lb-caption { transition: opacity .16s ease; transform: translateX(-50%); }
        .shot-lb-caption.is-in { transform: translateX(-50%); }
    }
</style>

<dialog id="shot-lb" class="shot-lb" aria-label="Screenshot">
    <div class="shot-lb-backdrop" data-lb-backdrop></div>
    <div data-lb-stage></div>
    <p class="shot-lb-caption" data-lb-caption></p>
</dialog>

<script>
(() => {
    const dialog = document.getElementById('shot-lb');
    const backdrop = dialog.querySelector('[data-lb-backdrop]');
    const stage = dialog.querySelector('[data-lb-stage]');
    const caption = dialog.querySelector('[data-lb-caption]');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const EASE = 'cubic-bezier(.32,.72,0,1)';
    const OPEN_MS = 440, CLOSE_MS = 340;

    let state = 'closed'; // closed | opening | open | closing
    let trigger = null, frame = null, clone = null, finalRect = null;

    // The chrome bar keeps a fixed height while the shot scales, so the fitted
    // width is solved against the leftover vertical room.
    const fitRect = (chromeH, aspect) => {
        const vw = window.innerWidth, vh = window.innerHeight;
        const w = Math.min(1440, vw * 0.94, (vh * 0.88 - chromeH) / aspect);
        const h = chromeH + w * aspect + 2;
        return { x: (vw - w) / 2, y: Math.max(vh * 0.03, (vh - h) / 2 - vh * 0.012), w };
    };

    const invert = (from, to) =>
        `translate(${from.left - to.left}px, ${from.top - to.top}px) ` +
        `scale(${from.width / to.width}, ${from.height / to.height})`;

    // transitionend with a timeout fallback, so a dropped event can't strand
    // the overlay.
    const settle = (el, timeoutMs, fn) => {
        let done = false;
        const finish = () => {
            if (done) return;
            done = true;
            el.removeEventListener('transitionend', onEnd);
            clearTimeout(t);
            fn();
        };
        const onEnd = (e) => { if (e.target === el) finish(); };
        el.addEventListener('transitionend', onEnd);
        const t = setTimeout(finish, timeoutMs);
    };

    const lockScroll = () => {
        const gap = window.innerWidth - document.documentElement.clientWidth;
        document.documentElement.style.overflow = 'hidden';
        if (gap > 0) document.body.style.paddingRight = gap + 'px';
    };
    const unlockScroll = () => {
        document.documentElement.style.overflow = '';
        document.body.style.paddingRight = '';
    };

    function open(btn, viaKeyboard) {
        if (state !== 'closed') return;
        state = 'opening';
        trigger = btn;
        frame = btn.querySelector('[data-shot-frame]');
        const srcImg = frame.querySelector('img');

        clone = frame.cloneNode(true);
        clone.classList.add('shot-lb-window');
        clone.style.visibility = 'hidden';
        const cImg = clone.querySelector('img');
        cImg.loading = 'eager';
        // The thumbnail already painted this exact URL, so the flight can start
        // immediately — warm the full-size decode in the background instead of
        // holding the animation for it.
        if (cImg.decode) cImg.decode().catch(() => {});

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'shot-lb-close';
        closeBtn.setAttribute('aria-label', 'Close full-size view');
        clone.querySelector('[data-shot-chrome] > span').replaceWith(closeBtn);

        caption.textContent = cImg.alt || '';
        caption.classList.remove('is-in');
        dialog.setAttribute('aria-label', cImg.alt || 'Screenshot');
        stage.replaceChildren(clone);
        lockScroll();
        dialog.showModal();

        const aspect = srcImg.naturalWidth ? srcImg.naturalHeight / srcImg.naturalWidth : 0.625;
        // Pin the flown image to its intrinsic ratio so its box height is
        // definite before this cloned, cache-backed <img> finishes decoding.
        // Chrome lays a cached clone out synchronously; Safari/iOS lay it out a
        // beat late, so finalRect below reads short (≈ chrome bar only), the
        // FLIP scaleY over-stretches vertically, then snaps when the real
        // height lands mid-flight. An explicit aspect-ratio makes it stable.
        if (srcImg.naturalWidth && srcImg.naturalHeight)
            cImg.style.aspectRatio = srcImg.naturalWidth + ' / ' + srcImg.naturalHeight;
        clone.style.width = '1200px'; // provisional, for a stable chrome-height read
        const chromeH = clone.querySelector('[data-shot-chrome]').getBoundingClientRect().height;
        const fit = fitRect(chromeH, aspect);
        clone.style.left = fit.x + 'px';
        clone.style.top = fit.y + 'px';
        clone.style.width = fit.w + 'px';
        finalRect = clone.getBoundingClientRect();
        caption.style.top = Math.min(finalRect.bottom + 18, window.innerHeight - 44) + 'px';

        // Move focus to the live close dot, but only draw its ring for
        // keyboard-driven opens — a mouse click shouldn't flash an outline.
        const focusClose = () => closeBtn.focus({ preventScroll: true, focusVisible: !!viaKeyboard });

        if (reduced.matches) {
            clone.style.visibility = '';
            dialog.style.opacity = '0';
            backdrop.style.transition = 'none';
            backdrop.style.opacity = '1';
            requestAnimationFrame(() => {
                dialog.style.transition = 'opacity 160ms ease';
                dialog.style.opacity = '1';
                caption.classList.add('is-in');
            });
            state = 'open';
            focusClose();
            return;
        }

        const start = frame.getBoundingClientRect();
        frame.style.visibility = 'hidden'; // the window has lifted off the page
        backdrop.style.transition = 'none';
        backdrop.style.opacity = '0';
        clone.style.transition = 'none';
        clone.style.transform = invert(start, finalRect);
        clone.style.visibility = '';
        clone.getBoundingClientRect(); // flush, so the flight starts from the frame
        clone.style.transition = `transform ${OPEN_MS}ms ${EASE}`;
        backdrop.style.transition = `opacity ${OPEN_MS}ms ${EASE}`;
        clone.style.transform = 'translate(0px, 0px) scale(1, 1)';
        backdrop.style.opacity = '1';
        setTimeout(() => {
            if (state === 'opening' || state === 'open') caption.classList.add('is-in');
        }, OPEN_MS * 0.4);
        focusClose();
        settle(clone, OPEN_MS + 140, () => { if (state === 'opening') state = 'open'; });
    }

    function close() {
        if (state !== 'open' && state !== 'opening') return;
        state = 'closing';
        caption.classList.remove('is-in');

        if (!clone || !finalRect) { teardown(); return; } // dismissed before geometry existed

        if (reduced.matches) {
            dialog.style.transition = 'opacity 140ms ease';
            dialog.style.opacity = '0';
            settle(dialog, 240, teardown);
            return;
        }

        const start = frame.getBoundingClientRect();
        clone.style.transition = `transform ${CLOSE_MS}ms ${EASE}`;
        backdrop.style.transition = `opacity ${CLOSE_MS}ms ${EASE}`;
        clone.style.transform = invert(start, finalRect);
        backdrop.style.opacity = '0';
        settle(clone, CLOSE_MS + 140, teardown);
    }

    function teardown() {
        if (state !== 'closing') return;
        state = 'closed';
        dialog.close();
        dialog.style.transition = '';
        dialog.style.opacity = '';
        if (frame) frame.style.visibility = '';
        stage.replaceChildren();
        clone = null;
        finalRect = null;
        unlockScroll();
        if (trigger) trigger.focus({ preventScroll: true });
    }

    // Delegated, because this script is emitted alongside the first shot —
    // later shots don't exist in the DOM yet when it runs.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-shot-trigger]');
        if (btn) open(btn, e.detail === 0); // detail 0 = keyboard-activated button
    });

    dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    dialog.addEventListener('click', () => close());
    dialog.addEventListener('close', () => { if (state !== 'closed') { state = 'closing'; teardown(); } });

    // Scroll-away dismissal, gated to the settled state so trackpad inertia
    // from before the click can't cancel the opening flight.
    dialog.addEventListener('wheel', (e) => {
        if (state === 'open' && Math.abs(e.deltaY) > 2) close();
    }, { passive: true });
    let touchY = null;
    dialog.addEventListener('touchstart', (e) => { touchY = e.touches[0].clientY; }, { passive: true });
    dialog.addEventListener('touchmove', (e) => {
        if (state === 'open' && touchY !== null && Math.abs(e.touches[0].clientY - touchY) > 12) close();
    }, { passive: true });

    window.addEventListener('resize', () => {
        if (state !== 'open' || !clone) return;
        const cImg = clone.querySelector('img');
        const aspect = cImg.naturalWidth ? cImg.naturalHeight / cImg.naturalWidth : 0.625;
        const chromeH = clone.querySelector('[data-shot-chrome]').getBoundingClientRect().height;
        const fit = fitRect(chromeH, aspect);
        clone.style.left = fit.x + 'px';
        clone.style.top = fit.y + 'px';
        clone.style.width = fit.w + 'px';
        finalRect = clone.getBoundingClientRect();
        caption.style.top = Math.min(finalRect.bottom + 18, window.innerHeight - 44) + 'px';
    });
})();
</script>
@endonce
@endif
