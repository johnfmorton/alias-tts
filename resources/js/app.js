// Lightweight dashboard interactions — no framework.

const SVG_NS = 'http://www.w3.org/2000/svg';

// Build the inline spinner with DOM APIs (no innerHTML) so it's safe to combine
// with caller-provided labels.
function spinnerSvg() {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'h-4 w-4 animate-spin');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('aria-hidden', 'true');

    const circle = document.createElementNS(SVG_NS, 'circle');
    circle.setAttribute('class', 'opacity-25');
    circle.setAttribute('cx', '12');
    circle.setAttribute('cy', '12');
    circle.setAttribute('r', '10');
    circle.setAttribute('stroke', 'currentColor');
    circle.setAttribute('stroke-width', '4');

    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('class', 'opacity-75');
    path.setAttribute('fill', 'currentColor');
    path.setAttribute('d', 'M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z');

    svg.append(circle, path);
    return svg;
}

// Replace a button's contents with a spinner + label (label via textContent).
function setRunning(btn, label) {
    const wrap = document.createElement('span');
    wrap.className = 'inline-flex items-center gap-2';
    wrap.append(spinnerSvg(), document.createTextNode(label));
    btn.replaceChildren(wrap);
}

const csrfToken = () => document.querySelector('meta[name=csrf-token]')?.content ?? '';
const elapsed = (t0) => ((performance.now() - t0) / 1000).toFixed(1);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function setStatus(el, message, kind) {
    if (!el) return;
    el.textContent = message;
    el.className = 'text-sm ' + (kind === 'error' ? 'text-red-300' : kind === 'ok' ? 'text-emerald-300' : 'text-zinc-400');
}

// setStatus's chunk-scoped counterpart: feedback lands on the card the action
// happened on (#project-final-status is for project-wide messages only). Only
// messages that add information get written here — a state change the card
// already shows (a take row vanishing, a badge swapping) is its own feedback.
// Non-errors retire after a few seconds (the durable state lives in the badges);
// errors stay until the next action on the card replaces them.
function chunkNotice(card, message, kind) {
    const el = card.querySelector('.chunk-notice');
    if (!el) return;
    el.textContent = message;
    // Rewritten wholesale like setStatus — keep the hook class and empty:hidden.
    el.className = 'chunk-notice mt-2 text-sm empty:hidden ' + (kind === 'error' ? 'text-red-300' : kind === 'ok' ? 'text-emerald-300' : 'text-zinc-400');
    if (message && kind !== 'error') {
        // Quietly retire the notice unless something newer replaced it.
        setTimeout(() => { if (el.textContent === message) el.textContent = ''; }, 8000);
    }
}

function playAudio(audio, blob) {
    if (!audio) return;
    audio.src = URL.createObjectURL(blob);
    // Reveal the custom-player wrapper if this audio is skinned, else the bare
    // audio element (a fallback — every player in the app is skinned now).
    (audio.closest('.aplayer') || audio).classList.remove('hidden');
    audio.play().catch(() => {});
}

// playAudio's counterpart: hide a player again (the wrapper when skinned).
function hidePlayer(audio) {
    if (audio) (audio.closest('.aplayer') || audio).classList.add('hidden');
}

// Build the app's standard player: the skinned `.aplayer` wrapper around a
// hidden native audio (same anatomy as <x-aplayer> and Studio's takeRow).
// Callers grab the native via `.aplayer__native` and run enhanceStudioPlayers()
// on the wrapper (or an ancestor) once it's in the DOM.
// With `lazySrc` (a URL, may be '') no native is created — the URL parks on
// data-audio-src and ensureNative() builds the element on first tap.
function buildAPlayer(variant, { label = 'Play audio', hidden = false, extraClass = '', lazySrc } = {}) {
    const el = document.createElement('div');
    el.className = `aplayer aplayer--${variant}` + (hidden ? ' hidden' : '') + (extraClass ? ` ${extraClass}` : '');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'aplayer__btn';
    btn.setAttribute('aria-label', label);
    const icon = document.createElement('span');
    icon.className = 'aplayer__icon';
    btn.append(icon);
    const track = document.createElement('div');
    track.className = 'aplayer__track';
    const fill = document.createElement('div');
    fill.className = 'aplayer__fill';
    const knob = document.createElement('div');
    knob.className = 'aplayer__knob';
    track.append(fill, knob);
    const time = document.createElement('span');
    time.className = 'aplayer__time';
    time.textContent = '0:00 / 0:00';
    if (lazySrc === undefined) {
        const audio = document.createElement('audio');
        audio.className = 'aplayer__native';
        el.append(btn, track, time, audio);
    } else {
        el.dataset.audioSrc = lazySrc;
        el.append(btn, track, time);
    }
    return el;
}

// A lazy player's native <audio>, created on first use. Big Studio pages ship
// their players as pure UI with the media URL parked on data-audio-src (see
// tts.studio_lazy_players): WebKit allocates real media plumbing for every
// <audio> element on the page, and hundreds of them at once made iPad Safari
// feel frozen. The element built here matches what the eager markup would have
// shipped, and _wireNative (stashed by enhanceStudioPlayers) attaches the same
// transport listeners — so everything downstream behaves identically.
function ensureNative(el) {
    if (!el) return null;
    let audio = el.querySelector('.aplayer__native');
    if (!audio && el.dataset.audioSrc !== undefined) {
        audio = document.createElement('audio');
        audio.className = ('aplayer__native ' + (el.dataset.nativeClass || '')).trim();
        audio.preload = 'none';
        if (el.dataset.audioSrc) audio.src = el.dataset.audioSrc;
        el.append(audio);
        el._wireNative?.(audio);
    }
    return audio;
}

async function errorMessage(res) {
    try {
        const body = await res.json();
        return body.message || `HTTP ${res.status}`;
    } catch (_) {
        return `HTTP ${res.status}`;
    }
}

function startBusy(btn, label) {
    btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
    btn.dataset.busy = '1'; // survives className rewrites (see look() in the Studio cluster)
    btn.classList.add('pointer-events-none', 'opacity-50');
    setRunning(btn, label);
}

function endBusy(btn) {
    delete btn.dataset.busy;
    btn.classList.remove('pointer-events-none', 'opacity-50');
    // bfcache pageshow resets call this on buttons that were never busy —
    // without a captured label, "restoring" would wipe the button's text.
    if (btn.dataset.originalText !== undefined) btn.textContent = btn.dataset.originalText;
}

// Synchronous provider test: POST → audio blob → play.
async function runShortTest(btn) {
    const status = document.querySelector(btn.dataset.statusTarget);
    const audio = document.querySelector(btn.dataset.audioTarget);
    const voice = document.querySelector(btn.dataset.voiceSelect)?.value;
    const t0 = performance.now();
    startBusy(btn, 'Generating…');
    setStatus(status, 'Generating a short sample…');
    try {
        const res = await fetch(btn.dataset.url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/mpeg' },
            body: new URLSearchParams(voice ? { voice } : {}),
        });
        if (!res.ok) throw new Error(await errorMessage(res));
        playAudio(audio, await res.blob());
        setStatus(status, `✓ Short text generated in ${elapsed(t0)}s.`, 'ok');
    } catch (err) {
        setStatus(status, `✗ ${err.message}`, 'error');
    } finally {
        endBusy(btn);
    }
}

// Async provider test: POST to queue → poll status → fetch audio → play. This
// only completes if a queue worker is draining the queue, so it doubles as a
// live worker test.
async function runLongTest(btn) {
    const status = document.querySelector(btn.dataset.statusTarget);
    const audio = document.querySelector(btn.dataset.audioTarget);
    const voice = document.querySelector(btn.dataset.voiceSelect)?.value;
    const t0 = performance.now();
    startBusy(btn, 'Queuing…');
    setStatus(status, 'Queuing a long async job…');
    try {
        const res = await fetch(btn.dataset.url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            body: new URLSearchParams(voice ? { voice } : {}),
        });
        if (!res.ok) throw new Error(await errorMessage(res));
        let job = await res.json();

        const deadline = performance.now() + 120000; // give up after 2 minutes
        while (job.status !== 'completed' && job.status !== 'failed') {
            if (performance.now() > deadline) {
                throw new Error(`Still processing after ${elapsed(t0)}s — is a queue worker running? (php artisan queue:work)`);
            }
            setStatus(status, `Async job ${job.status}… (${elapsed(t0)}s)`);
            await sleep(2000);
            const poll = await fetch(job.status_url, { headers: { 'Accept': 'application/json' } });
            if (!poll.ok) throw new Error(await errorMessage(poll));
            job = await poll.json();
        }
        if (job.status === 'failed') throw new Error(job.error || 'generation failed');

        const audioRes = await fetch(job.audio_url, { headers: { 'Accept': 'audio/mpeg' } });
        if (!audioRes.ok) throw new Error(await errorMessage(audioRes));
        playAudio(audio, await audioRes.blob());
        setStatus(status, `✓ Async generated & concatenated in ${elapsed(t0)}s.`, 'ok');
    } catch (err) {
        setStatus(status, `✗ ${err.message}`, 'error');
    } finally {
        endBusy(btn);
    }
}

document.addEventListener('click', async (e) => {
    // Dashboard connect card: clicking a Voice ID chip swaps its ID into the
    // cURL examples. No return — the chip's data-copy still copies the ID below.
    const chip = e.target.closest('[data-voice-chip]');
    if (chip) {
        const slug = chip.getAttribute('data-voice-chip');
        document.querySelectorAll('[data-example-voice]').forEach((el) => { el.textContent = slug; });
        const ACTIVE = ['border-accent/50', 'bg-accent/10', 'text-accent'];
        const IDLE = ['border-white/[0.14]', 'text-zinc-200', 'hover:bg-white/[0.04]'];
        document.querySelectorAll('[data-voice-chip]').forEach((c) => {
            c.classList.remove(...(c === chip ? IDLE : ACTIVE));
            c.classList.add(...(c === chip ? ACTIVE : IDLE));
        });
    }

    // Copy-to-clipboard: <button data-copy="value"> copies the attribute;
    // <button data-copy-from="#selector"> copies the target's rendered text
    // (used by the dashboard cURL examples, where the command is built from
    // highlighted spans and changes with the selected voice).
    const copyBtn = e.target.closest('[data-copy], [data-copy-from]');
    if (copyBtn) {
        try {
            const from = copyBtn.getAttribute('data-copy-from');
            const text = from
                ? (document.querySelector(from)?.textContent.trim() ?? '')
                : copyBtn.getAttribute('data-copy');
            await navigator.clipboard.writeText(text);
            const original = copyBtn.dataset.label || copyBtn.textContent;
            copyBtn.dataset.label = original;
            copyBtn.textContent = 'Copied!';
            setTimeout(() => { copyBtn.textContent = original; }, 1500);
        } catch (_) {
            /* clipboard unavailable */
        }
        return;
    }

    // Settings › Advanced: "Reset to default" puts a threshold field back to its
    // shipped default (data-default). Staged like any edit — the user still clicks
    // Save to persist it — so this only touches the input's value.
    const resetBtn = e.target.closest('[data-restore-default]');
    if (resetBtn) {
        e.preventDefault();
        const field = document.getElementById(resetBtn.getAttribute('data-restore-default'));
        if (field) {
            field.value = resetBtn.getAttribute('data-default') ?? '';
            const original = resetBtn.dataset.label || resetBtn.textContent;
            resetBtn.dataset.label = original;
            resetBtn.textContent = 'Reset ✓';
            setTimeout(() => { resetBtn.textContent = original; }, 1200);
        }
        return;
    }

    // Test a voice: <button data-test-voice="URL" data-audio-target="#selector">
    const testBtn = e.target.closest('[data-test-voice]');
    if (testBtn) {
        e.preventDefault();
        const url = testBtn.getAttribute('data-test-voice');
        const audio = document.querySelector(testBtn.getAttribute('data-audio-target'));
        const label = testBtn.textContent;
        testBtn.disabled = true;
        testBtn.textContent = 'Generating…';
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'audio/mpeg',
                },
            });
            if (!res.ok) throw new Error('Request failed');
            playAudio(audio, await res.blob());
            testBtn.textContent = label;
        } catch (_) {
            // Transient inline error (like the data-copy "Copied!" flip) —
            // an alert() here blocked the page for a routine provider hiccup.
            testBtn.textContent = '✗ Failed — check provider credit';
            setTimeout(() => { testBtn.textContent = label; }, 4000);
        } finally {
            testBtn.disabled = false;
        }
        return;
    }

    // Live provider tests on the health page.
    const shortBtn = e.target.closest('[data-test-short]');
    if (shortBtn) {
        e.preventDefault();
        await runShortTest(shortBtn);
        return;
    }

    const longBtn = e.target.closest('[data-test-long]');
    if (longBtn) {
        e.preventDefault();
        await runLongTest(longBtn);
        return;
    }
    // The health-page "Run checks" buttons are handled by initHealthReport (they
    // re-fetch the async report fragment rather than reloading the whole page).
});

// ---------------------------------------------------------------------------
// In-app confirmation dialog — the styled replacement for window.confirm().
// Native confirm() is subject to Chrome's "prevent this page from creating
// additional dialogs" checkbox, which silently disables every destructive-
// action guard for the tab; this one can't be suppressed. It also allows a
// toned confirm button and real copy. Resolves true (confirm) / false
// (cancel, Escape, backdrop). The layout renders one <x-confirm-dialog />
// singleton for authed pages; without it (or mid-dialog re-entry) this falls
// back to native confirm so a guard is never lost.
// ---------------------------------------------------------------------------
const CONFIRM_TONES = {
    danger: 'rounded-lg border border-red-500/40 bg-red-500/10 px-3.5 py-2 text-sm font-medium text-red-300 hover:bg-red-500/20',
    warn: 'rounded-lg border border-amber-500/50 bg-amber-500/10 px-3.5 py-2 text-sm font-medium text-amber-300 hover:bg-amber-500/20',
    // Affirmative state changes (promote a role, reactivate an account) get the
    // app's primary button rather than a red/amber scare tone.
    accent: 'rounded-lg bg-accent px-3.5 py-2 text-sm font-semibold text-accent-on hover:bg-accent/90',
};

// The from → to pill row's "to" pill, tinted to match the confirm button.
const CONFIRM_TO_PILLS = {
    danger: 'rounded-xl border border-bad/50 px-2.5 py-[3px] text-xs font-semibold text-bad',
    warn: 'rounded-xl border border-warn/50 px-2.5 py-[3px] text-xs font-semibold text-warn',
    accent: 'rounded-xl border border-accent/40 px-2.5 py-[3px] text-xs font-semibold text-accent',
};

function confirmDialog({ title = 'Are you sure?', message = '', label = 'Confirm', tone = 'danger', from = '', to = '' } = {}) {
    const dialog = document.getElementById('confirm-dialog');
    if (!dialog || dialog.classList.contains('flex')) {
        return Promise.resolve(window.confirm([title, message].filter(Boolean).join('\n\n')));
    }
    document.getElementById('confirm-dialog-title').textContent = title;
    const messageEl = document.getElementById('confirm-dialog-message');
    messageEl.textContent = message;
    messageEl.classList.toggle('hidden', !message);
    // The state-change row appears only when both endpoints are given.
    const metaEl = document.getElementById('confirm-dialog-meta');
    if (metaEl) {
        const showMeta = Boolean(from && to);
        metaEl.classList.toggle('hidden', !showMeta);
        metaEl.classList.toggle('flex', showMeta);
        if (showMeta) {
            document.getElementById('confirm-dialog-from').textContent = from;
            const toEl = document.getElementById('confirm-dialog-to');
            toEl.textContent = to;
            toEl.className = CONFIRM_TO_PILLS[tone] || CONFIRM_TO_PILLS.accent;
        }
    }
    const okBtn = document.getElementById('confirm-dialog-confirm');
    okBtn.textContent = label;
    okBtn.className = CONFIRM_TONES[tone] || CONFIRM_TONES.danger;
    const cancelBtn = document.getElementById('confirm-dialog-cancel');
    const opener = document.activeElement;

    dialog.classList.remove('hidden');
    dialog.classList.add('flex');
    cancelBtn.focus(); // safe default; Tab reaches the confirm button

    return new Promise((resolve) => {
        const close = (result) => {
            dialog.classList.add('hidden');
            dialog.classList.remove('flex');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            dialog.removeEventListener('mousedown', onBackdrop);
            dialog.removeEventListener('keydown', onKey);
            if (opener instanceof HTMLElement) opener.focus();
            resolve(result);
        };
        const onOk = () => close(true);
        const onCancel = () => close(false);
        const onBackdrop = (e) => { if (e.target === dialog) close(false); };
        const onKey = (e) => { if (e.key === 'Escape') close(false); };
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        dialog.addEventListener('mousedown', onBackdrop);
        dialog.addEventListener('keydown', onKey);
    });
}

// ---------------------------------------------------------------------------
// In-app prompt dialog — the styled replacement for window.prompt(), with the
// same rationale as confirmDialog above (native prompt() can be suppressed by
// Chrome, and this matches the app). Resolves the trimmed string on confirm,
// or null on cancel / Escape / backdrop / empty input. Falls back to native
// prompt when the singleton is missing or already open.
// ---------------------------------------------------------------------------
function promptDialog({ title = '', message = '', label = 'Save', value = '', placeholder = '' } = {}) {
    const dialog = document.getElementById('prompt-dialog');
    if (!dialog || dialog.classList.contains('flex')) {
        const raw = window.prompt([title, message].filter(Boolean).join('\n\n'), value);
        return Promise.resolve(raw === null ? null : (raw.trim() || null));
    }
    document.getElementById('prompt-dialog-title').textContent = title;
    const messageEl = document.getElementById('prompt-dialog-message');
    messageEl.textContent = message;
    messageEl.classList.toggle('hidden', !message);
    const okBtn = document.getElementById('prompt-dialog-confirm');
    okBtn.textContent = label;
    const cancelBtn = document.getElementById('prompt-dialog-cancel');
    const input = document.getElementById('prompt-dialog-input');
    input.value = value;
    input.placeholder = placeholder;
    const opener = document.activeElement;

    dialog.classList.remove('hidden');
    dialog.classList.add('flex');
    input.focus();
    input.select();

    return new Promise((resolve) => {
        const close = (result) => {
            dialog.classList.add('hidden');
            dialog.classList.remove('flex');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            dialog.removeEventListener('mousedown', onBackdrop);
            dialog.removeEventListener('keydown', onKey);
            if (opener instanceof HTMLElement) opener.focus();
            resolve(result);
        };
        const submit = () => { const t = input.value.trim(); close(t || null); };
        const onOk = () => submit();
        const onCancel = () => close(null);
        const onBackdrop = (e) => { if (e.target === dialog) close(null); };
        const onKey = (e) => {
            if (e.key === 'Escape') close(null);
            else if (e.key === 'Enter') { e.preventDefault(); submit(); }
        };
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        dialog.addEventListener('mousedown', onBackdrop);
        dialog.addEventListener('keydown', onKey);
    });
}

// Declarative form guard: <form data-confirm="message" data-confirm-title="…"
// data-confirm-label="…" data-confirm-tone="warn"> pauses its submit behind the
// dialog. On confirm, requestSubmit() re-fires the event (keeping the browser's
// constraint validation) and the one-shot `confirmed` flag lets it through.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.confirm === undefined) return;
    if (form.dataset.confirmed) {
        delete form.dataset.confirmed;
        return;
    }
    e.preventDefault();
    // Keep the original submitter so its name/value still ride the re-fire
    // (a segmented control's buttons carry the payload).
    const submitter = e.submitter instanceof HTMLElement && form.contains(e.submitter) ? e.submitter : undefined;
    confirmDialog({
        title: form.dataset.confirmTitle,
        message: form.dataset.confirm,
        label: form.dataset.confirmLabel,
        tone: form.dataset.confirmTone,
        from: form.dataset.confirmFrom,
        to: form.dataset.confirmTo,
    }).then((ok) => {
        if (!ok) return;
        form.dataset.confirmed = '1';
        if (form.requestSubmit) form.requestSubmit(submitter); else form.submit();
    });
});

// Type-to-confirm guard for the truly irreversible: <form data-delete-user="Name">
// pauses its submit behind the prompt dialog until the user types the name
// exactly (case-insensitive). Same one-shot `confirmed` re-fire as data-confirm.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.deleteUser) return;
    if (form.dataset.confirmed) {
        delete form.dataset.confirmed;
        return;
    }
    e.preventDefault();
    (async () => {
        const name = form.dataset.deleteUser;
        let message = `This permanently deletes ${name} and everything they own — projects, voices, API keys. It cannot be undone. Type the name to confirm.`;
        for (;;) {
            const typed = await promptDialog({
                title: `Delete ${name}?`,
                message,
                label: 'Delete user',
                placeholder: name,
            });
            if (typed === null) return;
            if (typed.toLowerCase() === name.trim().toLowerCase()) break;
            message = `That didn't match. Type “${name}” exactly to confirm.`;
        }
        form.dataset.confirmed = '1';
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    })();
});

// ---------------------------------------------------------------------------
// Studio: inspect normalization + chunking, then play whole / per-chunk / stitched.
// ---------------------------------------------------------------------------
// Wire every Chatterbox knob widget (slider + number + reset) under `scope` to
// stay in sync. The number box is the source of truth ('' = inherit); dragging
// the slider writes an explicit value, ↺ clears back to inherit. Idempotent — a
// widget is wired once (dynamic bench rows call this on the new row).
function initTuningKnobs(scope) {
    scope.querySelectorAll('.tuning-knob').forEach((knob) => {
        if (knob.dataset.wired) return;
        knob.dataset.wired = '1';
        const number = knob.querySelector('.knob-number');
        const range = knob.querySelector('.knob-range');
        const reset = knob.querySelector('.knob-reset');
        if (!number || !range) return;
        const fallback = () => number.getAttribute('placeholder') || range.min;
        // number -> slider (blank rests the slider at the inherited value)
        number.addEventListener('input', () => { range.value = number.value === '' ? fallback() : number.value; });
        // slider -> number (an explicit value); re-notify the number's own listeners
        range.addEventListener('input', () => {
            number.value = range.value;
            number.dispatchEvent(new Event('input', { bubbles: true }));
        });
        // ↺ -> inherit
        reset?.addEventListener('click', () => {
            number.value = '';
            range.value = fallback();
            number.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}

// Which engines each tuning control belongs to. Temperature and the seed pin
// are shared by both chatterbox engines but absent from qwen (its schema has
// neither); qwen's controls are the string pair language/style_instruction.
// Mirrors the knob sets in ChatterboxTuning / ChatterboxTurboTuning /
// Qwen3TtsTuning (PHP owns the formulas; this map only decides which
// controls SHOW).
const KNOB_ENGINES = {
    exaggeration: ['chatterbox'],
    cfg_weight: ['chatterbox'],
    top_p: ['chatterbox-turbo'],
    top_k: ['chatterbox-turbo'],
    repetition_penalty: ['chatterbox-turbo'],
    temperature: ['chatterbox', 'chatterbox-turbo'],
    language: ['qwen3-tts'],
    style_instruction: ['qwen3-tts'],
    seed: ['chatterbox', 'chatterbox-turbo'],
};

// The knobs whose values are text, not numbers (never cast through Number()).
const STRING_KNOBS = new Set(['language', 'style_instruction']);

// The engine behind a voice <select>: its selected option's data-model
// (stamped server-side from voices.model; absent = classic chatterbox).
const modelOfSelect = (select) => select?.selectedOptions[0]?.dataset.model || 'chatterbox';

// Every chunk-card tuning input, read via chunkKnobVal so only the active
// engine's set is ever sent.
const KNOB_INPUTS = [
    ['exaggeration', '.chunk-exaggeration'],
    ['cfg_weight', '.chunk-cfg'],
    ['temperature', '.chunk-temperature'],
    ['top_p', '.chunk-top-p'],
    ['top_k', '.chunk-top-k'],
    ['repetition_penalty', '.chunk-repetition-penalty'],
    ['language', '.chunk-language'],
    ['style_instruction', '.chunk-style-instruction'],
];

// The value of one chunk knob input, or '' while its knob is hidden (the
// OTHER engine's knob — a leftover value there must not ride along).
const chunkKnobVal = (card, sel) => {
    const input = card.querySelector(sel);
    if (!input || input.closest('.tuning-knob')?.classList.contains('hidden')) return '';
    return input.value;
};

// The whole tuning panel of one chunk card as a request payload: every knob
// (null = inherit/clear) + the seed pin. Rides on Generate/queue so the server
// persists exactly what's on screen before rendering.
const chunkTuningPayload = (card) => {
    // The seed row is engine-scoped (qwen has no seed input) — a leftover
    // value in the hidden row must not ride along.
    const seed = chunkKnobVal(card, '.chunk-seed');
    const payload = { seed: seed === '' ? null : Number(seed) };
    KNOB_INPUTS.forEach(([key, sel]) => {
        const value = chunkKnobVal(card, sel);
        payload[key] = value === '' ? null : (STRING_KNOBS.has(key) ? value : Number(value));
    });
    // The voice rides along only when the user actually changed the picker —
    // an untouched picker sends nothing, so a chunk that inherits the project
    // voice keeps inheriting rather than being silently pinned on every render.
    const voice = card.querySelector('.chunk-voice');
    if (voice && voice.value !== voice.dataset.original) payload.voice = voice.value;
    return payload;
};

// Restore a take's tuning snapshot into a card's panel (on take select): fill
// each knob from the server's knob => value|null map, and the seed field.
// Dispatching `input` re-syncs the paired sliders and re-lights the matching
// Delivery chip via the card's own listeners.
const applyTuningSnapshot = (card, tuning, seed) => {
    KNOB_INPUTS.forEach(([key, sel]) => {
        const input = card.querySelector(sel);
        if (!input) return;
        input.value = tuning && tuning[key] != null ? String(tuning[key]) : '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const seedInput = card.querySelector('.chunk-seed');
    if (seedInput) {
        seedInput.value = seed ?? '';
        seedInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
};

// Show exactly the given engine's knobs inside `scope` (a chunk card or a
// knobs row) and hide the other engine's, plus filter preset options and the
// engine-specific help sentences to match. Tuning-knob roots are flex
// containers, so `hidden` and `flex` are ALWAYS toggled as a pair — a
// co-present flex class would win over hidden. (Help spans and preset options
// are plain inline elements; `hidden` alone is safe there.)
function syncKnobEngines(scope, model) {
    scope.querySelectorAll('.tuning-knob[data-knob]').forEach((knob) => {
        const engines = KNOB_ENGINES[knob.dataset.knob];
        if (!engines) return;
        const hide = !engines.includes(model);
        knob.classList.toggle('hidden', hide);
        knob.classList.toggle('flex', !hide);
    });
    scope.querySelectorAll('.chunk-preset option[data-model]').forEach((opt) => {
        opt.classList.toggle('hidden', opt.dataset.model !== model);
    });
    // Any engine-scoped extra (help sentences, the sound-tag chips row) shows
    // only for its own engine. These are plain block/inline elements, so
    // `hidden` alone is safe.
    scope.querySelectorAll('[data-engine-help]').forEach((el) => {
        el.classList.toggle('hidden', el.dataset.engineHelp !== model);
    });
}

function initStudio() {
    const root = document.getElementById('studio');
    if (!root) return;

    const urls = {
        preview: root.dataset.previewUrl,
        synthesize: root.dataset.synthesizeUrl,
        stitch: root.dataset.stitchUrl,
        concat: root.dataset.concatUrl,
        suggestions: root.dataset.suggestionsUrl,
        approve: root.dataset.approveUrl,
        createProject: root.dataset.createProjectUrl,
    };

    const els = {
        text: document.getElementById('studio-text'),
        voice: document.getElementById('studio-voice'),
        exaggeration: root.querySelector('.studio-exaggeration'),
        cfg: root.querySelector('.studio-cfg'),
        temperature: root.querySelector('.studio-temperature'),
        topP: root.querySelector('.studio-top-p'),
        topK: root.querySelector('.studio-top-k'),
        repetitionPenalty: root.querySelector('.studio-repetition-penalty'),
        language: root.querySelector('.studio-language'),
        styleInstruction: root.querySelector('.studio-style-instruction'),
        knobs: document.getElementById('studio-knobs'),
        status: document.getElementById('studio-status'),
        results: document.getElementById('studio-results'),
        normalized: document.getElementById('studio-normalized'),
        normChars: document.getElementById('studio-norm-chars'),
        chunkCount: document.getElementById('studio-chunk-count'),
        chunks: document.getElementById('studio-chunks'),
        previewBtn: document.getElementById('studio-preview'),
        wholeAudio: document.getElementById('studio-whole-audio'),
        stitchBtn: document.getElementById('studio-stitch'),
        concatBar: document.getElementById('studio-concat-bar'),
        concatBtn: document.getElementById('studio-concat'),
        concatStatus: document.getElementById('studio-concat-status'),
        concatAudio: document.getElementById('studio-concat-audio'),
        estimate: document.getElementById('studio-estimate'),
        estimateLabel: document.getElementById('studio-estimate-label'),
        balance: document.getElementById('studio-balance'),
        pron: document.getElementById('studio-pron'),
        pronApplied: document.getElementById('studio-pron-applied'),
        pronStatus: document.getElementById('studio-pron-status'),
        pronSuggestions: document.getElementById('studio-pron-suggestions'),
        carryNote: document.getElementById('studio-carry-note'),
        projectTitle: document.getElementById('studio-project-title'),
        createBtn: document.getElementById('studio-create-project'),
        createStatus: document.getElementById('studio-create-status'),
    };

    let normalizedText = '';
    // Per-chunk state for the current preview, in chunk order. Each entry holds
    // the generated raw WAV blob (once generated) so we can stitch the EXACT
    // audio the user heard, plus the checkbox that selects it for concatenation.
    let chunkStates = [];

    // A knob's value, or '' while it's hidden (the other engine's knob).
    const knobValue = (input) => {
        if (!input || input.closest('.tuning-knob')?.classList.contains('hidden')) return '';
        return input.value;
    };

    // Common generation params from the form controls. `text` is whatever we want
    // synthesized (a chunk, or the whole normalized text). Only the ACTIVE
    // engine's knobs ride along — the chosen voice decides which those are.
    const paramsObject = (text) => {
        const body = { text };
        if (els.voice?.value) body.voice = els.voice.value;
        if (knobValue(els.exaggeration) !== '') body.exaggeration = els.exaggeration.value;
        if (knobValue(els.cfg) !== '') body.cfg_weight = els.cfg.value;
        if (knobValue(els.temperature) !== '') body.temperature = els.temperature.value;
        if (knobValue(els.topP) !== '') body.top_p = els.topP.value;
        if (knobValue(els.topK) !== '') body.top_k = els.topK.value;
        if (knobValue(els.repetitionPenalty) !== '') body.repetition_penalty = els.repetitionPenalty.value;
        if (knobValue(els.language) !== '') body.language = els.language.value;
        if (knobValue(els.styleInstruction) !== '') body.style_instruction = els.styleInstruction.value;
        return body;
    };
    const params = (text) => new URLSearchParams(paramsObject(text));

    // The inspector's knob row follows the chosen voice's engine.
    if (els.voice && els.knobs) {
        els.voice.addEventListener('change', () => syncKnobEngines(els.knobs, modelOfSelect(els.voice)));
        syncKnobEngines(els.knobs, modelOfSelect(els.voice));
    }

    async function fetchBlob(url, text, extra = {}) {
        const body = params(text);
        Object.entries(extra).forEach(([k, v]) => body.set(k, v));
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*' },
            body,
        });
        if (!res.ok) throw new Error(await errorMessage(res));
        // Every charged render hands back the owner's fresh balance so the
        // "credit" badge tracks spend live instead of going stale until the
        // next Preview. Absent for unlimited accounts (the badge stays hidden).
        const bal = res.headers.get('X-Credit-Balance');
        if (bal) renderBalance(JSON.parse(bal));
        // The stash token (per-chunk renders only) that lets "Create project"
        // carry this exact render across as a take.
        return { blob: await res.blob(), token: res.headers.get('X-Inspector-Take') };
    }

    // Generate `text`, play it into `audio`, drive `btn`'s busy state, and return
    // {blob, token} (or null on error) so callers can retain them.
    async function generate(url, text, audio, btn, label, extra = {}) {
        const t0 = performance.now();
        startBusy(btn, label);
        try {
            const result = await fetchBlob(url, text, extra);
            playAudio(audio, result.blob);
            setStatus(els.status, `✓ ${label.replace('…', '')} done in ${elapsed(t0)}s.`, 'ok');
            return result;
        } catch (err) {
            setStatus(els.status, `✗ ${err.message}`, 'error');
            return null;
        } finally {
            endBusy(btn);
        }
    }

    // Reveal the concat bar once at least one chunk has audio.
    function refreshConcatBar() {
        if (chunkStates.some((s) => s.blob)) els.concatBar.classList.remove('hidden');
    }

    function chunkCard(chunk, state) {
        const li = document.createElement('li');
        li.className = 'rounded-xl border border-zinc-800 bg-zinc-900/50 p-4';

        const head = document.createElement('div');
        head.className = 'flex flex-wrap items-center justify-between gap-2';

        const meta = document.createElement('div');
        meta.className = 'flex items-center gap-2 text-sm text-zinc-400';
        const num = document.createElement('span');
        num.className = 'font-mono text-zinc-300';
        num.textContent = `#${chunk.index + 1}`;
        const count = document.createElement('span');
        count.textContent = `${chunk.chars} chars`;
        meta.append(num, count);

        // "stitch test" checkbox — hidden until this chunk has been generated.
        // Scoped STRICTLY to the "▶ Concatenate selected" seam test; it must
        // never read as project-inclusion (Create project carries every render
        // regardless — real exclusion lives on the project's 🔇 skip toggle).
        const include = document.createElement('label');
        include.className = 'hidden cursor-pointer items-center gap-1.5 text-xs text-zinc-400';
        include.title = "Include this render when “▶ Concatenate selected” stitches chunks through the production trim + seam join. Doesn't affect Create project — every render you make here carries over.";
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = true;
        cb.className = 'accent-cyan-500';
        include.append(cb, document.createTextNode('stitch test'));
        state.checkbox = cb;

        const genBtn = document.createElement('button');
        genBtn.type = 'button';
        genBtn.className = 'rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800';
        genBtn.textContent = '▶ Generate';

        const actions = document.createElement('div');
        actions.className = 'flex items-center gap-3';
        actions.append(include, genBtn);
        head.append(meta, actions);

        const body = document.createElement('p');
        body.className = 'mt-2 whitespace-pre-wrap break-words text-sm text-zinc-200';
        body.textContent = chunk.text;

        const player = buildAPlayer('take', { label: 'Play chunk', hidden: true, extraClass: 'mt-3' });
        const audio = player.querySelector('.aplayer__native');

        if (els.voice) {
            genBtn.addEventListener('click', async () => {
                // stash=1: the server parks the raw render under a token so the
                // "Create project" CTA can adopt it as a take (no re-billing).
                const result = await generate(urls.synthesize, chunk.text, audio, genBtn, 'Generating…', { stash: '1' });
                if (!result) return;
                state.blob = result.blob;
                state.token = result.token;
                state.voice = els.voice.value;
                include.classList.remove('hidden');
                include.classList.add('inline-flex');
                refreshConcatBar();
                refreshCarryNote();
            });
        } else {
            genBtn.disabled = true;
            genBtn.classList.add('opacity-40');
        }

        li.append(head, body, player);
        return li;
    }

    // Stitch the selected, already-generated chunks through the production
    // trim + seam concatenation and play the result.
    async function concatSelected() {
        const chosen = chunkStates.filter((s) => s.blob && s.checkbox?.checked);
        if (!chosen.length) {
            setStatus(els.concatStatus, 'Tick “stitch test” on at least one generated chunk first.', 'error');
            return;
        }
        const fd = new FormData();
        // Voice + each blob's source text ride along so the server-side trim
        // can spare a rendered trailing sound tag (turbo voices) exactly like
        // production stitching does.
        if (els.voice?.value) fd.append('voice', els.voice.value);
        chosen.forEach((s) => {
            fd.append('files[]', s.blob, `chunk-${s.index + 1}.wav`);
            fd.append('breaks[]', s.breakAfter);
            fd.append('texts[]', s.text ?? '');
        });

        const t0 = performance.now();
        startBusy(els.concatBtn, 'Concatenating…');
        try {
            const res = await fetch(urls.concat, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*' },
                body: fd,
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            playAudio(els.concatAudio, await res.blob());
            const span = chosen.map((s) => `#${s.index + 1}`).join(', ');
            setStatus(els.concatStatus, `✓ Concatenated ${span} in ${elapsed(t0)}s.`, 'ok');
        } catch (err) {
            setStatus(els.concatStatus, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(els.concatBtn);
        }
    }

    function renderPreview(data) {
        normalizedText = data.normalized;
        els.normalized.textContent = data.normalized || '(empty after cleanup)';
        els.normChars.textContent = data.chars;
        els.chunkCount.textContent = data.chunks.length;

        chunkStates = data.chunks.map((c) => ({ index: c.index, text: c.text, breakAfter: c.breakAfter, blob: null, token: null, voice: null, checkbox: null }));
        els.chunks.replaceChildren(...data.chunks.map((c, i) => chunkCard(c, chunkStates[i])));
        enhanceStudioPlayers(els.chunks); // skin the freshly-built chunk players

        renderEstimate(data.estimate);
        renderApplied(data.pronunciation?.applied ?? []);

        hidePlayer(els.wholeAudio);
        els.concatBar.classList.add('hidden');
        hidePlayer(els.concatAudio);
        setStatus(els.concatStatus, '');
        setStatus(els.createStatus, '');
        refreshCarryNote();
        els.results.classList.remove('hidden');
    }

    // Server-formatted cost estimate for one render of every chunk (the server
    // owns all money math and viewer awareness — SuperAdmins see actual spend,
    // everyone else their marked-up price). Absent = no rates configured.
    function renderEstimate(estimate) {
        if (!els.estimate) return;
        els.estimate.classList.toggle('hidden', !estimate);
        if (!estimate) return;
        els.estimateLabel.textContent = estimate.label;
        els.estimateLabel.title = estimate.title;
        renderBalance(estimate.balance);
    }

    // Paint the "credit $X.XX" badge from a server-formatted {label, low} (or
    // hide it when absent/unlimited). Shared by the preview estimate and the
    // live refresh fetchBlob() runs after each charged render.
    function renderBalance(balance) {
        if (!els.balance) return;
        els.balance.classList.toggle('hidden', !balance);
        if (!balance) return;
        els.balance.textContent = balance.label;
        els.balance.classList.toggle('text-red-300', balance.low);
        els.balance.classList.toggle('border-red-500/40', balance.low);
    }

    // ── Pronunciation panel ─────────────────────────────────────────────────
    // "Applied" lists the dictionary respellings already IN the text above;
    // suggestions arrive async from the LLM and can be added with one click.

    function syncPronVisibility() {
        if (!els.pron) return;
        const hasContent = !els.pronApplied.classList.contains('hidden')
            || els.pronSuggestions.childElementCount > 0
            || els.pronStatus.textContent.trim() !== '';
        els.pron.classList.toggle('hidden', !hasContent);
    }

    function renderApplied(applied) {
        if (!els.pronApplied) return;
        if (applied.length) {
            els.pronApplied.textContent = 'Applied: ' + applied.map((a) => `${a.term} → ${a.phonetic}`).join('  ·  ');
        }
        els.pronApplied.classList.toggle('hidden', !applied.length);
        syncPronVisibility();
    }

    function suggestionRow(s) {
        const li = document.createElement('li');
        li.className = 'flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/8 bg-inset/60 px-3 py-2 text-sm';

        const label = document.createElement('span');
        const term = document.createElement('span');
        term.className = 'font-medium text-zinc-200';
        term.textContent = s.term;
        const phonetic = document.createElement('span');
        phonetic.className = 'text-cyan-300';
        phonetic.textContent = s.phonetic;
        label.append(term, document.createTextNode(' → '), phonetic);
        if (s.note) {
            const note = document.createElement('span');
            note.className = 'ml-2 text-xs text-zinc-500';
            note.textContent = s.note;
            label.append(note);
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rounded-lg border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800';
        btn.textContent = 'Add to dictionary';
        btn.addEventListener('click', async () => {
            startBusy(btn, 'Adding…');
            try {
                const body = new URLSearchParams({ term: s.term, phonetic: s.phonetic });
                ['category', 'confidence', 'note', 'match_mode'].forEach((k) => { if (s[k]) body.set(k, s[k]); });
                const res = await fetch(urls.approve, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                    body,
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                endBusy(btn);
                const added = document.createElement('span');
                added.className = 'text-xs text-emerald-300';
                added.textContent = '✓ added';
                btn.replaceWith(added);
                setStatus(els.pronStatus, 'Added to your dictionary — run Preview again to apply it to the text.', 'ok');
            } catch (err) {
                endBusy(btn);
                setStatus(els.pronStatus, `✗ ${err.message}`, 'error');
            }
        });

        li.append(label, btn);
        return li;
    }

    // Guards against a stale response landing after the user re-previewed.
    let suggestionsSeq = 0;

    async function loadSuggestions(text) {
        if (!els.pron || !urls.suggestions) return;
        const seq = ++suggestionsSeq;
        els.pronSuggestions.replaceChildren();
        setStatus(els.pronStatus, 'Checking for words worth respelling…');
        syncPronVisibility();
        try {
            const res = await fetch(urls.suggestions, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                body: new URLSearchParams({ text }),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            if (seq !== suggestionsSeq) return;
            if (!data.available || !data.suggestions.length) {
                setStatus(els.pronStatus, data.available ? 'No new pronunciation suggestions for this text.' : '');
                syncPronVisibility();
                return;
            }
            setStatus(els.pronStatus, '');
            els.pronSuggestions.replaceChildren(...data.suggestions.map(suggestionRow));
            syncPronVisibility();
        } catch {
            // Detection is best-effort — a failure just means no suggestions panel.
            if (seq === suggestionsSeq) {
                setStatus(els.pronStatus, '');
                syncPronVisibility();
            }
        }
    }

    // ── Create project (the closing CTA) ────────────────────────────────────

    // Renders eligible to ride into the project: stashed, and made with the
    // voice the project will actually be created with (switching the voice
    // after rendering keeps the audio playable here, but it must not become a
    // take that contradicts the project's voice).
    const carryable = () => chunkStates.filter((s) => s.token && s.voice === (els.voice?.value ?? ''));

    function refreshCarryNote() {
        if (!els.carryNote) return;
        const n = carryable().length;
        els.carryNote.classList.toggle('hidden', n === 0);
        if (n > 0) {
            els.carryNote.textContent = ` ${n} chunk render${n === 1 ? '' : 's'} you made here will carry over as ${n === 1 ? 'a take' : 'takes'} — already paid for, no re-generation.`;
        }
    }

    async function createProject() {
        const text = els.text.value.trim();
        if (!text) {
            setStatus(els.createStatus, 'Paste some text first.', 'error');
            return;
        }
        const body = paramsObject(text);
        if (els.projectTitle?.value.trim()) body.title = els.projectTitle.value.trim();
        body.takes = carryable().map((s) => ({ index: s.index, token: s.token }));

        startBusy(els.createBtn, 'Creating…');
        try {
            const res = await fetch(urls.createProject, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            setStatus(els.createStatus, '✓ Project created — opening…', 'ok');
            window.location.assign(data.url); // stay "busy" until the page swaps
        } catch (err) {
            setStatus(els.createStatus, `✗ ${err.message}`, 'error');
            endBusy(els.createBtn);
        }
    }

    async function preview() {
        const text = els.text.value.trim();
        if (!text) {
            setStatus(els.status, 'Paste some text first.', 'error');
            return;
        }
        startBusy(els.previewBtn, 'Analyzing…');
        setStatus(els.status, 'Normalizing and chunking…');
        try {
            const res = await fetch(urls.preview, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                // Full params: the voice picks the model that prices the estimate.
                body: params(text),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            renderPreview(await res.json());
            setStatus(els.status, '');
            loadSuggestions(text); // async LLM check — never blocks the breakdown
        } catch (err) {
            setStatus(els.status, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(els.previewBtn);
        }
    }

    els.previewBtn.addEventListener('click', preview);
    els.concatBtn.addEventListener('click', concatSelected);
    els.createBtn?.addEventListener('click', createProject);
    // Switching voices changes which stashed renders may carry into a project.
    els.voice?.addEventListener('change', refreshCarryNote);

    // Editing the text invalidates the breakdown — hide it until re-previewed.
    els.text.addEventListener('input', () => els.results.classList.add('hidden'));

    initTuningKnobs(root); // wire the single-shot Exaggeration / CFG-Pace / Temperature sliders

    els.stitchBtn?.addEventListener('click', () =>
        generate(urls.stitch, normalizedText, els.wholeAudio, els.stitchBtn, 'Stitching…'));
}

initStudio();

// ---------------------------------------------------------------------------
// Studio "Advanced tuning" toggle (per-user, persisted) — reveals the
// per-preview knobs. The A/B bench lives on the voice edit page (below).
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Studio segmented tabs (Projects / Inspector). Only one panel is on screen at a
// time; the active tab is persisted in the URL (?tab=) so refresh/back and the
// server-side project paginator (which reloads) land on the right view.
// ---------------------------------------------------------------------------
function initStudioTabs() {
    const root = document.querySelector('[data-studio-tabs]');
    if (!root) return;

    const buttons = Array.from(root.querySelectorAll('[data-studio-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-studio-panel]'));
    if (!buttons.length || !panels.length) return;

    const TAB_ON = ['bg-accent', 'text-accent-on'];
    const TAB_OFF = ['text-zinc-400', 'hover:text-zinc-100'];
    const PILL_ON = 'bg-accent-on/25';
    const PILL_OFF = 'bg-white/8';

    function activate(name, { push = true } = {}) {
        buttons.forEach((btn) => {
            const on = btn.dataset.studioTab === name;
            btn.classList.remove(...TAB_ON, ...TAB_OFF);
            btn.classList.add(...(on ? TAB_ON : TAB_OFF));
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
            const pill = btn.querySelector('[data-tab-count]');
            if (pill) {
                pill.classList.remove(PILL_ON, PILL_OFF);
                pill.classList.add(on ? PILL_ON : PILL_OFF);
            }
        });
        panels.forEach((p) => p.classList.toggle('hidden', p.dataset.studioPanel !== name));

        if (push) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', name);
            // Page number only belongs to the projects list — drop it elsewhere.
            if (name !== 'projects') url.searchParams.delete('page');
            window.history.replaceState({}, '', url);
        }
    }

    const params = new URLSearchParams(window.location.search);
    activate(params.get('tab') === 'inspector' ? 'inspector' : 'projects', { push: false });

    buttons.forEach((btn) => btn.addEventListener('click', () => activate(btn.dataset.studioTab)));
}
initStudioTabs();

function initStudioAdvancedToggle() {
    const root = document.getElementById('studio');
    const toggle = document.getElementById('studio-advanced-toggle');
    const panel = document.getElementById('studio-advanced');
    if (!root || !toggle || !panel) return;

    toggle.addEventListener('change', () => {
        panel.classList.toggle('hidden', !toggle.checked);
        // Persist the preference; a failure just means it isn't remembered.
        fetch(root.dataset.advancedUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            body: new URLSearchParams({ enabled: toggle.checked ? '1' : '0' }),
        }).catch(() => {});
    });
}
initStudioAdvancedToggle();

// "Getting Started" intro messages (Dashboard, Studio, Voices, Pronunciations,
// API Keys — one panel per page, see the x-getting-started component):
// intercept the dismiss form, hide the panel in place, reveal the header's
// restore control where the page has one (only the Dashboard does), persist
// via fetch. Without JS the form still works as a plain POST. Both toggled
// roots are block-level, so `hidden` alone is safe (no static flex/grid to
// pair — see the app.css note).
function initGettingStarted() {
    const panel = document.getElementById('getting-started');
    const form = panel?.querySelector('[data-getting-started-dismiss]');
    if (!panel || !form) return;

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        panel.classList.add('hidden');
        const restore = document.getElementById('getting-started-restore');
        if (restore) {
            restore.classList.remove('hidden');
            restore.querySelector('button')?.focus();
        }
        // Persist the preference; a failure just means it reappears next load.
        fetch(panel.dataset.dismissUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            body: new URLSearchParams({ show: '0', page: panel.dataset.page || 'dashboard' }),
        }).catch(() => {});
    });
}
initGettingStarted();

// ---------------------------------------------------------------------------
// A/B tuning bench (voice edit page): audition ONE voice at several settings,
// pick the winner, save it as that voice's defaults. The bench root carries the
// voice slug and endpoints as data attributes; presets are named knob pairs
// reusable on any voice's bench.
// ---------------------------------------------------------------------------
// The bench's knob columns per engine. `param` is the request/knob key,
// `data` the camelCase dataset key the blade stamps values under (on the
// bench root, preset chips, and new-preset payloads). Ranges mirror
// ChatterboxTuning / ChatterboxTurboTuning.
const BENCH_KNOBS = {
    'chatterbox': [
        { param: 'exaggeration', data: 'exaggeration', ph: '0.5', min: '0.25', max: '2', step: '0.05' },
        { param: 'cfg_weight', data: 'cfg', ph: '0.5', min: '0.2', max: '1', step: '0.05' },
        { param: 'temperature', data: 'temperature', ph: '0.8', min: '0.5', max: '1.5', step: '0.05' },
    ],
    'chatterbox-turbo': [
        { param: 'top_p', data: 'topP', ph: '0.95', min: '0.5', max: '1', step: '0.01' },
        { param: 'top_k', data: 'topK', ph: '1000', min: '1', max: '2000', step: '1' },
        { param: 'repetition_penalty', data: 'repetitionPenalty', ph: '1.2', min: '1', max: '2', step: '0.05' },
        { param: 'temperature', data: 'temperature', ph: '0.8', min: '0.5', max: '1.5', step: '0.05' },
    ],
    // Qwen's only knob is a free-text style note — its rows audition wordings.
    'qwen3-tts': [
        { param: 'style_instruction', data: 'styleInstruction', type: 'text', ph: 'e.g. speak slowly and calmly' },
    ],
};

// Row two of the bench: a more-expressive contrast to row one's defaults.
// (Qwen seeds its own fixed style menu instead — see BENCH_QWEN_STYLES.)
const BENCH_CONTRAST = {
    'chatterbox': { exaggeration: '0.95', cfg_weight: '0.8', temperature: '0.9' },
    'chatterbox-turbo': { top_p: '0.85', top_k: '300', repetition_penalty: '1.35', temperature: '1' },
};

// Qwen has no numeric knobs — its bench auditions plain-words style notes.
// A fixed four-delivery menu every Qwen voice starts from; "Save pick as
// voice defaults" writes the chosen note to the voice's Style note WITHOUT
// changing this menu, so a reload always shows the same four options.
const BENCH_QWEN_STYLES = [
    'speak clearly in a confident and steady delivery like a newscaster',
    'speak clearly with an animated, upbeat and lively delivery',
    'speak clearly with an excited and overjoyed delivery',
    'speak clearly but seriously and with gravitas',
];

function initTuningBench(bench) {
    const synthUrl = bench.dataset.synthesizeUrl;
    const voice = bench.dataset.voice;
    // The voice's SAVED engine decides the knob columns (the blade renders the
    // matching header). An unsaved engine change retunes the bench after save.
    const model = bench.dataset.model || 'chatterbox';
    const knobDefs = BENCH_KNOBS[model] || BENCH_KNOBS.chatterbox;
    // Must match $benchGrid in _tuning_bench.blade.php (the header row).
    const rowGrid = model === 'chatterbox-turbo'
        ? 'grid-cols-[44px_repeat(4,minmax(0,1fr))_56px_minmax(110px,150px)_32px]'
        : (model === 'qwen3-tts'
            ? 'grid-cols-[44px_minmax(0,1fr)_56px_minmax(110px,150px)_32px]'
            : 'grid-cols-[44px_repeat(3,minmax(0,1fr))_56px_minmax(110px,150px)_32px]');
    const els = {
        text: bench.querySelector('.bench-text'),
        rows: bench.querySelector('.bench-rows'),
        addBtn: bench.querySelector('.bench-add'),
        genBtn: bench.querySelector('.bench-generate'),
        status: bench.querySelector('.bench-status'),
    };

    const rows = [];

    // The step-3 delivery fields the picked row writes into. This is the bench's
    // ONLY save path: values land in the page form, the save bar names them, and
    // the form's single Save persists them. (Voices whose page has no such
    // fields — none today — degrade to an audition-only bench.)
    const form = bench.closest('form');
    const deliveryField = (param) => form?.querySelector(`[data-delivery-field="${param}"]`);

    const knob = (value, def) => {
        const input = document.createElement('input');
        if (def.type === 'text') {
            Object.assign(input, { type: 'text', placeholder: def.ph });
        } else {
            Object.assign(input, { type: 'number', step: def.step, min: def.min, max: def.max, placeholder: def.ph });
        }
        if (value !== null && value !== '' && value !== undefined) input.value = value;
        input.className = (def.type === 'text' ? 'w-full' : 'w-[74px]')
            + ' rounded-[8px] border border-edge bg-inset px-2.5 py-2 text-[15px] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
        return input;
    };

    // A row's typed knob values as `param -> value` (set ones only).
    const rowValues = (state) => {
        const values = {};
        knobDefs.forEach((def) => {
            const v = state.inputs[def.param].value;
            if (v !== '') values[def.param] = v;
        });
        return values;
    };

    // The delivery fields' CURRENT values — what the voice would save as right
    // now. Row one is seeded from these, and the audition button reads them.
    const deliveryValues = () => {
        const values = {};
        knobDefs.forEach((def) => {
            const v = deliveryField(def.param)?.value ?? '';
            if (v !== '') values[def.param] = v;
        });
        return values;
    };

    // Push a row's values into the delivery fields. `input` + `change` so the
    // save bar and the rail pick the edit up like any typed one.
    const writeDelivery = (values) => {
        knobDefs.forEach((def) => {
            const field = deliveryField(def.param);
            if (!field) return;
            field.value = values[def.param] ?? '';
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
    };

    // Row one always mirrors the voice's saved defaults, so the table reads as
    // "here's what you have, here's what you're considering".
    const labelFor = (state) => {
        if (state.saved) return 'current defaults';
        if (state.pick.checked) return '★ your pick';
        return state.generated ? 'take ready' : 'not generated';
    };
    const labelToneFor = (state) => state.saved
        ? 'text-ok'
        : (state.pick.checked ? 'text-zinc-200' : 'text-zinc-600');

    function syncRows() {
        rows.forEach((state) => {
            state.li.dataset.state = state.pick.checked ? 'picked' : 'candidate';
            state.label.textContent = labelFor(state);
            state.label.className = `block text-[12px] ${labelToneFor(state)}`;
            // A generated row's play button turns green like the saved row's —
            // "this one you've heard".
            state.playBtn.classList.toggle('border-ok/60', state.generated || state.saved);
            state.playBtn.classList.toggle('text-ok', state.generated || state.saved);
            state.playBtn.classList.toggle('border-accent/50', !(state.generated || state.saved));
            state.playBtn.classList.toggle('text-accent', !(state.generated || state.saved));
        });
    }

    const loadAudio = (audio, blob) => {
        audio.src = URL.createObjectURL(blob);
        (audio.closest('.aplayer') || audio).classList.remove('hidden');
    };

    // One synthesis of the sample line at `values`. Shared by the row ▶ buttons
    // and (on Qwen) the "Audition this style note" button.
    async function synthesize(values, btn) {
        const text = els.text.value.trim();
        if (!text) { setStatus(els.status, 'Type a sample line first.', 'error'); return null; }
        const body = new URLSearchParams({ text, voice });
        Object.entries(values).forEach(([param, value]) => body.set(param, value));
        const t0 = performance.now();
        startBusy(btn, '');
        try {
            const res = await fetch(synthUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*' },
                body,
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const blob = await res.blob();
            setStatus(els.status, `✓ Generated in ${elapsed(t0)}s.`, 'ok');
            return blob;
        } catch (err) {
            setStatus(els.status, `✗ ${err.message}`, 'error');
            return null;
        } finally {
            endBusy(btn);
        }
    }

    async function generateRow(state, btn, autoplay = true) {
        const blob = await synthesize(rowValues(state), btn);
        if (!blob) return;
        autoplay ? playAudio(state.audio, blob) : loadAudio(state.audio, blob);
        state.generated = true;
        syncRows();
    }

    function addRow(values = {}, { saved = false, autoPick = true } = {}) {
        const li = document.createElement('li');
        li.className = `vtake-row grid ${rowGrid} items-center gap-2 border-b border-white/6 px-4 py-3 last:border-b-0`;

        const pick = document.createElement('input');
        Object.assign(pick, { type: 'radio', name: 'bench-pick', title: 'Make this row the voice’s new defaults' });
        pick.className = 'accent-emerald-500';

        const inputs = {};
        knobDefs.forEach((def) => { inputs[def.param] = knob(values[def.param] ?? null, def); });

        const playBtn = document.createElement('button');
        playBtn.type = 'button';
        playBtn.className = 'grid h-[34px] w-[34px] place-items-center rounded-full border border-accent/50 text-accent transition hover:bg-accent/10';
        playBtn.textContent = '▶';

        // Take cell: a state label (current defaults / your pick / not generated)
        // above the app's standard player, which appears once a take is made.
        const take = document.createElement('div');
        take.className = 'min-w-0';
        const label = document.createElement('span');
        const player = buildAPlayer('take', { label: 'Play take', hidden: true });
        const audio = player.querySelector('.aplayer__native');
        take.append(label, player);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'text-center text-zinc-600 hover:text-zinc-300' + (saved ? ' invisible' : '');
        remove.title = 'Remove';
        remove.textContent = '✕';
        remove.disabled = saved;

        li.append(pick, ...knobDefs.map((def) => inputs[def.param]), playBtn, take, remove);

        const state = { li, inputs, audio, pick, playBtn, label, saved, generated: false };
        rows.push(state);
        if (autoPick && rows.length === 1) pick.checked = true;

        playBtn.addEventListener('click', () => generateRow(state, playBtn));
        // Picking is what makes a row the pending new default. Programmatic
        // checks (row one on load) fire no `change`, so they never dirty the form.
        pick.addEventListener('change', () => { writeDelivery(rowValues(state)); syncRows(); });
        // Editing the picked row's knobs keeps the delivery fields in step.
        knobDefs.forEach((def) => inputs[def.param].addEventListener('input', () => {
            if (pick.checked) writeDelivery(rowValues(state));
        }));
        remove.addEventListener('click', () => {
            const i = rows.indexOf(state);
            if (i >= 0) rows.splice(i, 1);
            li.remove();
            if (pick.checked && rows[0]) { rows[0].pick.checked = true; writeDelivery(rowValues(rows[0])); }
            syncRows();
        });

        els.rows.append(li);
        enhanceStudioPlayers(li); // skin the row's take player
        syncRows();
        return state;
    }

    async function generateAll() {
        if (!els.text.value.trim()) { setStatus(els.status, 'Type a sample line first.', 'error'); return; }
        startBusy(els.genBtn, 'Generating…');
        try {
            // Sequential — the provider is rate-limited; load each without autoplay
            // so the user compares them deliberately, not all at once.
            for (const state of rows) await generateRow(state, els.genBtn, false);
            setStatus(els.status, '✓ Generated all settings — play each to compare.', 'ok');
        } finally {
            endBusy(els.genBtn);
        }
    }

    els.addBtn.addEventListener('click', () => addRow());
    els.genBtn.addEventListener('click', generateAll);

    // "Audition this style note" (Qwen's step 3): hear the delivery fields
    // exactly as they stand, without adding a row.
    const auditionBtn = form?.querySelector('[data-bench-audition]');
    if (auditionBtn) {
        let auditionAudio = null;
        auditionBtn.addEventListener('click', async () => {
            const blob = await synthesize(deliveryValues(), auditionBtn);
            if (!blob) return;
            if (!auditionAudio) {
                auditionAudio = document.createElement('audio');
                auditionAudio.className = 'hidden';
                auditionBtn.parentElement.append(auditionAudio);
            }
            playAudio(auditionAudio, blob);
        });
    }

    // --- Named presets: apply adds a pre-filled row; ✕ deletes; save the picked
    // row's values as a new named preset. Tucked in a menu off the table footer. ---
    const presetsBar = bench.querySelector('.bench-presets');
    if (presetsBar) {
        const storeUrl = presetsBar.dataset.storeUrl;
        const emptyHint = bench.querySelector('.bench-preset-empty');
        const presetList = bench.querySelector('.bench-preset-list');
        const presetSaveBtn = bench.querySelector('.bench-preset-save');
        const presetToggle = bench.querySelector('.bench-presets-toggle');
        const presetMenu = bench.querySelector('.bench-presets-menu');

        const setMenu = (open) => {
            presetMenu.classList.toggle('hidden', !open);
            presetToggle.setAttribute('aria-expanded', String(open));
        };
        presetToggle.addEventListener('click', () => setMenu(presetMenu.classList.contains('hidden')));
        document.addEventListener('click', (e) => {
            if (!presetsBar.contains(e.target)) setMenu(false);
        });

        const refreshEmpty = () =>
            emptyHint?.classList.toggle('hidden', presetList.querySelectorAll('.bench-preset').length > 0);

        // A chip's knob values keyed by request param (this bench's engine only
        // — the blade already filters chips to the bench's engine).
        const chipValues = (chip) => {
            const values = {};
            knobDefs.forEach((def) => {
                if (chip.dataset[def.data]) values[def.param] = chip.dataset[def.data];
            });
            return values;
        };

        const wireChip = (chip) => {
            chip.querySelector('.preset-apply').addEventListener('click', () => {
                // A preset is a candidate, not a decision: it lands as a new row
                // and only becomes the default once picked and saved.
                addRow(chipValues(chip));
                setMenu(false);
            });
            chip.querySelector('.preset-delete').addEventListener('click', async () => {
                const name = chip.querySelector('.preset-apply').textContent;
                if (!(await confirmDialog({
                    title: 'Delete this preset?',
                    message: `“${name}” is deleted permanently, everywhere it's offered.`,
                    label: 'Delete preset',
                }))) return;
                try {
                    const res = await fetch(`${storeUrl}/${chip.dataset.id}`, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                    });
                    if (!res.ok) throw new Error(await errorMessage(res));
                    chip.remove();
                    refreshEmpty();
                } catch (err) {
                    setStatus(els.status, `✗ ${err.message}`, 'error');
                }
            });
        };

        const addChip = (preset) => {
            const chip = document.createElement('span');
            chip.className = 'bench-preset inline-flex items-center gap-1 rounded-full border border-white/12 bg-inset py-0.5 pr-1.5 pl-2.5 text-xs';
            chip.dataset.id = preset.id;
            chip.dataset.exaggeration = preset.exaggeration ?? '';
            chip.dataset.cfg = preset.cfg_weight ?? '';
            chip.dataset.temperature = preset.temperature ?? '';
            chip.dataset.topP = preset.top_p ?? '';
            chip.dataset.topK = preset.top_k ?? '';
            chip.dataset.repetitionPenalty = preset.repetition_penalty ?? '';
            const apply = document.createElement('button');
            apply.type = 'button';
            apply.className = 'preset-apply text-zinc-200 hover:text-accent';
            apply.textContent = preset.name;
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'preset-delete text-zinc-500 hover:text-bad';
            del.title = 'Delete preset';
            del.textContent = '✕';
            chip.append(apply, del);
            presetList.append(chip);
            wireChip(chip);
            refreshEmpty();
        };

        presetList.querySelectorAll('.bench-preset').forEach(wireChip);

        presetSaveBtn?.addEventListener('click', async () => {
            const picked = rows.find((s) => s.pick.checked);
            if (!picked) { setStatus(els.status, 'Pick a row to save as a preset.', 'error'); return; }
            const name = await promptDialog({
                title: 'Save tuning preset',
                message: 'Name this preset so you can apply it to other projects later.',
                label: 'Save preset',
                placeholder: 'e.g. Warm narration',
            });
            if (!name) return;
            // The preset records the bench's engine so pickers offer it only
            // where its knobs apply.
            const body = new URLSearchParams({ name, model });
            Object.entries(rowValues(picked)).forEach(([param, value]) => body.set(param, value));
            startBusy(presetSaveBtn, 'Saving…');
            try {
                const res = await fetch(storeUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                    body,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
                addChip(data.preset);
                setStatus(els.status, `✓ Saved preset "${data.preset.name}".`, 'ok');
            } catch (err) {
                setStatus(els.status, `✗ ${err.message}`, 'error');
            } finally {
                endBusy(presetSaveBtn);
            }
        });
    }

    // Qwen seeds a fixed four-delivery menu that stays put across reloads (a
    // saved pick writes the voice's Style note, not this menu). The numeric
    // engines seed row one from the voice's current defaults and row two with
    // a more-expressive contrast to compare.
    if (model === 'qwen3-tts') {
        const saved = bench.dataset.styleInstruction || '';
        // The four fixed deliveries, led by the voice's own note when that isn't
        // already one of them — so "current defaults" never labels a row that
        // isn't. With no note saved yet, nothing is pre-picked: an unpicked
        // table is the honest reading of "this voice has no style note".
        const styles = saved && !BENCH_QWEN_STYLES.includes(saved)
            ? [saved, ...BENCH_QWEN_STYLES]
            : BENCH_QWEN_STYLES;
        styles.forEach((style) => {
            const isSaved = saved !== '' && style === saved;
            const state = addRow({ style_instruction: style }, { saved: isSaved, autoPick: false });
            if (isSaved) state.pick.checked = true;
        });
        syncRows();
        return;
    }

    const currentDefaults = {};
    knobDefs.forEach((def) => {
        if (bench.dataset[def.data]) currentDefaults[def.param] = bench.dataset[def.data];
    });
    addRow(currentDefaults, { saved: true });
    addRow(BENCH_CONTRAST[model] || BENCH_CONTRAST.chatterbox);
}
document.querySelectorAll('.tuning-bench').forEach(initTuningBench);

// ---------------------------------------------------------------------------
// Studio project editor: regenerate one chunk, rebuild the stitched final.
// ---------------------------------------------------------------------------
const STATUS_STYLES = {
    completed: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    ready: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    stale: 'border-amber-500/30 bg-amber-500/10 text-amber-300',
    failed: 'border-red-500/30 bg-red-500/10 text-red-300',
    pending: 'border-zinc-700 bg-zinc-800 text-zinc-400',
    draft: 'border-zinc-700 bg-zinc-800 text-zinc-400',
    // Virtual status from the run poll: waiting its turn in an active
    // background run (cyan = the run's color). Must match $chunkStyles in
    // show.blade.php.
    queued: 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',
};

// ASR transcript-QA badge tones — the same emerald/amber/red/zinc palette as the
// sibling status pills. Green pass, amber "fixed" (auto-remediation changed the
// audio), red "check" (flagged, needs a human), muted "reviewed" (dismissed).
// Used by the take-list pills and by renderQaBadge below.
const ASR_TONE = {
    ok: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    fixed: 'border-amber-500/30 bg-amber-500/10 text-amber-300',
    bad: 'border-red-500/30 bg-red-500/10 text-red-300',
    reviewed: 'border-zinc-700 bg-zinc-800 text-zinc-400',
};

// Fill a .qa-badge-wrap element with the pill + hover/focus popover from the
// server's badge payload, mirroring resources/views/admin/studio/projects/
// _qa-badge.blade.php — keep the two in lockstep. Shared by the chunk header
// badge (renderQaBadge) and the take-list pills (takeRow). Opts:
//   compact     — smaller pill sizing for the take list
//   withActions — render the undo footer. Only the chunk header gets it: those
//                 actions operate on the chunk's CURRENT audio, so a historical
//                 take's pill is informational (heading + body only).
function fillQaBadge(wrap, info, { compact = false, withActions = false } = {}) {
    const size = compact ? 'px-1.5 py-0.5 text-[11px]' : 'px-2 py-0.5 text-xs';

    const pill = document.createElement('button');
    pill.type = 'button';
    pill.className = 'qa-badge cursor-help rounded-md border ' + size + ' ' + (ASR_TONE[info.tone] || ASR_TONE.bad);
    pill.setAttribute('aria-haspopup', 'dialog');
    pill.setAttribute('aria-expanded', 'false');
    pill.textContent = info.text;

    const pop = document.createElement('span');
    pop.className = 'qa-popover qa-popover--' + info.tone + ' hidden';
    pop.setAttribute('role', 'tooltip');

    const arrow = document.createElement('span');
    arrow.className = 'qa-popover__arrow';
    arrow.setAttribute('aria-hidden', 'true');
    pop.append(arrow);

    if (info.heading) {
        const head = document.createElement('span');
        head.className = 'qa-popover__head';
        const icon = document.createElement('span');
        icon.className = 'qa-popover__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = (info.tone === 'fixed' || info.tone === 'bad') ? '⚠' : '✓';
        const label = document.createElement('span');
        label.textContent = info.heading;
        head.append(icon, label);
        pop.append(head);
    }

    const body = document.createElement('span');
    body.className = 'qa-popover__body';
    body.textContent = info.body;
    if (info.fix) {
        body.append(' ');
        if (info.fix.label) {
            const strong = document.createElement('strong');
            strong.textContent = info.fix.label;
            body.append(strong, ' ');
        }
        body.append(info.fix.text);
    }
    pop.append(body);

    if (withActions && info.actions && info.actions.length) {
        const foot = document.createElement('span');
        foot.className = 'qa-popover__foot';
        if (info.prompt) {
            const prompt = document.createElement('span');
            prompt.className = 'qa-popover__prompt';
            prompt.textContent = info.prompt;
            foot.append(prompt);
        }
        info.actions.forEach((act, i) => {
            if (i > 0) {
                const sep = document.createElement('span');
                sep.className = 'qa-popover__sep';
                sep.setAttribute('aria-hidden', 'true');
                sep.textContent = '·';
                foot.append(sep);
            }
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'qa-act';
            btn.dataset.qaAct = act.act;
            btn.textContent = act.label;
            foot.append(btn);
        });
        pop.append(foot);
    }

    wrap.replaceChildren(pill, pop);
}

// Rebuild the chunk header's .chunk-asr-badge wrapper. Toggling hidden vs
// relative/inline-flex by full className rewrite, never leaving both set
// (inline-flex would otherwise win over hidden in the compiled CSS). The header
// badge carries the interactive footer; .qa-badge-wrap opts it into the shared
// popover interaction (initQaPopovers).
function renderQaBadge(wrap, info) {
    if (!wrap) return;
    if (!info) {
        wrap.className = 'chunk-asr-badge qa-badge-wrap hidden';
        wrap.replaceChildren();
        return;
    }
    wrap.className = 'chunk-asr-badge qa-badge-wrap relative inline-flex';
    fillQaBadge(wrap, info, { withActions: true });
}

// Human labels for a take's provenance (its stored `source` token — see
// ProjectService::recordTake and the duplicate copier). Presentation only:
// the payload keeps the raw token, and an unknown token shows itself. A plain
// 'generate' take deliberately has NO label ('') — with Regenerate as the one
// render action it's the unmarked default; only the exceptions get named
// (QA auto-fix, Inspector, copies, and rows from retired legacy flows).
// "QA auto-fix" is deliberately outcome-neutral — the adjacent QA badge says
// whether the fix actually recovered ("fixed by re-roll" vs "still flagged").
const TAKE_SOURCE_LABELS = {
    generate: '',
    reroll: 'rendered with Re-roll',
    preview: 'tuning preview',
    use: 'kept from a preview',
    remediate: 'QA auto-fix',
    duplicate: 'copied from the original project',
    inspector: 'carried over from the Inspector',
};

function initStudioProject() {
    const root = document.getElementById('studio-project');
    if (!root) return;

    // First-run QA orientation (design 10E): reveal the one-liner once per browser
    // and dismiss it for good. Starts hidden in the Blade so it never flashes for a
    // returning user; localStorage mirrors the finetune-open persistence pattern.
    const qaIntro = document.getElementById('qa-intro');
    if (qaIntro) {
        const QA_INTRO_KEY = 'alias.studio.qaIntroDismissed';
        let qaIntroDismissed = false;
        try { qaIntroDismissed = localStorage.getItem(QA_INTRO_KEY) === '1'; } catch { /* storage unavailable */ }
        if (!qaIntroDismissed) {
            qaIntro.classList.remove('hidden');
            qaIntro.classList.add('flex');
        }
        document.getElementById('qa-intro-dismiss')?.addEventListener('click', () => {
            qaIntro.classList.add('hidden');
            qaIntro.classList.remove('flex');
            try { localStorage.setItem(QA_INTRO_KEY, '1'); } catch { /* ignore */ }
        });
    }

    // Built-in Delivery archetypes per engine (native knob values), stashed on
    // the root as JSON by the Blade. Each chunk applies them on a chip click and
    // matches its sliders against them to light the active chip (see below).
    let deliveryPresets = {};
    try { deliveryPresets = JSON.parse(root.dataset.deliveryPresets || '{}'); } catch { /* ignore */ }

    const finalUrl = root.dataset.finalUrl;
    const rebuildUrl = root.dataset.rebuildUrl;
    const finalAudio = document.getElementById('project-final-audio');
    const finalStatus = document.getElementById('project-final-status');
    const estimateEl = document.getElementById('project-generate-estimate');
    const dirtyHint = document.getElementById('project-dirty-hint');
    const stickyHeader = document.getElementById('project-sticky-header');
    const estimateUrl = root.dataset.estimateUrl;
    const projectStatus = document.getElementById('project-status');
    const downloadLink = document.getElementById('project-download');
    const generateAllBtn = document.getElementById('project-generate-all');
    const rebuildBtn = document.getElementById('project-rebuild');
    const previewUrl = root.dataset.previewUrl;
    const insertUrl = root.dataset.insertUrl;
    const finalPlayer = document.getElementById('project-final-player');
    let hasFinal = root.dataset.hasFinal === '1';

    // Cache-bust so a regenerated chunk / rebuilt final reloads in the player.
    const bust = (url) => url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();

    // ---- Foreign-project guard ----------------------------------------------
    // The access policy lets a SuperAdmin open any user's project for support,
    // which puts an accidental edit of someone else's work one mis-click away.
    // Until the viewer opts in (once per tab per project), text fields are
    // read-only and the first mutating interaction is intercepted by a warning
    // dialog. Listening, downloads, copying text, and Duplicate (which copies
    // rather than touches the original) stay free. The dialog only renders for
    // a non-owner, so its presence is the whole "is this foreign?" signal.
    const foreignGuard = document.getElementById('foreign-guard');
    if (foreignGuard) {
        const ackKey = 'studio-foreign-ack:' + location.pathname;
        let acked = false;
        try { acked = sessionStorage.getItem(ackKey) === '1'; } catch { /* storage unavailable */ }

        const lockText = (locked) => root
            .querySelectorAll('textarea, input[type="text"], input[type="number"]')
            .forEach((el) => { el.readOnly = locked; });
        const showGuard = (show) => {
            foreignGuard.classList.toggle('hidden', !show);
            foreignGuard.classList.toggle('flex', show);
            if (show) document.getElementById('foreign-guard-cancel')?.focus();
        };

        // Interactions that never change the owner's project.
        const isSafe = (t) =>
            t.closest('#foreign-guard') ||          // the dialog's own buttons
            t.closest('.aplayer') ||                // playback transports (final, chunk, takes)
            t.closest('a[href]') ||                 // navigation + downloads (GETs)
            t.closest('summary') ||                 // "Takes & tuning" disclosure
            t.closest('#project-overflow') ||       // menu toggle (its items are gated)
            t.closest('#project-seal-copy') ||      // clipboard only
            t.closest('#project-duplicate-form') || // copies; the original is untouched
            t.closest('.seam-preview') ||           // renders a temporary preview only
            t.closest('#dock-handle') ||            // mobile dock: expands the production sheet (read-only)
            t.closest('#sheet-handle') ||           // mobile sheet: collapses it again
            t.closest('.qa-badge');                 // opens the QA popover (read-only; its .qa-act mutations stay gated)

        // Would this key press type, submit, or (on a select) change the value —
        // rather than navigate, copy, or select text?
        const editsKey = (e, t) =>
            (e.key.length === 1 && !e.metaKey && !e.ctrlKey) ||
            ['Backspace', 'Delete', 'Enter'].includes(e.key) ||
            ((e.metaKey || e.ctrlKey) && ['v', 'x'].includes(e.key.toLowerCase())) ||
            (t.closest('select') && e.key.startsWith('Arrow'));

        const intercept = (e) => {
            if (acked) return;
            const t = e.target instanceof Element ? e.target : null;
            if (!t || isSafe(t) || !t.closest('button, select, textarea, input')) return;
            if (e.type === 'keydown' && !editsKey(e, t)) return;
            // mousedown is gated only for selects (stops the dropdown opening);
            // leaving it free elsewhere keeps select-to-copy working in textareas.
            if (e.type === 'mousedown' && !t.closest('select')) return;
            e.preventDefault();
            e.stopPropagation();
            showGuard(true);
        };
        ['mousedown', 'click', 'keydown', 'change'].forEach((ev) => root.addEventListener(ev, intercept, true));

        document.getElementById('foreign-guard-continue')?.addEventListener('click', () => {
            acked = true;
            try { sessionStorage.setItem(ackKey, '1'); } catch { /* storage unavailable */ }
            lockText(false);
            showGuard(false);
        });
        document.getElementById('foreign-guard-cancel')?.addEventListener('click', () => showGuard(false));
        // The duplicate redirect lands on the copy, which the SuperAdmin owns.
        // form.submit() fires no click event, so the guard doesn't re-intercept.
        document.getElementById('foreign-guard-duplicate')?.addEventListener('click', () =>
            document.getElementById('project-duplicate-form')?.submit());
        foreignGuard.addEventListener('mousedown', (e) => { if (e.target === foreignGuard) showGuard(false); });
        foreignGuard.addEventListener('keydown', (e) => { if (e.key === 'Escape') showGuard(false); });

        if (!acked) lockText(true);
    }

    const badge = (el, status, prefix = '') => {
        el.textContent = status;
        el.className = prefix + 'inline-flex rounded-md border px-2 py-0.5 text-xs ' + (STATUS_STYLES[status] || STATUS_STYLES.pending);
    };
    // Rebuild the ASR verdict badge + its hover popover from the server's badge
    // payload (or clear it when null). Structure lives in renderQaBadge (mirrors
    // the Blade partial); the popover's open/close and its action buttons are
    // wired by initQaPopovers and the per-card delegation below.
    const setChunkAsrBadge = (card, info) => renderQaBadge(card.querySelector('.chunk-asr-badge'), info);
    const setChunkStatus = (card, status, queueLabel = null) => {
        const el = card.querySelector('.chunk-status');
        // A queued chunk's pill carries its place in line ("queued · next in
        // line", or "rendering" for the one the worker is on) instead of the
        // bare status word.
        el.textContent = queueLabel || status;
        el.className = 'chunk-status inline-flex rounded-md border px-2 py-0.5 text-xs ' + (STATUS_STYLES[status] || STATUS_STYLES.pending);
        // 'queued' is virtual — the chunk is waiting its turn in the active
        // run. The flag flips the render button to "Queued" (setGenerateLabel)
        // and clears itself when the poll delivers the chunk's real status.
        card.dataset.queued = status === 'queued' ? '1' : '0';
        setGenerateLabel(card);
        // The verdict is for the current audio; clear it once the chunk is no
        // longer completed (edited / retuned / failed). It returns on regenerate.
        if (status !== 'completed') setChunkAsrBadge(card, null);
    };
    // ---- Seal as final ------------------------------------------------------
    const sealUrl = root.dataset.sealUrl;
    const unsealUrl = root.dataset.unsealUrl;
    const verifyBase = root.dataset.verifyBase;
    const sealBtn = document.getElementById('project-seal');
    const unsealBtn = document.getElementById('project-unseal');
    const receiptLink = document.getElementById('project-receipt');
    const sealBadge = document.getElementById('project-seal-badge');
    const sealApproverEl = document.getElementById('project-seal-approver');
    const sealWhenEl = document.getElementById('project-seal-when');
    const sealHashEl = document.getElementById('project-seal-hash');
    const sealCopyBtn = document.getElementById('project-seal-copy');
    let isSealed = sealBadge ? !sealBadge.classList.contains('hidden') : false;
    // route('verify') is already an absolute URL; the byte hash opens the record
    // view server-side (?sha=…), where the holder uploads the file to confirm it.
    const buildVerifyUrl = (hash) => hash ? verifyBase + '?sha=' + hash : '';
    let verifyUrl = isSealed ? buildVerifyUrl(sealBadge?.dataset.sha256) : '';

    // Toggle hidden + the element's display class together so neither lingers and
    // overrides the other (see the dirty-badge note above).
    const showEl = (el, show, displayClass) => {
        if (!el) return;
        el.classList.toggle('hidden', !show);
        if (displayClass) el.classList.toggle(displayClass, show);
    };
    // Seal is offered only on a clean (ready) final; the badge shows once sealed.
    // Any edit clears the seal server-side; this mirrors it in the UI. The seal
    // *button* and its receipt replacement live in the action cluster and are owned
    // by reflectActionState, so this only manages the badge.
    const reflectSeal = () => {
        const ready = projectStatus.textContent.trim() === 'ready';
        if (!ready) isSealed = false; // a stale/draft project is never sealed
        showEl(sealBadge, isSealed, 'flex');
        // "Unapprove" (overflow menu) is offered only while approved.
        showEl(unsealBtn, isSealed, 'block');
    };

    // 4B: the action cluster is state-aware — the lit primary names the single next
    // step, and actions needing a current final stay visible-but-disabled until one
    // exists. States: chunks outstanding → Generate remaining; all generated but
    // final missing/stale → Build final; ready → Download draft / Approve;
    // approved → Download approved version.
    const ACT_BASE = 'inline-flex items-center gap-1.5 rounded-[9px] px-4 py-[9px] text-sm transition';
    const ACT_LOOK = {
        primary: 'bg-accent font-semibold text-accent-on hover:bg-accent/90',
        outline: 'border border-accent/35 text-accent hover:bg-accent/[0.08]',
        seal: 'border border-ok/35 bg-ok/[0.06] text-ok hover:bg-ok/[0.12]',
        off: 'border border-white/8 text-zinc-600 cursor-not-allowed pointer-events-none',
    };
    // Rewriting className would strip startBusy()'s dimming mid-request (each
    // chunk in a generate-all run reflects state), so re-apply it for a busy
    // button — otherwise "Generating…" un-dims and accepts a second click.
    const look = (el, variant) => {
        if (! el) return;
        el.className = ACT_BASE + ' ' + ACT_LOOK[variant];
        if (el.dataset.busy) el.classList.add('pointer-events-none', 'opacity-50');
    };
    // look() rewrites className, so the pulse is re-applied after it each pass.
    // A busy button never pulses — it's already doing the thing the pulse asks for.
    const setPulse = (el, on) => el?.classList.toggle('act-pulse', Boolean(on) && ! el.dataset.busy);

    // A background "Generate remaining" run is in flight (this page is only
    // following it — the queue worker does the generating). While true, Build
    // final is locked and per-chunk Regenerate queues into the run instead: the
    // direct endpoint 409s, since it would race the worker over the same chunks.
    let runActive = false;
    const stopBtn = document.getElementById('project-generate-stop');

    // The pre-run time estimate lives in its own element and is refreshed
    // whenever the outstanding set changes — reflectActionState() fires on every
    // such change (it's what lights Generate remaining), so hooking it there
    // keeps the estimate in step with the button. Debounced so a burst of status
    // updates collapses to one request; skipped while a run is active (the live
    // ETA owns the status line then) and the estimate hides. A null estimate
    // (nothing outstanding) hides it too.
    let estimateTimer;
    function refreshEstimate() {
        if (!estimateEl || !estimateUrl) return;
        clearTimeout(estimateTimer);
        estimateTimer = setTimeout(async () => {
            if (runActive) { showEl(estimateEl, false); return; }
            try {
                const res = await fetch(estimateUrl, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const { estimate } = await res.json();
                estimateEl.textContent = estimate || '';
                showEl(estimateEl, !!estimate);
            } catch (_) { /* the estimate is a nicety — never surface its failure */ }
        }, 300);
    }

    // Jump to a chunk card from a header hint. scrollIntoView would tuck the
    // card's top under the sticky header, so offset by the header's live height
    // (it wraps on narrow screens and grows when the rename form opens). The
    // inserted-chunk ring marks the landing spot; the class is removed and
    // re-added (with a reflow between) so a second click flashes again.
    function scrollToChunk(card) {
        // Below sm the header is ordinary content (the transport docks at the
        // bottom instead — see initStudioMobileDock), so nothing overlaps the
        // card's top; only a header that's actually pinned needs offsetting.
        const pinned = stickyHeader && getComputedStyle(stickyHeader).position === 'sticky';
        const offset = (pinned ? stickyHeader.offsetHeight : 0) + 12;
        window.scrollTo({
            top: card.getBoundingClientRect().top + window.scrollY - offset,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        card.classList.remove('chunk-inserted-flash');
        void card.offsetWidth;
        card.classList.add('chunk-inserted-flash');
    }

    function reflectActionState() {
        const status = projectStatus.textContent.trim();
        // Skipped chunks don't count as outstanding work: they're excluded from
        // the stitch, so an ungenerated-but-skipped chunk must not hold Build
        // final hostage. All-skipped leaves Build final off (the server 422s it).
        const cards = [...root.querySelectorAll('.studio-chunk')].filter((c) => !isChunkSkipped(c));
        const anyCompleted = cards.some(isChunkCompleted);
        const anyPending = cards.some((c) => !isChunkCompleted(c));
        const allCompleted = cards.length > 0 && ! anyPending;
        const ready = hasFinal && status === 'ready';
        // Pending (unsaved) edits: the stitch would speak audio that no longer
        // matches the screen, so they hold Build final below until each chunk
        // is regenerated or reverted. Skipped chunks are already filtered out
        // of `cards` — an edit on an excluded chunk can't lie in the final.
        const dirtyCards = cards.filter((c) => c.dataset.dirty === '1');

        // Generate-all shows while chunks remain outstanding — and since the
        // final-audio actions below all HIDE in exactly those states, it's the
        // single next step, so it always leads. It pulses when existing work
        // (generated audio or a built final) has fallen out of sync with the
        // text; a brand-new project gets the lit primary without the nudge.
        // look() rewrites className with ACT_BASE (which carries `inline-flex`),
        // so hiding must clear inline-flex too — a lone `hidden` loses to it.
        // showEl toggles both, so re-adding a chunk (→ anyPending) brings the
        // button back.
        if (generateAllBtn) {
            look(generateAllBtn, 'primary');
            setPulse(generateAllBtn, anyPending && (anyCompleted || hasFinal));
            showEl(generateAllBtn, anyPending, 'inline-flex');
        }
        // Stop lives next to Generate remaining, only while a run is in flight.
        showEl(stopBtn, runActive, 'inline-flex');

        // Space-saving: a button with no purpose in the current state is HIDDEN,
        // not greyed. While any chunk is outstanding (Generate remaining leads) or
        // a background run is going, none of these final-audio actions can do
        // anything — building would stitch stale/absent audio, and the server 409s
        // a mid-run rebuild — so they disappear until the work is done.
        const canBuild = ! runActive && allCompleted && dirtyCards.length === 0;   // every chunk current AND saved, no run
        const finalReady = ready && ! anyPending;        // a built, in-sync final exists

        // Build final vanishing needs a why: name the chunks holding it. Only
        // when dirty edits are the SOLE blocker — while chunks are outstanding
        // or a run is active the button is absent for those louder reasons.
        if (dirtyHint) {
            const blocked = ! runActive && allCompleted && dirtyCards.length > 0;
            if (blocked) {
                // Each number is a jump link to its card — with several dirty
                // chunks the reader shouldn't have to hunt for them by eye.
                const links = dirtyCards.map((c) => {
                    const no = c.querySelector('.chunk-no')?.textContent;
                    if (!no) return null;
                    const link = document.createElement('button');
                    link.type = 'button';
                    link.className = 'underline underline-offset-2 hover:text-amber-300';
                    link.textContent = no;
                    link.setAttribute('aria-label', `Go to chunk ${no}`);
                    link.addEventListener('click', () => scrollToChunk(c));
                    return link;
                }).filter(Boolean);
                dirtyHint.replaceChildren(
                    links.length === 1 ? 'Chunk ' : 'Chunks ',
                    ...links.flatMap((link, i) => (i ? [', ', link] : [link])),
                    (links.length === 1 ? ' has' : ' have')
                        + ' unsaved edits — Regenerate or Revert before building the final.',
                );
            }
            showEl(dirtyHint, blocked);
        }

        // Build final: lit primary (pulsing) while a final is due; a quiet outline
        // once one is ready, as a rebuild after edits. Hidden while chunks are
        // outstanding or a run is active.
        look(rebuildBtn, ready ? 'outline' : 'primary');
        setPulse(rebuildBtn, canBuild && ! ready);
        showEl(rebuildBtn, canBuild, 'inline-flex');

        // Draft download (bare final audio): only meaningful once a current final
        // exists and before approval — then the approved-version package below
        // supersedes it. Hidden otherwise (no file, or a stale one).
        look(downloadLink, 'primary');
        showEl(downloadLink, finalReady && ! isSealed, 'inline-flex');

        // Approve as final: same gate as the draft download; once approved it's
        // replaced in place by the approved-version (receipt) download.
        look(sealBtn, 'seal');
        showEl(sealBtn, finalReady && ! isSealed, 'inline-flex');
        if (receiptLink) {
            look(receiptLink, 'primary');
            showEl(receiptLink, isSealed, 'inline-flex');
        }

        refreshEstimate(); // keep the pre-run time hint in step with the outstanding set
    }

    const setProjectStatus = (status) => { badge(projectStatus, status); reflectSeal(); reflectActionState(); };

    async function seal() {
        if (!sealBtn) return;
        startBusy(sealBtn, 'Approving…');
        try {
            const res = await fetch(sealUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            isSealed = true;
            verifyUrl = data.verify_url || buildVerifyUrl(data.sha256);
            if (sealBadge) sealBadge.dataset.sha256 = data.sha256;
            if (sealApproverEl) sealApproverEl.textContent = data.approver ? ' — approved by ' + data.approver : '';
            if (sealWhenEl) sealWhenEl.textContent = data.sealed_at_human ? ' · ' + data.sealed_at_human : '';
            if (sealHashEl) sealHashEl.textContent = data.short || '';
            reflectSeal();
            reflectActionState(); // swap Approve for the approved-version download
            // The green "Approved final" badge is the confirmation — don't echo it
            // in the status line. Clear any prior message (e.g. a build result).
            setStatus(finalStatus, '', 'ok');
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(sealBtn);
        }
    }

    sealBtn?.addEventListener('click', seal);

    // Undo an approval made by mistake. Clears the seal server-side; the audio is
    // untouched, so the project stays Ready and the cluster swaps back to Approve.
    async function unseal() {
        if (!unsealUrl) return;
        // Close the ⋯ menu the item lives in (declared later in this scope; the
        // click only fires after init, so it's initialized by then).
        overflowMenu?.classList.add('hidden');
        try {
            const res = await fetch(unsealUrl, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            isSealed = false;
            const data = await res.json().catch(() => ({}));
            // Re-lights the cluster (draft download + Approve return) and hides the
            // badge + Unapprove. Unlike approving, there's no persistent badge left
            // to confirm it, so a brief status line is warranted here.
            setProjectStatus(data.project_status || 'ready');
            setStatus(finalStatus, '✓ Approval removed — you can edit or re-approve.', 'ok');
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        }
    }

    unsealBtn?.addEventListener('click', unseal);

    sealCopyBtn?.addEventListener('click', async () => {
        if (!verifyUrl) return;
        try {
            await navigator.clipboard.writeText(verifyUrl);
            setStatus(finalStatus, '✓ Verify link copied.', 'ok');
        } catch (_) {
            setStatus(finalStatus, verifyUrl, 'ok');
        }
    });

    // ---- Downloads with feedback --------------------------------------------
    // Both download links build their file INSIDE the request — the receipt zip
    // fetches the sealed audio, re-reads and fingerprints every chunk's audio,
    // renders receipt.html, and zips it before the first byte leaves the server.
    // A bare <a download> shows nothing during that, so it reads as broken.
    // Fetching instead lets the button narrate the stages (with an elapsed
    // heartbeat), show transfer progress, and surface a JSON error (422/502) in
    // the status line rather than downloading it as a .json file. The href stays
    // on the link as a no-JS fallback.
    async function fetchDownload(link, stages) {
        if (link.dataset.busy) return;
        const t0 = performance.now();
        startBusy(link, 'Preparing…');
        // Walk the stage messages on a rough schedule and hold on the last one;
        // the ticking seconds are the "still working" signal either way.
        setStatus(finalStatus, stages[0]);
        const ticker = setInterval(() => {
            const secs = Math.round((performance.now() - t0) / 1000);
            const stage = Math.min(Math.floor(secs / 3), stages.length - 1);
            setStatus(finalStatus, `${stages[stage]} (${secs}s)`);
        }, 1000);

        try {
            const res = await fetch(link.href);
            clearInterval(ticker);
            if (!res.ok) throw new Error(await errorMessage(res));

            // Headers arrive only once the file is fully built, so from here on
            // it's pure transfer — report it as such.
            const total = Number(res.headers.get('Content-Length')) || 0;
            const reader = res.body.getReader();
            const parts = [];
            let received = 0;
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                parts.push(value);
                received += value.length;
                const mb = (received / 1048576).toFixed(1);
                setStatus(finalStatus, total
                    ? `Downloading… ${Math.min(100, Math.round((received / total) * 100))}% (${mb} MB)`
                    : `Downloading… ${mb} MB`);
            }

            const cd = res.headers.get('Content-Disposition') || '';
            const name = (cd.match(/filename="?([^";]+)"?/) || [])[1] || 'download';
            const blob = new Blob(parts, { type: res.headers.get('Content-Type') || 'application/octet-stream' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = name;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 30000);

            const doneMsg = `✓ ${name} is in your downloads.`;
            setStatus(finalStatus, doneMsg, 'ok');
            // Quietly retire the confirmation unless something newer replaced it.
            setTimeout(() => {
                if (finalStatus.textContent === doneMsg) setStatus(finalStatus, '');
            }, 8000);
        } catch (err) {
            clearInterval(ticker);
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(link);
        }
    }

    const managedDownload = (link, stages) => link?.addEventListener('click', (e) => {
        e.preventDefault();
        fetchDownload(link, stages);
    });
    managedDownload(downloadLink, [
        'Preparing your download…',
        'Fetching the final audio from storage…',
    ]);
    // Stages mirror buildReceiptZip: sealed audio → per-chunk fingerprints →
    // receipt render + zip. Fingerprinting dominates (one storage read per chunk).
    managedDownload(receiptLink, [
        'Preparing your download…',
        'Gathering the approved audio…',
        'Fingerprinting each chunk for the receipt…',
        'Building the provenance receipt and zipping it up…',
    ]);
    // The archive (⋯ menu) reads and re-packages EVERY take in the project, so
    // it runs the longest of the three. Close the menu first so the status line
    // underneath is visible; an unapproved project surfaces the server's 422
    // message there via fetchDownload's error path.
    const archiveLink = document.getElementById('project-archive');
    archiveLink?.addEventListener('click', () =>
        document.getElementById('project-overflow-menu')?.classList.add('hidden'));
    managedDownload(archiveLink, [
        'Preparing your download…',
        'Gathering the approved audio…',
        'Collecting every clip in the project…',
        'Building the receipt and zipping it all up…',
    ]);

    // A chunk is "dirty" while its textarea differs from the last-saved text
    // (data-original). Show an amber badge + border and reveal Revert; warn before
    // leaving the page with any dirty chunk. Set when an intended reload (insert /
    // re-chunk) navigates away on purpose, so the guard doesn't fire for those.
    let skipUnloadGuard = false;
    // Every panel control whose pending edit marks the chunk "unsaved": the text,
    // the voice, the seed pin, and every tuning knob. Each control's last-SAVED
    // value lives in its own dataset.original; a chunk is dirty when any live value
    // has drifted from it. Hidden (other-engine) knobs sit at their own baseline
    // untouched, so they never add false dirt. Voice/tuning are held pending and
    // written only on the next Regenerate — same contract as a text edit.
    const panelControls = (card) => {
        const controls = [
            card.querySelector('.chunk-text'),
            card.querySelector('.chunk-voice'),
            card.querySelector('.chunk-seed'),
        ];
        KNOB_INPUTS.forEach(([, sel]) => controls.push(card.querySelector(sel)));
        return controls.filter(Boolean);
    };

    const isDirty = (card) => panelControls(card).some((el) => el.value !== el.dataset.original);

    // Snapshot the panel's current values as the new saved baseline (after a
    // generate/queue/patch/select persists them). The voice's inherit flag is
    // captured too so Revert can put it back.
    const commitBaseline = (card) => {
        panelControls(card).forEach((el) => { el.dataset.original = el.value; });
        const voice = card.querySelector('.chunk-voice');
        if (voice) voice.dataset.inheritsOriginal = voice.dataset.inherits ?? '1';
    };

    // The render button's label: the bare verb from data-base, which flips from
    // Generate to Regenerate once the chunk has rendered audio. A dirty text
    // edit doesn't change it — the click saves the edit as part of the render.
    // Skipped mid-render (startBusy owns the label until endBusy restores it).
    // While the chunk waits its turn in an active run the button says so
    // instead (clicking then just saves any newer edits into that render).
    const setGenerateLabel = (card) => {
        const btn = card.querySelector('.chunk-generate');
        if (!btn || btn.dataset.busy) return;
        btn.textContent = card.dataset.queued === '1' ? '⏳ Queued' : `▶ ${btn.dataset.base || 'Generate'}`;
    };

    const setDirty = (card, dirty) => {
        const textarea = card.querySelector('.chunk-text');
        const dirtyBadge = card.querySelector('.chunk-dirty');
        // Toggle hidden AND inline-flex together — leaving both on an element lets
        // inline-flex win over hidden in the compiled CSS, so the badge would always show.
        dirtyBadge.classList.toggle('hidden', !dirty);
        dirtyBadge.classList.toggle('inline-flex', dirty);
        card.querySelector('.chunk-revert').classList.toggle('hidden', !dirty);
        // The amber outline belongs to the TEXTAREA, so tie it to a text change
        // specifically — a pending voice/tuning edit lights the badge + Revert
        // but must not outline text the user didn't touch.
        const textDirty = dirty && textarea.value !== textarea.dataset.original;
        textarea.classList.toggle('border-amber-500/50', textDirty);
        textarea.classList.toggle('border-edge', !textDirty);
        // There is no save-text-without-render — the render button absorbs the
        // save (patchChunk runs first in queueChunkRegen).
        setGenerateLabel(card);
        // A dirty chunk's one resolving action is its render button (Build
        // final holds meanwhile) — flip it from the quiet zinc outline to the
        // lit accent look with the same gentle pulse the header primaries use.
        // Each pair is toggled both ways so the utilities are never co-present
        // (co-present classes fight and stylesheet order picks the winner).
        const gen = card.querySelector('.chunk-generate');
        if (gen) {
            gen.classList.toggle('border-zinc-700', !dirty);
            gen.classList.toggle('hover:bg-zinc-800', !dirty);
            gen.classList.toggle('border-accent/60', dirty);
            gen.classList.toggle('text-accent', dirty);
            gen.classList.toggle('hover:bg-accent/10', dirty);
            gen.classList.toggle('act-pulse', dirty && ! gen.dataset.busy);
        }
        // Build final reacts to pending edits project-wide (guard + hint live
        // in reflectActionState) — keep it in step with every dirty flip.
        card.dataset.dirty = dirty ? '1' : '0';
        reflectActionState();
    };

    // After a successful save (generate/queue), lock the panel in as the new saved
    // state: a voice the user changed is now an explicit server-side pin (it stops
    // mirroring the project voice), then snapshot the baseline and clear the badge.
    const commitPanelAfterSave = (card) => {
        const voice = card.querySelector('.chunk-voice');
        if (voice && voice.value !== voice.dataset.original) voice.dataset.inherits = '0';
        commitBaseline(card);
        setDirty(card, false);
    };

    async function patchChunk(card) {
        const textarea = card.querySelector('.chunk-text');
        const res = await fetch(card.dataset.patchUrl, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: textarea.value }),
        });
        if (!res.ok) throw new Error(await errorMessage(res));
        const data = await res.json();
        // An over-long edit was split into new chunks — the list changed
        // structurally, so reload to re-render it. Returns true so callers stop.
        if (data.rechunked) {
            skipUnloadGuard = true; // the edit is saved server-side; reload is intentional
            window.location.reload();
            return true;
        }
        textarea.dataset.original = textarea.value;
        // Only the text was saved here (this is generate's first step); a pending
        // voice/tuning edit stays dirty until the render that follows persists it.
        setDirty(card, isDirty(card));
        card.querySelector('.chunk-chars').textContent = `${data.characters} chars`;
        setChunkStatus(card, data.status);
        setProjectStatus(data.project_status);
        refreshSeams(); // a now-stale chunk hides its adjacent seam previews
        return false;
    }

    // ---- Background "Generate remaining" ------------------------------------
    // The run executes on the queue worker, so it survives leaving the page
    // (the old in-page loop died with the tab). This page only dispatches it,
    // then follows along on the generation-status poll; a chunk card re-renders
    // only when its selected take actually changed, so polling never rebuilds a
    // take list the user is auditioning.
    const generateRemainingUrl = root.dataset.generateRemainingUrl;
    const generationStatusUrl = root.dataset.generationStatusUrl;
    const RUN_POLL_MS = 3000;
    let runTimer = null;
    let runCancelUrl = null;
    // chunkId -> the selected take last rendered, seeded from the blade payload
    // so resuming a run (page load mid-run) doesn't rebuild untouched cards.
    const renderedTakes = new Map();
    root.querySelectorAll('.studio-chunk').forEach((card) => {
        try {
            renderedTakes.set(card.dataset.chunkId, JSON.parse(card.dataset.takes || '{}').selected_take_id ?? null);
        } catch { /* no takes payload */ }
    });

    const setRunLock = (locked) => {
        runActive = locked;
        root.querySelectorAll('.studio-chunk').forEach((card) => {
            // Generate stays usable during a run — it queues the chunk into the
            // run instead of generating directly (see queueChunkRegen).
            const gen = card.querySelector('.chunk-generate');
            if (gen) {
                gen.title = locked
                    ? 'Saves the text and Delivery settings shown, then puts this clip next in line in the background run.'
                    : "Render this chunk with the text and Delivery settings shown — they're saved as part of the click.";
            }
        });
    };

    // "Regenerate" one chunk — the only render path, and it never renders
    // inside the request: with no run active the server starts a single-chunk
    // background run, and while one is active the chunk joins its line — right
    // after the clip in flight, so it renders next. (The old direct endpoint
    // rendered synchronously and died on the gateway's timeout — HTTP 504 —
    // whenever a render plus its ASR re-rolls ran long.) A pending text edit
    // and the tuning panel are persisted with the click, so the worker renders
    // what's on screen. The server marks a generated chunk stale as it adopts
    // it and reports its place in line ('queued' + queue_label); followRun's
    // poll then delivers the take and keeps the line fresh as it moves.
    async function queueChunkRegen(card) {
        ensureTune(card); // the payload below reads the tuning panel
        const btn = card.querySelector('.chunk-generate');
        startBusy(btn, 'Queueing…');
        try {
            const textarea = card.querySelector('.chunk-text');
            if (textarea.value !== textarea.dataset.original) {
                // A re-chunking edit reloads the page — don't queue the orphan.
                if (await patchChunk(card)) return;
            }
            const res = await fetch(card.dataset.queueUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(chunkTuningPayload(card)),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            setChunkStatus(card, data.status, data.queue_label ?? null);
            // The queue endpoint persisted the pending voice + tuning (the worker
            // renders from them later) — lock them in as saved, same as a direct
            // Regenerate does.
            commitPanelAfterSave(card);
            // Nothing on the card changes until the worker gets to this clip, so
            // the message ("Saved — clip N will regenerate next…") carries real
            // information — show it here; the run's own progress stays in the
            // header status line.
            chunkNotice(card, data.message || data.job?.message);
            // A fresh single-chunk run needs following — the poll is what lands
            // the take on the card. No-op while a run is already being followed
            // (followRun guards on its own timer); a run that already finished
            // inline (sync queue) settles on the first poll.
            if (data.job) followRun();
        } catch (err) {
            chunkNotice(card, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(btn);
            // endBusy restores a possibly stale label and busy suppressed the
            // dirty styling — re-derive both from the panel's real state.
            setDirty(card, isDirty(card));
        }
    }

    // One-shot accent glow (chunk-rendered-glow in app.css) on a card whose
    // fresh render just landed. Remove-reflow-add restarts the animation when
    // the same chunk re-renders while a previous glow is still playing.
    const flashRenderedChunk = (card) => {
        card.classList.remove('chunk-rendered-glow');
        void card.offsetWidth;
        card.classList.add('chunk-rendered-glow');
    };

    // One chunk entry from the poll. Light entries ({id, status}) are chunks the
    // run hasn't finished with; full ones carry the same payload generateChunk()
    // returns, and flow through the same render path. Returns whether the
    // chunk's audio changed (the caller refreshes seams once per batch).
    const applyRunChunk = (data) => {
        const card = root.querySelector(`.studio-chunk[data-chunk-id="${data.id}"]`);
        if (!card) return false;
        setChunkStatus(card, data.status, data.queue_label ?? null);
        if (data.asr_badge !== undefined) setChunkAsrBadge(card, data.asr_badge ?? null);
        if (data.selected_take_id === undefined || renderedTakes.get(data.id) === data.selected_take_id) {
            return false;
        }
        renderedTakes.set(data.id, data.selected_take_id);
        if (data.selected_take_id !== null) {
            // No autoplay: chunks land while the user is elsewhere on the page
            // (or was — a resumed run may deliver several at once).
            setChunkAudioSrc(card, bust(card.dataset.audioUrl));
            flashRenderedChunk(card);
        }
        card.querySelector('.chunk-generate').dataset.base = 'Regenerate';
        setGenerateLabel(card);
        renderTakes(card, data);
        return true;
    };

    // Returns true when the run is over (or there is none) — the poll stops.
    const applyRunState = (data) => {
        if (!data.job) return true;
        runCancelUrl = data.job.cancel_url;
        const changed = (data.chunks || []).filter(applyRunChunk).length > 0;
        if (changed) refreshSeams();
        setProjectStatus(data.project_status);
        setStatus(finalStatus, data.job.message, data.job.tone || undefined);
        return !data.job.active;
    };

    async function pollRun() {
        try {
            const res = await fetch(generationStatusUrl, { headers: { 'Accept': 'application/json' } });
            if (res.ok && applyRunState(await res.json())) {
                endRun();
                return;
            }
        } catch { /* transient — keep polling */ }
        runTimer = setTimeout(pollRun, RUN_POLL_MS);
    }

    function followRun(initialMessage) {
        if (runTimer) return;
        setRunLock(true);
        startBusy(generateAllBtn, 'Generating…');
        reflectActionState(); // Stop appears; Build final goes off
        if (initialMessage) setStatus(finalStatus, initialMessage);
        runTimer = setTimeout(pollRun, 800); // first read right away-ish
    }

    function endRun() {
        clearTimeout(runTimer);
        runTimer = null;
        runCancelUrl = null;
        setRunLock(false);
        // The final poll normally delivers every run chunk's real status, which
        // clears its queued flag — but a run whose row vanished (job: null)
        // settles without that payload. Sweep any leftovers so the render
        // buttons re-derive from data-base instead of reading "Queued" forever.
        root.querySelectorAll('.studio-chunk[data-queued="1"]').forEach((card) => {
            card.dataset.queued = '0';
            setGenerateLabel(card);
        });
        endBusy(generateAllBtn);
        reflectActionState(); // the pulse is suppressed while busy — re-apply it (e.g. failures left chunks outstanding)
    }

    async function generateAll() {
        startBusy(generateAllBtn, 'Starting…');
        try {
            const res = await fetch(generateRemainingUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            endBusy(generateAllBtn);
            // Clicking while a run is active just joins it; and under
            // QUEUE_CONNECTION=sync the run already finished inline — the first
            // poll sees the terminal payload and settles the page.
            followRun(data.job?.message);
        } catch (err) {
            endBusy(generateAllBtn);
            reflectActionState();
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        }
    }

    stopBtn?.addEventListener('click', async () => {
        if (!runCancelUrl) return;
        startBusy(stopBtn, 'Stopping…');
        try {
            const res = await fetch(runCancelUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            // A queued run cancels instantly; a running worker finishes the clip
            // it's on, then winds down — the poll settles the page either way.
            if (data.job) setStatus(finalStatus, data.job.message, data.job.tone || undefined);
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(stopBtn);
        }
    });

    // ---- Async "Build final" -------------------------------------------------
    // The stitch runs on the queue worker (the old in-request stitch died on
    // the gateway timeout — HTTP 504 — once a project grew past what ~60s of
    // download+concat+encode can cover). Build final books a stitch run, then
    // follows it on the same generation-status poll the generate runs use.
    let stitchTimer = null;

    // Settle the page from a terminal stitch payload. autoplay only when the
    // user just clicked Build final — a resumed page (or a stitch that finished
    // while they were elsewhere) must not start playback on its own.
    function applyStitchResult(job, autoplay) {
        if (!job) return;
        setStatus(finalStatus, job.message, job.tone || undefined);
        if (job.status !== 'completed') return;
        finalAudio.src = bust(finalUrl);
        hasFinal = true;
        finalPlayer?.classList.remove('hidden');
        document.getElementById('project-final-placeholder')?.remove();
        if (autoplay) finalAudio.play().catch(() => {});
        isSealed = false; // new bytes — the server cleared the seal; offer to re-seal
        setProjectStatus('ready'); // also re-lights the action cluster (Download leads)
    }

    // Follow an active stitch run until it settles. The latest run IS the
    // stitch — nothing else can start while it holds the project (the join
    // endpoints 409). No-op while already following.
    function followStitch(autoplay) {
        if (stitchTimer) return;
        startBusy(rebuildBtn, 'Rebuilding…');
        const tick = async () => {
            try {
                const res = await fetch(generationStatusUrl, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    if (!data.job || !data.job.active) {
                        stitchTimer = null;
                        endBusy(rebuildBtn);
                        applyStitchResult(data.job, autoplay);
                        reflectActionState();
                        return;
                    }
                    setStatus(finalStatus, data.job.message, data.job.tone || undefined);
                }
            } catch { /* transient — keep polling */ }
            stitchTimer = setTimeout(tick, RUN_POLL_MS);
        };
        stitchTimer = setTimeout(tick, 800); // first read right away-ish
    }

    // A "Duplicate project" run copies clips off the request cycle (object
    // storage has no batch copy, so a long project's deep copy outlived the
    // gateway timeout the way the old synchronous duplicate did). Booked on THIS
    // (source) project, the page follows it and navigates to the fresh copy when
    // it lands. No-op while already following.
    let duplicateTimer = null;
    function followDuplicate() {
        if (duplicateTimer) return;
        const tick = async () => {
            try {
                const res = await fetch(generationStatusUrl, { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    if (data.job) setStatus(finalStatus, data.job.message, data.job.tone || undefined);
                    if (data.job && !data.job.active) {
                        duplicateTimer = null;
                        // Completed → open the copy; failed → leave the reason on screen.
                        if (data.job.status === 'completed' && data.job.redirect_url) {
                            window.location.assign(data.job.redirect_url);
                        }
                        return;
                    }
                }
            } catch { /* transient — keep polling */ }
            duplicateTimer = setTimeout(tick, RUN_POLL_MS);
        };
        duplicateTimer = setTimeout(tick, 800); // first read right away-ish
    }

    async function rebuild() {
        startBusy(rebuildBtn, 'Rebuilding…');
        setStatus(finalStatus, 'Stitching chunks…');
        try {
            const res = await fetch(rebuildUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            if (data.job?.active) {
                // Booked — the worker stitches while this page follows along.
                setStatus(finalStatus, data.job.message, data.job.tone || undefined);
                followStitch(true);
            } else {
                // Inline finish (sync queue) — settle without a poll.
                endBusy(rebuildBtn);
                applyStitchResult(data.job, true);
                reflectActionState();
            }
        } catch (err) {
            endBusy(rebuildBtn);
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        }
    }

    // A chunk's status badge is the source of truth for "is it generated?".
    const isChunkCompleted = (card) =>
        card && card.querySelector('.chunk-status')?.textContent.trim() === 'completed';

    // Skipped = left out of the stitched final (reversible). data-skipped also
    // drives the row's dimmed look via CSS.
    const isChunkSkipped = (card) => card?.dataset.skipped === '1';

    // The id of the take currently selected as a chunk's audio — its select button
    // is the only one left disabled (see takeRow). A stitched seam preview is built
    // from a specific pair of these, so it goes stale the moment either changes.
    const selectedTakeId = (card) =>
        card?.querySelector('.chunk-take-select:disabled')?.closest('.chunk-take')?.dataset.takeId || null;
    const seamTakePair = (prev, next) => `${selectedTakeId(prev)}|${selectedTakeId(next)}`;

    // Flip a card's skipped state in place: dataset (CSS dim), the "skipped"
    // pill, and the 🔊/🔇 button. Pill and button classNames are rewritten
    // wholesale (hidden must beat inline-flex — the hidden/display gotcha) and
    // must stay identical to the Blade initial state in show.blade.php.
    function setChunkSkipped(card, skipped) {
        card.dataset.skipped = skipped ? '1' : '0';

        const pill = card.querySelector('.chunk-skip-pill');
        if (pill) {
            pill.className = `chunk-skip-pill ${skipped ? 'inline-flex' : 'hidden'} rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300`;
        }

        const btn = card.querySelector('.chunk-skip');
        if (btn) {
            btn.className = `chunk-skip rounded-lg border px-2.5 py-1.5 text-sm ${skipped
                ? 'border-amber-500/40 bg-amber-500/10 text-amber-300'
                : 'border-zinc-700 text-zinc-500 hover:border-amber-700/60 hover:text-amber-300'}`;
            btn.title = skipped ? 'Include this chunk in the final audio.' : 'Skip this chunk in the final audio.';
            btn.textContent = skipped ? '🔇' : '🔊';
            // endBusy() restores dataset.originalText (captured once by startBusy),
            // so it must track the new icon or the old one reappears after a toggle.
            btn.dataset.originalText = btn.textContent;
        }
    }

    // The inline "Preview stitch" connector stays visible between two chunks, but
    // it can only actually stitch once BOTH sides have audio — so until then it's
    // disabled (grayed) rather than vanishing, which used to confuse anyone who'd
    // inserted an empty chunk between two generated ones (the button just
    // disappeared). A skipped neighbor is the exception: that join won't exist in
    // the final at all, so its seam is hidden outright.
    function refreshSeams() {
        root.querySelectorAll('.chunk-seam').forEach((seam) => {
            const prev = root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.prev}"]`);
            const next = root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.next}"]`);
            const skipped = isChunkSkipped(prev) || isChunkSkipped(next);
            const ready = isChunkCompleted(prev) && isChunkCompleted(next) && !skipped;
            seam.classList.toggle('hidden', skipped);
            const btn = seam.querySelector('.seam-preview');
            if (btn) {
                btn.disabled = !ready;
                btn.title = ready ? '' : 'Generate both chunks to preview how they stitch together.';
            }
            // The caption under the line repeats the disabled reason (design state 1),
            // shown and hidden in step with the button.
            seam.querySelector('.seam-hint')?.classList.toggle('hidden', ready);
            // Drop an open stitched preview once it no longer reflects the two chunks:
            // a neighbor went un-generated or skipped (!ready), OR either neighbor's
            // selected take changed since the stitch — the join was built from the old
            // audio, so it's stale even though both sides are still "completed". The
            // take-id pair stamped at stitch time (dataset.previewTakes) is the test.
            const stale = seam.dataset.previewTakes && seam.dataset.previewTakes !== seamTakePair(prev, next);
            if (!ready || stale) discardSeamPreview(seam);
        });
    }

    // Tear down a seam's stitched preview: hide the player, stop and forget its
    // audio (so the next click re-stitches fresh), clear the status line, and reset
    // the Preview-stitch button. Safe to call on a seam with no open preview.
    function discardSeamPreview(seam) {
        seam.querySelector('.seam-player')?.classList.add('hidden');
        const audio = seam.querySelector('.seam-audio');
        if (audio) { audio.pause(); audio.removeAttribute('src'); }
        const status = seam.querySelector('[role="status"]');
        if (status) status.textContent = '';
        renderSeamPlaying(seam, false);
        delete seam.dataset.previewTakes;
    }

    // Keep the Preview-stitch button in step with its seam player: fully cyan with
    // a pause glyph while the stitched preview sounds, back to a muted play glyph
    // when it stops (design 8A). `.is-active` turns the label cyan (see app.css).
    function renderSeamPlaying(seam, playing) {
        const btn = seam.querySelector('.seam-preview');
        if (!btn) return;
        btn.classList.toggle('is-active', playing);
        const glyph = btn.querySelector('.seam-glyph');
        if (glyph) glyph.textContent = playing ? '❚❚' : '▶';
    }

    // Stitch the two adjacent chunks this connector sits between and play the join
    // in place. Once stitched and on screen, re-clicking just toggles that preview's
    // playback rather than re-stitching; a seam hidden by an edit stitches fresh
    // next time. The stitched clip is transient preview audio, never a saved take.
    // The seam player's native audio — created on first use in lazy mode — with
    // the transport-mirroring listeners attached exactly once either way.
    function ensureSeamAudio(seam) {
        const audio = ensureNative(seam.querySelector('.seam-player .aplayer'));
        if (audio && !audio.dataset.seamWired) {
            audio.dataset.seamWired = '1';
            // Mirror the player's transport onto the Preview-stitch button
            // (play/pause/ended), so the seam control and the inline player
            // never disagree about what's sounding.
            audio.addEventListener('play', () => renderSeamPlaying(seam, true));
            audio.addEventListener('pause', () => renderSeamPlaying(seam, false));
            audio.addEventListener('ended', () => renderSeamPlaying(seam, false));
        }
        return audio;
    }

    async function previewSeam(seam) {
        const btn = seam.querySelector('.seam-preview');
        const label = btn.querySelector('.seam-label');
        const player = seam.querySelector('.seam-player');
        const audio = ensureSeamAudio(seam);
        // setStatus() rewrites className, so find the status by its stable role
        // attribute — its `seam-status` class doesn't survive the first update.
        const status = seam.querySelector('[role="status"]');

        // Already stitched and on screen? Toggle the existing preview's playback.
        if (audio?.src && !player.classList.contains('hidden')) {
            audio.paused ? audio.play().catch(() => {}) : audio.pause();
            return;
        }
        if (btn.dataset.busy) return;

        const t0 = performance.now();
        btn.dataset.busy = '1';
        const restore = label.textContent;
        label.textContent = 'Stitching…';
        player.classList.remove('hidden');
        try {
            const res = await fetch(previewUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*', 'Content-Type': 'application/json' },
                body: JSON.stringify({ chunks: [seam.dataset.prev, seam.dataset.next] }),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            playAudio(audio, await res.blob()); // the 'play' event drives renderSeamPlaying
            // Stamp which pair of takes this join was built from, so refreshSeams()
            // can drop it the moment either neighbor's selected take changes.
            seam.dataset.previewTakes = seamTakePair(
                root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.prev}"]`),
                root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.next}"]`),
            );
            setStatus(status, `✓ Stitched in ${elapsed(t0)}s.`, 'ok');
        } catch (err) {
            player.classList.add('hidden');
            setStatus(status, `✗ ${err.message}`, 'error');
        } finally {
            delete btn.dataset.busy;
            label.textContent = restore;
        }
    }

    root.querySelectorAll('.chunk-seam').forEach((seam) => {
        seam.querySelector('.seam-preview')?.addEventListener('click', () => previewSeam(seam));
        // Eager markup ships the native audio up front — wire its transport
        // mirroring now. Lazy seams get the same wiring inside previewSeam.
        if (seam.querySelector('.seam-audio')) ensureSeamAudio(seam);
    });

    // ---- Take history -------------------------------------------------------
    // Every render is kept as a selectable take. These render the per-chunk list
    // from the JSON the server returns (embedded on load, or from each action's
    // response / the listTakes endpoint).
    function takeRow(card, take) {
        const li = document.createElement('li');
        li.dataset.takeId = take.id;
        // Layout (columns) is owned by the .chunk-take grid in app.css so rows
        // line up across chunks; this className only carries the box styling.
        li.className = 'chunk-take rounded-lg border px-2 py-1.5 '
            + (take.selected ? 'border-emerald-600/50 bg-emerald-500/10' : 'border-zinc-800 bg-zinc-950/40');

        // Custom player (take weight): enhanceStudioPlayers() (called by
        // renderTakes) wires it up. Fills the grid's first (1fr) column.
        // Lazy mode parks the URL on data-audio-src — no <audio> until played.
        const lazy = root.dataset.lazyPlayers === '1';
        const player = buildAPlayer('take', {
            label: 'Play take',
            extraClass: 'min-w-0' + (take.selected ? ' aplayer--selected' : ''),
            ...(lazy ? { lazySrc: take.audio_url } : {}),
        });
        // Recorded length: enhanceStudioPlayers prints it immediately, so the
        // duration is visible without playing (preload stays 'none' — no request).
        if (take.duration_ms) player.dataset.durationMs = take.duration_ms;
        if (!lazy) {
            const audio = player.querySelector('.aplayer__native');
            audio.preload = 'none';
            audio.src = take.audio_url;
        }

        const meta = document.createElement('div');
        meta.className = 'flex min-w-0 flex-col text-xs text-zinc-500';
        // Provenance line: just the source label (e.g. "copied from the original
        // project"). Selection isn't spelled out here — the "✓ Selected" button
        // already says so — and a plain generate take has no label, so this line
        // is left out entirely.
        const line1 = document.createElement('span');
        line1.className = take.selected ? 'text-emerald-300' : 'text-zinc-400';
        line1.textContent = TAKE_SOURCE_LABELS[take.source] ?? take.source;
        const line2 = document.createElement('span');
        // Delivery/tuning label (archetype name or "Custom: …", built server-side)
        // · the pinned seed IF one was set (a random draw isn't worth a segment,
        // and Replicate never reports the seed it chose) · relative time.
        // Assembled from present parts so nothing dangles.
        // Lead with the voice name only when this take used a DIFFERENT voice than
        // the chunk's current one (server sends it just then), so a cross-voice take
        // is obvious before you Select it.
        const line2Parts = [];
        if (take.voice_name) line2Parts.push(take.voice_name);
        line2Parts.push(take.tuning_label);
        if (take.seed) line2Parts.push(`seed ${take.seed}`);
        if (take.created_human) line2Parts.push(take.created_human);
        line2.textContent = line2Parts.filter(Boolean).join(' · ');
        if (line1.textContent) meta.append(line1);
        meta.append(line2);
        if (take.asr_badge) {
            // Same popover as the chunk header, minus the undo footer (its actions
            // act on the chunk's current audio, not this historical take).
            const b = document.createElement('span');
            b.className = 'qa-badge-wrap relative mt-0.5 inline-flex w-fit';
            fillQaBadge(b, take.asr_badge, { compact: true, withActions: false });
            meta.append(b);
        }

        const actions = document.createElement('div');
        actions.className = 'flex items-center gap-1.5';
        const selectBtn = document.createElement('button');
        selectBtn.type = 'button';
        selectBtn.className = 'chunk-take-select rounded-lg border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800 disabled:cursor-default disabled:border-emerald-700/50 disabled:text-emerald-300 disabled:hover:bg-transparent';
        selectBtn.textContent = take.selected ? '✓ Selected' : 'Select';
        selectBtn.disabled = !!take.selected;
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'chunk-take-delete rounded-lg border border-zinc-800 px-2 py-1 text-xs text-zinc-500 hover:border-red-700/60 hover:text-red-300 disabled:cursor-default disabled:opacity-30 disabled:hover:border-zinc-800 disabled:hover:text-zinc-500';
        deleteBtn.textContent = 'Delete';
        deleteBtn.disabled = !!take.selected;
        deleteBtn.title = take.selected ? 'Select another take before deleting this one.' : 'Delete this take permanently.';
        actions.append(selectBtn, deleteBtn);

        li.append(player, meta, actions);
        return li;
    }

    // Refresh the lifetime-spend readouts from a server response. The labels
    // arrive pre-formatted (the server owns the money math — never re-derive it
    // here); absent `spend` means nothing changed or no rate is configured.
    function renderSpend(card, spend) {
        if (!spend) return;
        const chip = card.querySelector('.chunk-spend');
        if (chip && spend.chunk) {
            chip.textContent = spend.chunk.label;
            chip.title = spend.chunk.title;
            chip.classList.toggle('hidden', !(spend.chunk.spent > 0));
        }
        // Header/credit are stat chips (value over a static key), so write only
        // the bare figure into .stat-value — never the chip's whole textContent,
        // which would wipe the key span. `value` is the bare figure; `label`
        // (with its "project spend"/"credit" wording) is the pre-chip fallback.
        const header = document.getElementById('project-spend');
        if (header && spend.project) {
            const v = header.querySelector('.stat-value') || header;
            v.textContent = spend.project.value ?? spend.project.label;
            header.title = spend.project.title;
        }
        // Remaining prepaid credit for the project owner; the chip only exists
        // for limited owners. `low` flips the value to the warning color at/below $0.
        const balance = document.getElementById('credit-balance');
        if (balance && spend.balance) {
            const v = balance.querySelector('.stat-value') || balance;
            v.textContent = spend.balance.value ?? spend.balance.label;
            v.classList.toggle('text-amber-400', !!spend.balance.low);
            v.classList.toggle('text-ok', !spend.balance.low);
        }
    }

    // The chunk's main player wrapper — findable whether or not its native
    // <audio> has been created yet (lazy mode builds those on first tap).
    const chunkPlayerOf = (card) => card.querySelector('.chunk-audio')?.closest('.aplayer')
        || card.querySelector('.aplayer--chunk');

    // Point the chunk's player at a (busted) audio URL and reveal it: write the
    // native's src when it exists, else the data-audio-src it will be built from.
    const setChunkAudioSrc = (card, url) => {
        const wrap = chunkPlayerOf(card);
        if (!wrap) return;
        const audio = wrap.querySelector('.aplayer__native');
        if (audio) audio.src = url;
        else if (wrap.dataset.audioSrc !== undefined) wrap.dataset.audioSrc = url;
        wrap.classList.remove('hidden');
    };

    function renderTakes(card, data) {
        // Any render — lazy or from fresh server data — retires the card's
        // pending lazy pass (the IntersectionObserver checks this flag), so the
        // page-load JSON can't clobber a newer take list.
        card.dataset.takesRendered = '1';
        renderSpend(card, data && data.spend);
        // Keep the main player's duration fallback in step with whichever take the
        // chunk audio now points at (its src is cache-busted on select/generate, so
        // audio.duration is briefly unavailable — durationchange re-syncs from this).
        const selected = ((data && data.takes) || []).find((t) => t.selected);
        const mainPlayer = chunkPlayerOf(card);
        if (mainPlayer && selected?.duration_ms) mainPlayer.dataset.durationMs = selected.duration_ms;
        const list = card.querySelector('.chunk-takes');
        const takes = (data && data.takes) || [];
        // Stash the latest take list so the QA popover's "Restore full take /
        // keep original" can find the pre-fix take without a refetch.
        card._qaTakes = takes;
        if (!list) return;
        list.innerHTML = '';
        if (!takes.length) {
            const li = document.createElement('li');
            li.className = 'text-xs text-zinc-600';
            li.textContent = 'No takes yet — Generate to create one.';
            list.append(li);
            return;
        }
        takes.forEach((take) => list.append(takeRow(card, take)));
        enhanceStudioPlayers(list); // skin the freshly-built take players
    }

    async function selectTake(card, takeId, btn) {
        // Selecting restores the take's text — warn before it replaces an edit
        // the user typed but never saved (saved text is still in the history:
        // the take that rendered it can bring it back).
        if (isDirty(card) && !(await confirmDialog({
            title: 'Replace the unsaved edit?',
            message: 'Selecting a take restores the text it was rendered from, replacing the unsaved edit in this chunk.',
            label: 'Select take',
        }))) return;
        startBusy(btn, 'Selecting…');
        try {
            const res = await fetch(card.dataset.takesUrl + '/' + takeId + '/select', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            ensureTune(card); // the snapshot below restores into the tuning panel
            setChunkStatus(card, data.status);
            setChunkAsrBadge(card, data.asr_badge ?? null);
            setProjectStatus(data.project_status);
            // The select restored the take's snapshot server-side — mirror it
            // into the panel so text, knobs, and seed tell the truth about the
            // audio now selected. (A legacy take may carry no text snapshot.)
            if (data.text != null) {
                const textarea = card.querySelector('.chunk-text');
                textarea.value = data.text;
                card.querySelector('.chunk-chars').textContent = `${data.characters} chars`;
            }
            // Restore the take's voice BEFORE its tuning, so the take's knob values
            // land in the correct engine's now-visible inputs. Null (legacy take)
            // leaves the picker as-is.
            if (data.voice) {
                const chunkVoice = ensureVoiceOptions(card.querySelector('.chunk-voice'));
                const opt = chunkVoice?.querySelector(`option[value="${CSS.escape(data.voice)}"]`);
                if (chunkVoice && opt && chunkVoice.value !== data.voice) {
                    chunkVoice.value = data.voice;
                    chunkVoice.dataset.current = data.voice;
                    chunkVoice.dataset.inherits = '0'; // a restored take pins its voice explicitly
                    syncKnobEngines(card, modelOfSelect(chunkVoice));
                    card.dispatchEvent(new Event('engine-change'));
                }
            }
            applyTuningSnapshot(card, data.tuning, data.seed);
            // selectTake persisted the whole restored snapshot server-side, so it
            // is the new saved baseline — nothing should read as unsaved.
            commitBaseline(card);
            setDirty(card, false);
            setChunkAudioSrc(card, bust(card.dataset.audioUrl));
            card.querySelector('.chunk-generate').dataset.base = 'Regenerate';
            setGenerateLabel(card);
            renderTakes(card, data); // rebuilds the list (and detaches btn)
            refreshSeams();
            chunkNotice(card, '✓ Selected this take — its text and settings are restored below.', 'ok');
        } catch (err) {
            chunkNotice(card, `✗ ${err.message}`, 'error');
            endBusy(btn);
        }
    }

    async function deleteTake(card, takeId, btn) {
        if (!(await confirmDialog({
            title: 'Delete this take?',
            message: 'The take and its audio are deleted permanently — this cannot be undone.',
            label: 'Delete take',
        }))) return;
        startBusy(btn, 'Deleting…');
        try {
            const res = await fetch(card.dataset.takesUrl + '/' + takeId, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            renderTakes(card, await res.json());
            chunkNotice(card, ''); // the row vanishing is the feedback — just retire any stale notice
        } catch (err) {
            chunkNotice(card, `✗ ${err.message}`, 'error');
            endBusy(btn);
        }
    }

    // "Restore full take / keep original": revert an auto-fixed chunk to its
    // pre-fix take. That's the newest take that isn't the current audio and isn't
    // itself a QA auto-fix ('remediate'); selecting it re-points the chunk (and
    // carries that take's own, honest verdict back onto the badge).
    function restoreOriginalTake(card, btn) {
        const takes = card._qaTakes || [];
        const original = takes.find((t) => !t.selected && t.source !== 'remediate')
            || takes.find((t) => !t.selected);
        if (!original) {
            chunkNotice(card, '✗ No earlier take to restore.', 'error');
            return;
        }
        selectTake(card, original.id, btn);
    }

    // "Dismiss": acknowledge a flagged verdict without touching the audio. The
    // server records it on the chunk's asr_report and returns the muted "reviewed"
    // badge to swap in.
    async function dismissChunkQa(card, btn) {
        startBusy(btn, 'Dismissing…');
        try {
            const res = await fetch(card.dataset.qaDismissUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            setChunkAsrBadge(card, data.asr_badge ?? null);
            chunkNotice(card, ''); // the badge swapping to "reviewed" is the feedback
        } catch (err) {
            chunkNotice(card, `✗ ${err.message}`, 'error');
            endBusy(btn);
        }
    }

    // ---- Slim cards: shared option/chip sources -----------------------------
    // Big projects ship each card's voice picker with only its selected option
    // and its sound-tag row empty (see tts.studio_slim_cards); the full lists
    // render once as page-level <template>s and mount per card on approach or
    // first use. On eager markup both helpers are no-ops (no data-lazy-* hooks).
    const voiceOptionsTpl = document.getElementById('chunk-voice-options-template');
    const tagChipsTpl = document.getElementById('chunk-tag-chips-template');

    // Swap the full voice list into a slim select, keeping its value. MUST run
    // before any programmatic `.value =` write (revert, take restore, project
    // fanout): assigning a slug whose <option> isn't there silently no-ops.
    const ensureVoiceOptions = (select) => {
        if (!select || !select.dataset.lazyOptions || select.dataset.optionsLoaded || !voiceOptionsTpl) return select;
        select.dataset.optionsLoaded = '1';
        const value = select.value;
        select.replaceChildren(voiceOptionsTpl.content.cloneNode(true));
        select.value = value;
        return select;
    };

    const mountTagChips = (card) => {
        const slot = card.querySelector('.chunk-tag-slot[data-lazy-chips]');
        if (!slot || slot.dataset.chipsMounted || !tagChipsTpl) return;
        slot.dataset.chipsMounted = '1';
        slot.append(tagChipsTpl.content.cloneNode(true));
    };

    // Wire one card's tuning panel — everything below the takes list: seed row,
    // saved presets, Delivery chips, and the fine-tune disclosure. Called once
    // per card by ensureTune, AFTER the panel's controls exist and hold their
    // saved values, so no listener ever fires during setup.
    const wireTune = (card) => {
        initTuningKnobs(card); // slider ↔ number sync (idempotent per knob)

        // 🎲 rolls a fresh random seed into the field so the pin is visible and
        // re-usable (clearing the field by hand still means inherit/random).
        card.querySelector('.chunk-seed-random')?.addEventListener('click', () => {
            const seedInput = card.querySelector('.chunk-seed');
            seedInput.value = Math.floor(Math.random() * 1_000_000);
            seedInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        // The seed lives outside the fine-tune body, so track its edits (typed, 🎲,
        // or cleared by Reset all) as unsaved on their own.
        card.querySelector('.chunk-seed')?.addEventListener('input', () => setDirty(card, isDirty(card)));

        // "Apply preset" fills the native knobs (dispatching input so the sliders
        // sync); the next Regenerate saves and renders them. Seed is deliberately
        // not part of a preset — it's a per-take pin, not a reusable delivery.
        card.querySelector('.chunk-preset')?.addEventListener('change', (e) => {
            const opt = e.target.selectedOptions[0];
            if (!opt || !opt.value) return;
            [
                ['exaggeration', '.chunk-exaggeration'], ['cfg', '.chunk-cfg'], ['temperature', '.chunk-temperature'],
                ['topP', '.chunk-top-p'], ['topK', '.chunk-top-k'], ['repetitionPenalty', '.chunk-repetition-penalty'],
            ].forEach(([key, sel]) => {
                if (opt.dataset[key] === '' || opt.dataset[key] == null) return;
                const input = card.querySelector(sel);
                if (!input) return;
                input.value = opt.dataset[key];
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
            e.target.value = ''; // rest back on "Apply…" so it reads as an action
        });

        // ---- Delivery archetypes (Steady / Balanced / Expressive) --------------
        // The everyday control: each chip fills the (collapsed) sliders with a
        // full native knob set for the active engine. Dragging any slider off an
        // archetype's values un-lights the chips (implicit "Custom"). The values
        // and the matching both come from deliveryPresets, keyed by engine.
        const deliveryChips = card.querySelectorAll('.delivery-chip');
        const activeDeliveryTable = () => deliveryPresets[modelOfSelect(card.querySelector('.chunk-voice'))] || [];

        // A knob's EFFECTIVE value: the explicit override, else the inherited
        // placeholder (what would actually render). null when the knob is absent.
        const effectiveKnob = (sel) => {
            const input = card.querySelector(sel);
            if (!input) return null;
            const raw = input.value !== '' ? input.value : (input.placeholder || '');
            return raw === '' ? null : Number(raw);
        };

        // top_k is the only integer knob (compare rounded); the rest are floats
        // aligned to their step, so a tight epsilon is safe.
        const knobMatches = (key, a, b) => (key === 'top_k' ? Math.round(a) === Math.round(b) : Math.abs(a - b) < 0.005);

        // Which archetype the sliders currently match — every one of the active
        // engine's knobs equals that archetype's value — or null for Custom.
        const matchedDelivery = () => {
            for (const preset of activeDeliveryTable()) {
                const hit = KNOB_INPUTS.every(([key, sel]) => {
                    if (!(key in preset.values)) return true; // other engine's knob — ignore
                    const eff = effectiveKnob(sel);
                    return eff !== null && knobMatches(key, eff, Number(preset.values[key]));
                });
                if (hit) return preset.key;
            }
            return null;
        };

        const markDelivery = (key) => deliveryChips.forEach((chip) =>
            chip.classList.toggle('is-active', chip.dataset.delivery === key));
        const refreshDelivery = () => {
            // An engine with no archetypes (qwen) has no Delivery to offer —
            // the whole block (chips + saved-preset picker) hides.
            card.querySelector('.chunk-delivery-wrap')?.classList.toggle('hidden', activeDeliveryTable().length === 0);
            markDelivery(matchedDelivery());
        };

        const applyDelivery = (key) => {
            const preset = activeDeliveryTable().find((p) => p.key === key);
            if (!preset) return;
            KNOB_INPUTS.forEach(([nativeKey, sel]) => {
                if (!(nativeKey in preset.values)) return;
                const input = card.querySelector(sel);
                if (!input) return;
                input.value = preset.values[nativeKey];
                input.dispatchEvent(new Event('input', { bubbles: true })); // syncs slider + re-lights chips
            });
            markDelivery(key);
        };
        deliveryChips.forEach((chip) => chip.addEventListener('click', () => applyDelivery(chip.dataset.delivery)));
        // Any slider drag re-evaluates which archetype (if any) is now matched, and
        // flags the chunk unsaved. This also covers Delivery chips and the preset
        // picker — both fill these knobs and dispatch input, which bubbles here.
        card.querySelector('.finetune-body')?.addEventListener('input', () => {
            refreshDelivery();
            setDirty(card, isDirty(card));
        });

        // ---- Fine-tune disclosure ---------------------------------------------
        // The raw sliders collapse behind a toggle whose (N) reflects the active
        // engine's knob count; open/closed is remembered across chunks and visits.
        const FINE_KEY = 'alias.studio.finetuneOpen';
        const fineToggle = card.querySelector('.finetune-toggle');
        const fineBody = card.querySelector('.finetune-body');
        const fineCaret = card.querySelector('.finetune-caret');
        const fineCount = card.querySelector('.finetune-count');
        const resetAllBtn = card.querySelector('.chunk-tune-reset-all');

        const updateFineCount = () => {
            if (fineCount) fineCount.textContent = `(${card.querySelectorAll('.finetune-body .tuning-knob:not(.hidden)').length})`;
        };
        // Toggle hidden AND flex together — a lingering flex would beat hidden in
        // the compiled CSS (the app's hidden-vs-flex gotcha).
        const setFineOpen = (open) => {
            fineBody?.classList.toggle('hidden', !open);
            fineBody?.classList.toggle('flex', open);
            if (fineCaret) fineCaret.textContent = open ? '▾' : '▸';
            fineToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
            resetAllBtn?.classList.toggle('hidden', !open);
        };
        fineToggle?.addEventListener('click', () => {
            const open = fineBody?.classList.contains('hidden'); // hidden now => we're opening
            setFineOpen(open);
            try { localStorage.setItem(FINE_KEY, open ? '1' : '0'); } catch { /* ignore */ }
        });

        // Reset all: drop every knob override + seed back to the project's
        // inherited tuning (which re-lights Balanced on a default project).
        resetAllBtn?.addEventListener('click', () => {
            KNOB_INPUTS.forEach(([, sel]) => {
                const input = card.querySelector(sel);
                if (!input) return;
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true })); // rests slider at inherited
            });
            const seedInput = card.querySelector('.chunk-seed');
            if (seedInput) { seedInput.value = ''; seedInput.dispatchEvent(new Event('input', { bubbles: true })); }
            refreshDelivery();
        });

        // Following a voice change may switch engines — re-point the chips to the
        // new engine's archetypes and re-count the sliders. (Dispatched by both
        // voice-change handlers after syncKnobEngines.)
        card.addEventListener('engine-change', () => { refreshDelivery(); updateFineCount(); });

        // Initial state: restore the remembered open/closed, count the sliders,
        // and light the matching archetype (Balanced for an untouched chunk).
        let fineOpenPref = false;
        try { fineOpenPref = localStorage.getItem(FINE_KEY) === '1'; } catch { /* ignore */ }
        setFineOpen(fineOpenPref);
        updateFineCount();
        refreshDelivery();

        // Revert (wired in the init loop, panel or no panel) restores into this
        // panel — hand it the closures it needs.
        card._refreshDelivery = refreshDelivery;
        card._updateFineCount = updateFineCount;
    };

    // The tuning panel is the single heaviest per-card block, so slim cards ship
    // the <details> with only its takes list; the chunk's overrides park on
    // data-tune-settings. Mounting clones the shared #chunk-tune-template, fills
    // the overrides in (baselines included, so isDirty stays truthful), syncs
    // the knob set to the card's actual engine, then wires the panel — a path
    // eager markup also routes through (from the init loop instead). Idempotent;
    // every code path that reads or writes panel controls (Regenerate payload,
    // take restore, Revert) calls this first.
    const tuneTpl = document.getElementById('chunk-tune-template');
    const ensureTune = (card) => {
        if (card.dataset.tuneWired) return card;
        const details = card.querySelector('.chunk-tune');
        if (!details) return card;
        if (details.dataset.lazyTune) {
            if (!tuneTpl) return card; // slim markup without its template — leave for a later attempt
            details.append(tuneTpl.content.cloneNode(true));
            let overrides = {};
            try { overrides = JSON.parse(details.dataset.tuneSettings || '{}'); } catch { /* ignore */ }
            const fill = (key, sel) => {
                const input = card.querySelector(sel);
                if (!input) return;
                input.value = overrides[key] == null ? '' : String(overrides[key]);
                // What was just applied IS the saved state — baseline it so a
                // mount can never read as an unsaved edit.
                input.dataset.original = input.value;
                // Rest the paired slider on the override, or the inherited default.
                const range = input.closest('.tuning-knob')?.querySelector('.knob-range');
                if (range) range.value = input.value === '' ? (input.placeholder || range.min) : input.value;
            };
            fill('seed', '.chunk-seed');
            KNOB_INPUTS.forEach(([key, sel]) => fill(key, sel));
            // The template rendered as the PROJECT's engine — re-sync to this
            // card's actual voice (covers per-chunk voices, and a project voice
            // change that fanned out while the card was unmounted).
            syncKnobEngines(card, modelOfSelect(card.querySelector('.chunk-voice')));
        }
        card.dataset.tuneWired = '1';
        wireTune(card);
        return card;
    };

    // Take histories are heavy: every take row carries its own <audio> player,
    // so a 147-chunk project would build ~450 of them during init — and iPad
    // Safari grinds to a halt well before that (WebKit degrades badly with
    // hundreds of media elements). Build each card's takes (and mount its slim
    // pieces) only as it nears the viewport. Any direct renderTakes() call
    // (generate, select, poll) marks the card rendered, so the page-load JSON
    // can never overwrite fresher server data.
    const mountCard = (card) => {
        ensureVoiceOptions(card.querySelector('.chunk-voice'));
        mountTagChips(card);
        ensureTune(card);
        if (!card.dataset.takesRendered) {
            try { renderTakes(card, JSON.parse(card.dataset.takes || '{}')); } catch { /* ignore */ }
        }
    };

    const lazyTakes = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            lazyTakes.unobserve(entry.target);
            mountCard(entry.target);
        });
    }, { rootMargin: '600px 0px' }) : null;

    root.querySelectorAll('.studio-chunk').forEach((card) => {
        // Mount the card's deferred pieces (lazily where supported — see above),
        // and wire Select/Delete via one delegated listener (the take list is
        // rebuilt wholesale on every change).
        if (lazyTakes) lazyTakes.observe(card);
        else mountCard(card);
        card.querySelector('.chunk-takes')?.addEventListener('click', (e) => {
            const row = e.target.closest('.chunk-take');
            if (!row) return;
            const selBtn = e.target.closest('.chunk-take-select');
            const delBtn = e.target.closest('.chunk-take-delete');
            if (selBtn && !selBtn.disabled) selectTake(card, row.dataset.takeId, selBtn);
            else if (delBtn && !delBtn.disabled) deleteTake(card, row.dataset.takeId, delBtn);
        });

        // QA popover footer actions (design "QA Badge States"). Delegated on the
        // .chunk-asr-badge wrapper (its innerHTML is rebuilt on every verdict, but
        // the wrapper element persists). Regenerate and Play reuse the existing
        // chunk controls; Restore reverts to the pre-fix take; Dismiss
        // acknowledges a flag. On a foreign project the guard intercepts these
        // (they mutate) — only opening the popover (.qa-badge) is whitelisted.
        card.querySelector('.chunk-asr-badge')?.addEventListener('click', (e) => {
            const act = e.target.closest('.qa-act');
            if (!act) return;
            e.preventDefault();
            const kind = act.dataset.qaAct;
            if (kind === 'reroll') {
                card.querySelector('.chunk-generate')?.click();
            } else if (kind === 'play') {
                const wrap = chunkPlayerOf(card);
                wrap?.classList.remove('hidden');
                ensureNative(wrap)?.play().catch(() => {});
            } else if (kind === 'restore') {
                restoreOriginalTake(card, act);
            } else if (kind === 'dismiss') {
                dismissChunkQa(card, act);
            }
            // Fold the popover away; a resulting verdict change rebuilds it fresh.
            const pop = card.querySelector('.qa-popover');
            pop?.classList.add('hidden');
            card.querySelector('.qa-badge')?.setAttribute('aria-expanded', 'false');
        });

        card.querySelector('.chunk-generate').addEventListener('click', () => queueChunkRegen(card));

        // Per-chunk voice override: a PENDING edit, like the text and tuning —
        // held on screen and written with the next Regenerate, not saved on its
        // own. Picking a voice may switch engines, so swap the knob set and
        // re-point the Delivery chips; then flag the chunk unsaved (Revert puts it
        // back). An explicit pick stops the chunk mirroring the project voice.
        const chunkVoice = card.querySelector('.chunk-voice');
        if (chunkVoice) {
            chunkVoice.dataset.current = chunkVoice.value;
            // Belt-and-braces for slim cards: the observer mounts the card as it
            // nears view, but focus (fires before the dropdown opens, mouse or
            // keyboard) guarantees the options AND the tuning panel regardless —
            // a voice change flows straight into both.
            chunkVoice.addEventListener('focus', () => mountCard(card), { once: true });
            chunkVoice.addEventListener('change', () => {
                const voice = chunkVoice.value;
                if (voice === chunkVoice.dataset.current) return;
                chunkVoice.dataset.current = voice;
                chunkVoice.dataset.inherits = '0';
                syncKnobEngines(card, modelOfSelect(chunkVoice));
                card.dispatchEvent(new Event('engine-change'));
                setDirty(card, isDirty(card));
                chunkNotice(card, 'Voice changed — Regenerate to apply.');
            });
        }

        // Tuning panel: eager markup wires it now — the baseline below reads the
        // live controls, matching the pre-slim init exactly. Slim cards defer the
        // whole panel to ensureTune via mountCard as they near the viewport.
        if (!card.querySelector('.chunk-tune[data-lazy-tune]')) ensureTune(card);

        // Snapshot the server-rendered panel as the saved baseline, so isDirty/Revert
        // have a reference point for the voice, knobs and seed from the first paint.
        // (A slim card baselines its deferred tuning controls at mount instead.)
        commitBaseline(card);

        // Sound-tag chips (turbo only — the row swaps with the engine): insert
        // the tag at the textarea's cursor with sensible spacing, replacing any
        // selection. Dispatching `input` runs the same paths typing would
        // (dirty tracking, preview invalidation), so Save/Generate react.
        card.querySelector('.chunk-sound-tags')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.chunk-tag-insert');
            if (!btn) return;
            const textarea = card.querySelector('.chunk-text');
            const start = textarea.selectionStart ?? textarea.value.length;
            const end = textarea.selectionEnd ?? start;
            const before = textarea.value.slice(0, start);
            const after = textarea.value.slice(end);
            const tag = (before === '' || /\s$/.test(before) ? '' : ' ')
                + btn.dataset.tag
                + (after === '' || /^\s/.test(after) ? '' : ' ');
            textarea.setRangeText(tag, start, end, 'end');
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.focus();
        });

        // Track dirty state as the user types.
        card.querySelector('.chunk-text').addEventListener('input', () => setDirty(card, isDirty(card)));
        // Revert restores the WHOLE panel to its last-saved baseline in one click:
        // text, voice (which may switch engines back), every knob, and the seed.
        card.querySelector('.chunk-revert').addEventListener('click', () => {
            ensureTune(card); // the restore below writes into the tuning panel
            const textarea = card.querySelector('.chunk-text');
            textarea.value = textarea.dataset.original;
            const voice = ensureVoiceOptions(card.querySelector('.chunk-voice'));
            if (voice && voice.value !== voice.dataset.original) {
                voice.value = voice.dataset.original;
                voice.dataset.current = voice.dataset.original;
                voice.dataset.inherits = voice.dataset.inheritsOriginal ?? '1';
                syncKnobEngines(card, modelOfSelect(voice));
                card.dispatchEvent(new Event('engine-change'));
            }
            // Rebuild the baseline knob/seed map and reuse applyTuningSnapshot to
            // fill it back in (its input dispatches re-sync sliders + Delivery chips).
            const tuning = {};
            KNOB_INPUTS.forEach(([key, sel]) => {
                const original = card.querySelector(sel)?.dataset.original;
                tuning[key] = (original == null || original === '') ? null : Number(original);
            });
            const seedOriginal = card.querySelector('.chunk-seed')?.dataset.original;
            applyTuningSnapshot(card, tuning, (seedOriginal == null || seedOriginal === '') ? null : Number(seedOriginal));
            card._refreshDelivery?.();
            card._updateFineCount?.();
            setDirty(card, false);
        });

        // Delete this chunk — a two-step inline confirm (destructive). Deletion
        // renumbers the list, so — like insert — reload to re-render it. The
        // control isn't rendered for a one-chunk project (guard the wiring too).
        const deleteBtn = card.querySelector('.chunk-delete');
        // Skip toggle: reversible (no confirm), updates in place — no reload. The
        // explicit body state (not a blind flip) keeps double-clicks idempotent.
        const skipBtn = card.querySelector('.chunk-skip');
        if (skipBtn) {
            skipBtn.addEventListener('click', async () => {
                if (skipBtn.dataset.busy) return;
                const skipped = !isChunkSkipped(card);
                startBusy(skipBtn, '…');
                try {
                    const res = await fetch(card.dataset.skipUrl, {
                        method: 'PATCH',
                        headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                        body: JSON.stringify({ skipped }),
                    });
                    if (!res.ok) throw new Error(await errorMessage(res));
                    const data = await res.json();
                    endBusy(skipBtn);
                    setChunkSkipped(card, data.skipped);
                    setProjectStatus(data.project_status); // also re-lights the action cluster
                    refreshSeams();
                    chunkNotice(card, data.skipped
                        ? "✓ Chunk skipped — it won't be in the final."
                        : '✓ Chunk included again.', 'ok');
                } catch (err) {
                    endBusy(skipBtn);
                    chunkNotice(card, `✗ ${err.message}`, 'error');
                }
            });
        }

        const deleteConfirm = card.querySelector('.chunk-delete-confirm');
        if (deleteBtn && deleteConfirm) {
            // Toggle hidden AND inline-flex together so neither lingers and wins
            // over the other in the compiled CSS (see the dirty-badge note above).
            const showConfirm = (show) => {
                deleteBtn.classList.toggle('hidden', show);
                deleteConfirm.classList.toggle('hidden', !show);
                deleteConfirm.classList.toggle('inline-flex', show);
            };
            const yesBtn = deleteConfirm.querySelector('.chunk-delete-yes');
            deleteBtn.addEventListener('click', () => showConfirm(true));
            deleteConfirm.querySelector('.chunk-delete-no').addEventListener('click', () => showConfirm(false));
            yesBtn.addEventListener('click', async () => {
                startBusy(yesBtn, 'Deleting…');
                try {
                    const res = await fetch(card.dataset.deleteUrl, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                    });
                    if (!res.ok) throw new Error(await errorMessage(res));
                    skipUnloadGuard = true; // the delete is committed; the reload is intentional
                    window.location.reload();
                } catch (err) {
                    chunkNotice(card, `✗ ${err.message}`, 'error');
                    endBusy(yesBtn);
                    showConfirm(false);
                }
            });
        }
    });

    initTuningKnobs(root); // wire every per-chunk Exaggeration / CFG-Pace / Temperature slider

    generateAllBtn.addEventListener('click', generateAll);
    rebuildBtn.addEventListener('click', rebuild);

    // A run dispatched earlier is still working — pick it back up. (This is the
    // whole point of the background run: leaving the page no longer kills it.)
    if (root.dataset.activeRun === '1') {
        // A stitch run resumes as a Build final follow (busy Build button +
        // status line); a duplicate run resumes as a copy-and-open follow; every
        // other type is a generate follow.
        if (root.dataset.activeRunType === 'stitch') followStitch(false);
        else if (root.dataset.activeRunType === 'duplicate') followDuplicate();
        else followRun();
    }

    // Don't let unsaved chunk edits vanish on navigation. Two layers:
    //
    //  1. In-app links (← Projects, Start over, the top nav) are plain anchors,
    //     so we can catch the click and show the app's own modal instead of
    //     Chrome's un-stylable "Leave site?" prompt. On confirm we set
    //     skipUnloadGuard and navigate ourselves.
    //  2. Genuine browser exits we can't intercept — closing the tab, a reload,
    //     the URL bar, Back/Forward, a form submit — still fall to the native
    //     beforeunload below. The browser only allows its own dialog there, so
    //     that plain prompt survives by necessity, for those cases alone.
    //
    // Intentional reloads (save, delete, insert) set skipUnloadGuard first so
    // neither layer fires.
    const hasUnsavedChunks = () => [...root.querySelectorAll('.studio-chunk')].some(isDirty);

    document.addEventListener('click', (e) => {
        if (skipUnloadGuard || e.defaultPrevented || e.button !== 0
            || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const link = e.target instanceof Element ? e.target.closest('a[href]') : null;
        // Skip new-tab / download links, and anything not a real navigation away.
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
        let url;
        try { url = new URL(link.href, location.href); } catch { return; }
        if (url.origin !== location.origin || url.pathname === location.pathname) return;
        if (!hasUnsavedChunks()) return;
        e.preventDefault();
        confirmDialog({
            title: 'Unsaved chunk edits',
            message: "Leaving this project now will lose the chunk edits you haven't saved.",
            label: 'Leave anyway',
            tone: 'warn',
        }).then((ok) => {
            if (!ok) return;
            skipUnloadGuard = true; // our modal already asked — don't double-prompt on unload
            window.location.href = link.href;
        });
    });

    window.addEventListener('beforeunload', (e) => {
        if (skipUnloadGuard) return;
        if (hasUnsavedChunks()) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Insert an empty chunk at a gap, then reload to re-render the (renumbered) list.
    // The Insert action lives on each seam (and the top/tail connector lines); every
    // one carries its own data-position (see the seam markup).
    root.querySelectorAll('.seam-insert').forEach((btn) => {
        btn.addEventListener('click', async () => {
            // Inserting reloads the list, which would drop unsaved edits elsewhere.
            if ([...root.querySelectorAll('.studio-chunk')].some(isDirty)
                && !(await confirmDialog({
                    title: 'Unsaved chunk edits',
                    message: 'Inserting a chunk reloads the list, and your unsaved edits will be lost.',
                    label: 'Insert anyway',
                    tone: 'warn',
                }))) {
                return;
            }
            const pos = Number(btn.dataset.position);
            startBusy(btn, 'Inserting…');
            try {
                const res = await fetch(insertUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ position: pos }),
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                // Breadcrumb for the reload: the new chunk lands at 0-based index
                // `pos`, and highlightInsertedChunk() below reads this to open, ring,
                // and focus it. Path-scoped so a later page can't consume a stale one.
                try {
                    sessionStorage.setItem('studioInsertedAt', JSON.stringify({ path: location.pathname, pos }));
                } catch (_) { /* storage off (private mode): reload still works, just no highlight */ }
                skipUnloadGuard = true; // reload is intentional
                window.location.reload();
            } catch (err) {
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
                endBusy(btn);
            }
        });
    });

    // The reload above re-renders the whole (renumbered) list and lands back at the
    // top of the page, so a freshly inserted chunk is easy to lose. Pick it up from
    // the breadcrumb the insert handler left, then draw the eye to it: animate its
    // space open, flash an accent ring once, scroll it to center, and drop the cursor
    // in its empty text field (its own cyan focus ring marks it). Runs synchronously
    // from the deferred module — before the first paint — so the collapse-to-zero is
    // never seen at full height first.
    (function highlightInsertedChunk() {
        let stash;
        try {
            stash = sessionStorage.getItem('studioInsertedAt');
            sessionStorage.removeItem('studioInsertedAt'); // one-shot, whatever happens next
        } catch (_) {
            return; // storage off: nothing to do
        }
        if (!stash) return;
        let path, pos;
        try {
            ({ path, pos } = JSON.parse(stash));
        } catch (_) {
            return;
        }
        // Only consume the breadcrumb on the page that dropped it.
        if (path !== window.location.pathname) return;

        const target = root.querySelectorAll('.studio-chunk')[pos];
        if (!target) return;
        const textarea = target.querySelector('.chunk-text');

        // Reduced motion: skip the open/ring animation — just bring the new chunk on
        // screen and hand it the cursor (the focus ring alone marks its place).
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            target.scrollIntoView({ block: 'center' });
            if (textarea) textarea.focus();
            return;
        }

        // Collapse it now, before paint, so the card starts from nothing...
        const full = target.getBoundingClientRect().height;
        target.style.overflow = 'hidden';
        target.style.height = '0px';
        target.style.opacity = '0';

        // ...then, next frame, release it to its natural height and cue it.
        requestAnimationFrame(() => {
            target.style.transition = 'height 320ms ease-out, opacity 320ms ease-out';
            target.style.height = `${full}px`;
            target.style.opacity = '1';
            target.classList.add('chunk-inserted-flash');
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (textarea) textarea.focus({ preventScroll: true });
            target.addEventListener('transitionend', function done(e) {
                if (e.propertyName !== 'height') return;
                // Hand height/overflow back to the stylesheet so the card can keep
                // growing as its content (takes, tags) fills in later.
                target.style.height = '';
                target.style.overflow = '';
                target.style.opacity = '';
                target.style.transition = '';
                target.removeEventListener('transitionend', done);
            });
        });
    })();

    // Inline rename: swap the page heading for a title input, PATCH on save, then
    // update the heading and tab title in place. The control lives next to the
    // <h1> via the layout's titleActions slot, so these elements sit outside #studio-project.
    const renameUrl = root.dataset.renameUrl;
    const renameBtn = document.getElementById('project-rename');
    const renameForm = document.getElementById('project-rename-form');
    const titleInput = document.getElementById('project-title-input');
    const renameSave = document.getElementById('project-rename-save');
    const renameCancel = document.getElementById('project-rename-cancel');
    const heading = document.getElementById('project-title-label') || document.querySelector('h1');

    function openRename() {
        titleInput.value = heading.textContent.trim();
        heading.classList.add('hidden');
        renameBtn.classList.add('hidden');
        renameForm.classList.remove('hidden');
        renameForm.classList.add('flex');
        titleInput.focus();
        titleInput.select();
    }

    function closeRename() {
        renameForm.classList.add('hidden');
        renameForm.classList.remove('flex');
        heading.classList.remove('hidden');
        renameBtn.classList.remove('hidden');
    }

    async function saveRename() {
        const title = titleInput.value.trim();
        if (!title) { titleInput.focus(); return; }
        startBusy(renameSave, 'Saving…');
        try {
            const res = await fetch(renameUrl, {
                method: 'PATCH',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ title }),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            heading.textContent = data.title;
            document.title = `${data.title} — Alias TTS`;
            closeRename();
            setStatus(finalStatus, '✓ Renamed.', 'ok');
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(renameSave);
        }
    }

    if (renameBtn) {
        renameBtn.addEventListener('click', openRename);
        renameSave.addEventListener('click', saveRename);
        renameCancel.addEventListener('click', closeRename);
        titleInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); saveRename(); }
            if (e.key === 'Escape') { e.preventDefault(); closeRename(); }
        });
    }

    // Dismiss the "recovered from a failed API generation" banner: clear the flag
    // server-side (which also drops the index's "API failure" badge and the prune
    // TTL), then remove the banner and update the heading to the cleaned title.
    const dismissBtn = document.getElementById('project-dismiss-failure');
    if (dismissBtn) {
        dismissBtn.addEventListener('click', async () => {
            startBusy(dismissBtn, 'Dismissing…');
            try {
                const res = await fetch(root.dataset.dismissUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                const data = await res.json();
                if (data.title && heading) {
                    heading.textContent = data.title;
                    document.title = `${data.title} — Alias TTS`;
                }
                document.getElementById('project-failure-notice')?.remove();
                setStatus(finalStatus, '✓ Cleared the API-failure flag — this is now a regular project.', 'ok');
            } catch (err) {
                endBusy(dismissBtn);
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            }
        });
    }

    // Voice switch: PATCH the project's voice. Existing audio was generated with
    // the old voice, so the server marks every generated chunk stale — reflect
    // that in place (and revert the picker if the request fails).
    const voiceSelect = document.getElementById('project-voice');
    if (voiceSelect) {
        let lastVoice = voiceSelect.value;
        voiceSelect.addEventListener('change', async () => {
            const voice = voiceSelect.value;
            if (voice === lastVoice) return;
            voiceSelect.disabled = true;
            try {
                const res = await fetch(root.dataset.voiceUrl, {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ voice }),
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                const data = await res.json();
                lastVoice = voice;
                // Only chunks that INHERIT the project voice are affected: mirror
                // the new voice into their pickers and stale their generated audio.
                // Chunks with an explicit per-chunk voice are left untouched.
                document.querySelectorAll('.studio-chunk').forEach((card) => {
                    const cv = ensureVoiceOptions(card.querySelector('.chunk-voice'));
                    if (!cv || cv.dataset.inherits !== '1') return;
                    cv.value = voice;
                    cv.dataset.current = voice;
                    // The chunk still inherits, so this IS its new saved voice —
                    // move the baseline with it, or the picker would read as an
                    // unsaved edit the user never made. (It goes 'stale' below: its
                    // audio predates the new voice and wants a regenerate.)
                    cv.dataset.original = voice;
                    // Following the project voice may change the engine too.
                    syncKnobEngines(card, modelOfSelect(cv));
                    card.dispatchEvent(new Event('engine-change'));
                    if (card.querySelector('.chunk-status').textContent.trim() === 'completed') {
                        setChunkStatus(card, 'stale');
                    }
                });
                setProjectStatus(data.project_status);
                refreshSeams();
                setStatus(finalStatus, `✓ Voice set to ${data.voice_name}. Regenerate chunks to apply.`, 'ok');
            } catch (err) {
                voiceSelect.value = lastVoice; // revert the picker on failure
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            } finally {
                voiceSelect.disabled = false;
            }
        });
    }

    // Format switch: PATCH the project's final-audio format (mp3/wav). Chunk audio
    // is format-independent, so nothing regenerates — but the built final was
    // encoded in the old format, so the server marks the project stale; reflect
    // that and prompt a rebuild (revert the picker if the request fails).
    const formatSelect = document.getElementById('project-format');
    if (formatSelect) {
        let lastFormat = formatSelect.value;
        formatSelect.addEventListener('change', async () => {
            const output_format = formatSelect.value;
            if (output_format === lastFormat) return;
            formatSelect.disabled = true;
            try {
                const res = await fetch(root.dataset.formatUrl, {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ output_format }),
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                const data = await res.json();
                lastFormat = output_format;
                setProjectStatus(data.project_status);
                reflectActionState();
                const label = formatSelect.options[formatSelect.selectedIndex].text;
                setStatus(finalStatus, `✓ Format set to ${label}. Build the final to apply.`, 'ok');
            } catch (err) {
                formatSelect.value = lastFormat; // revert the picker on failure
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            } finally {
                formatSelect.disabled = false;
            }
        });
    }

    // Duplicating byte-copies every audio file before the redirect, so the POST
    // can run for a while — show a pending state (spinner in the menu + the
    // aria-live status line) instead of appearing frozen, and swallow repeat
    // submits so a second click can't mint a second copy.
    const duplicateForm = document.getElementById('project-duplicate-form');
    const duplicateBtn = document.getElementById('project-duplicate');
    if (duplicateForm && duplicateBtn) {
        duplicateForm.addEventListener('submit', (e) => {
            if (duplicateForm.dataset.submitting) { e.preventDefault(); return; }
            duplicateForm.dataset.submitting = '1';
            startBusy(duplicateBtn, 'Duplicating…');
            setStatus(finalStatus, 'Duplicating project — copying every chunk and its audio. This can take a moment…');
        });
    }

    // Overflow ⋯ menu (Start over / Delete / receipt). Same toggle idiom as the nav.
    const overflowBtn = document.getElementById('project-overflow');
    const overflowMenu = document.getElementById('project-overflow-menu');
    if (overflowBtn && overflowMenu) {
        overflowBtn.addEventListener('click', (e) => { e.stopPropagation(); overflowMenu.classList.toggle('hidden'); });
        document.addEventListener('click', (e) => {
            if (!overflowMenu.classList.contains('hidden') && !overflowMenu.contains(e.target) && !overflowBtn.contains(e.target)) {
                overflowMenu.classList.add('hidden');
            }
        });
    }

    enhanceStudioPlayers(root); // skin the custom players (hero final audio)
    reflectActionState(); // set the initial action-cluster looks from current state
    refreshSeams();
}

// Skin a native <audio> as the custom player (design 4A): a wrapper `.aplayer`
// holds a hidden `.aplayer__native` that does playback; this drives the play
// button, scrubber fill/knob, and mono timecode from the audio's own events.
function enhanceStudioPlayers(scope) {
    (scope || document).querySelectorAll('.aplayer').forEach((el) => {
        if (el.dataset.enhanced) return;
        const btn = el.querySelector('.aplayer__btn');
        const track = el.querySelector('.aplayer__track');
        const fill = el.querySelector('.aplayer__fill');
        const knob = el.querySelector('.aplayer__knob');
        const time = el.querySelector('.aplayer__time');
        // Lazy players (data-audio-src) carry no native <audio> until the first
        // tap builds one via ensureNative() — read it fresh, never close over it.
        const native = () => el.querySelector('.aplayer__native');
        if ((!native() && el.dataset.audioSrc === undefined) || !btn || !track) return;
        el.dataset.enhanced = '1';

        const fmt = (s) => (isFinite(s) && s >= 0)
            ? Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0')
            : '0:00';
        const sync = () => {
            // A failed load owns the readout until the next attempt starts —
            // the browser fires trailing timeupdate/pause after 'error' that
            // would otherwise immediately overwrite the failure notice.
            if (el.dataset.loadFailed) return;
            const audio = native();
            const ct = audio ? audio.currentTime : 0;
            // Until the audio (exists and) has metadata, fall back to the
            // server-recorded length (data-duration-ms) so the duration shows
            // without any interaction or request.
            const d = (audio && audio.duration) || (parseInt(el.dataset.durationMs, 10) || 0) / 1000;
            const pct = d ? (ct / d) * 100 : 0;
            if (fill) fill.style.width = pct + '%';
            if (knob) knob.style.left = pct + '%';
            if (time) time.textContent = fmt(ct) + ' / ' + fmt(d);
            // Remember where playback got to, so an error retry can resume
            // there instead of 0:00 (see the ▶ handler).
            if (ct > 0) el.dataset.lastTime = String(ct);
        };
        // Third transport state between idle and playing: lazy players fetch
        // their audio on the first tap, so there's real network time before
        // sound while the icon already shows "playing". `.is-loading` draws a
        // spinner arc over the button ring (see .aplayer__btn::after).
        const loading = (on) => {
            el.classList.toggle('is-loading', on);
            btn.setAttribute('aria-busy', on ? 'true' : 'false');
        };

        btn.addEventListener('click', () => {
            const audio = ensureNative(el);
            if (!audio) return;
            // A failed fetch bricks the element: after a media error, play()
            // won't re-run resource selection, so clicks would silently no-op
            // forever. load() re-arms it and the play below retries the fetch —
            // re-following the audio route's redirect, so an expired signed
            // storage URL is replaced by a fresh one. Resume where the failure
            // struck, not from the top.
            if (audio.error) {
                const resumeAt = parseFloat(el.dataset.lastTime || '0');
                audio.load();
                if (resumeAt > 0) {
                    audio.addEventListener('loadedmetadata', () => { audio.currentTime = resumeAt; }, { once: true });
                }
            }
            if (audio.paused) {
                // Safari is slow to fire 'waiting' on a cold start; flag the
                // stall here so the spinner appears with the tap, not after it.
                if (audio.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) loading(true);
                audio.play().catch(() => loading(false));
            } else {
                audio.pause();
            }
        });
        track.addEventListener('click', (e) => {
            // Scrubbing a never-built player is a no-op (nothing is loaded yet).
            const audio = native();
            if (!audio) return;
            const r = track.getBoundingClientRect();
            if (audio.duration) audio.currentTime = ((e.clientX - r.left) / r.width) * audio.duration;
        });

        // The transport listeners — attached now for an eager native, or by
        // ensureNative() the moment a lazy player builds its element.
        const wire = (audio) => {
            audio.addEventListener('timeupdate', sync);
            audio.addEventListener('loadedmetadata', sync);
            // Fires on src swap (duration resets) and when the new metadata arrives, so
            // the readout tracks a re-selected take via the data-duration-ms fallback.
            audio.addEventListener('durationchange', sync);
            audio.addEventListener('play', () => el.classList.add('is-playing'));
            audio.addEventListener('pause', () => { el.classList.remove('is-playing'); loading(false); });
            audio.addEventListener('ended', () => { el.classList.remove('is-playing'); loading(false); });
            // Mid-play rebuffering and scrub-ahead stalls get the same spinner
            // as the cold start; 'playing' means sound is actually running.
            audio.addEventListener('waiting', () => loading(true));
            audio.addEventListener('playing', () => loading(false));
            // Surface load/decode failures in the readout, so a dropped connection
            // reads as retryable instead of a dead button. A fresh attempt (the
            // retry's load()+play() above) fires loadstart, which hands the readout
            // back to sync().
            // (lastTime too: a fresh load means a new resource — the retry above
            // captured its resume point before calling load(), so this can't race.)
            audio.addEventListener('loadstart', () => { delete el.dataset.loadFailed; delete el.dataset.lastTime; sync(); });
            audio.addEventListener('error', () => {
                el.classList.remove('is-playing');
                loading(false);
                el.dataset.loadFailed = '1';
                if (time) time.textContent = 'failed — press ▶ to retry';
            });
        };
        el._wireNative = wire;
        if (native()) wire(native());
        sync();
    });
}

// Big projects render a Blade loading veil (#studio-loading) over the page; it
// comes down only once every control here is wired and live. Remove it even if
// init throws, so a JS error degrades to the old inert page, not a stuck veil.
try {
    initStudioProject();
} finally {
    document.getElementById('studio-loading')?.remove();
}

// ---- Studio mobile transport dock (design 12B) --------------------------------
// Below 640px nothing pins at the top of the project page: the header scrolls
// away, chunks own the screen, and the transport pins at the BOTTOM — a compact
// dock (play / scrubber / one-line status / primary download) that expands into
// the full production sheet. The dock and the desktop header are the same
// logical component in two placements: the header's rows 2–3 (hero player,
// Voice/Format, action cluster, status lines) plus the status pill and ⋯ menu
// are MOVED into the sheet on entering mobile and moved back on leaving, so
// every control keeps its single id and all of initStudioProject's wiring
// (reflectActionState, setStatus, the one shared final-audio element) drives
// both layouts untouched. The dock's own readouts are mirrors, kept in step by
// a MutationObserver on the nodes that wiring already updates.
function initStudioMobileDock() {
    const root = document.getElementById('studio-project');
    const dock = document.getElementById('project-dock');
    const sheet = document.getElementById('project-sheet');
    const scrim = document.getElementById('project-sheet-scrim');
    if (!root || !dock || !sheet || !scrim) return;

    const audio = document.getElementById('project-final-audio');
    const finalPlayer = document.getElementById('project-final-player');
    const statusPill = document.getElementById('project-status');
    const finalStatus = document.getElementById('project-final-status');
    const downloadLink = document.getElementById('project-download');
    const receiptLink = document.getElementById('project-receipt');
    const titleLabel = document.getElementById('project-title-label');
    const spendValue = document.querySelector('#project-spend .stat-value');

    // ---- Two placements, one set of nodes ------------------------------------
    // Each group's desktop anchor (parent + next sibling) is captured now, while
    // everything sits in header position. Restores run in REVERSE list order so
    // a group whose anchor is a later-listed sibling (config → actions) finds it
    // already back home; the parentNode check covers any anchor that's mid-move.
    const moves = [
        ['project-player-row', 'sheet-slot-player'],
        ['project-config-group', 'sheet-slot-config'],
        ['project-actions', 'sheet-slot-actions'],
        ['project-status-lines', 'sheet-slot-status'],
        ['project-status', 'sheet-slot-pill'],
        ['project-overflow-wrap', 'sheet-slot-menu'],
    ].flatMap(([id, slotId]) => {
        const el = document.getElementById(id);
        const slot = document.getElementById(slotId);
        return el && slot ? [{ el, slot, home: el.parentNode, next: el.nextSibling }] : [];
    });
    const mobile = window.matchMedia('(max-width: 639.98px)');
    const place = (m) => {
        if (m) moves.forEach(({ el, slot }) => slot.appendChild(el));
        else [...moves].reverse().forEach(({ el, home, next }) =>
            home.insertBefore(el, next && next.parentNode === home ? next : null));
    };

    // ---- Sheet open/close ------------------------------------------------------
    const dockHandle = document.getElementById('dock-handle');
    const sheetHandle = document.getElementById('sheet-handle');
    const isOpen = () => sheet.classList.contains('is-open');
    const open = () => {
        sheet.classList.add('is-open');
        scrim.classList.add('is-open');
        dock.classList.add('is-tucked'); // the sheet replaces the dock
        dockHandle.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        sheetHandle.focus();
    };
    const close = ({ refocus = true } = {}) => {
        sheet.classList.remove('is-open');
        scrim.classList.remove('is-open');
        dock.classList.remove('is-tucked');
        dockHandle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (refocus) dockHandle.focus();
    };
    dockHandle.addEventListener('click', () => (isOpen() ? close() : open()));
    sheetHandle.addEventListener('click', () => close());
    scrim.addEventListener('click', () => close({ refocus: false }));
    document.addEventListener('keydown', (e) => {
        if (!isOpen()) return;
        if (e.key === 'Escape') {
            close();
        } else if (e.key === 'Tab') {
            // Keep focus cycling inside the sheet while it's modal (same idiom
            // as the mobile nav sheet). display:none controls drop out via the
            // offsetParent check.
            const items = [...sheet.querySelectorAll('a[href], button:not([disabled]), select, input, textarea, [tabindex]:not([tabindex="-1"])')]
                .filter((el) => el.offsetParent !== null);
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
    // Swipe: up anywhere on the dock expands; down on the sheet's handle
    // collapses. Touch-only nicety — the handle taps are the accessible path.
    // Swipe-down is scoped to the handle so scrolling the sheet's own content
    // (overflow-y) never dismisses it.
    const swipe = (el, dir, act) => {
        let y0 = null;
        el.addEventListener('touchstart', (e) => { y0 = e.touches[0].clientY; }, { passive: true });
        el.addEventListener('touchmove', (e) => {
            if (y0 === null) return;
            const dy = e.touches[0].clientY - y0;
            if (dir === 'up' ? dy < -24 : dy > 24) { y0 = null; act(); }
        }, { passive: true });
        el.addEventListener('touchend', () => { y0 = null; }, { passive: true });
    };
    swipe(dock, 'up', open);
    swipe(sheetHandle, 'down', () => close({ refocus: false }));

    // ---- Transport: the dock drives the ONE final-audio element ---------------
    // Same element the hero player (now living in the sheet) wraps, so playing
    // state persists across dock ↔ sheet and the document-level one-player-at-
    // a-time rule applies unchanged; a playing chunk shows the dock ▶ paused.
    const dockPlayer = document.getElementById('dock-player');
    const playBtn = document.getElementById('dock-play');
    const track = document.getElementById('dock-track');
    const fill = document.getElementById('dock-fill');
    const time = document.getElementById('dock-time');
    const fmt = (s) => (isFinite(s) && s >= 0)
        ? Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0')
        : '0:00';
    const syncTransport = () => {
        const d = audio.duration || 0;
        const ct = audio.currentTime || 0;
        fill.style.width = (d ? (ct / d) * 100 : 0) + '%';
        time.textContent = fmt(ct) + ' / ' + fmt(d);
    };
    if (audio && dockPlayer) {
        ['timeupdate', 'loadedmetadata', 'durationchange', 'emptied'].forEach((ev) =>
            audio.addEventListener(ev, syncTransport));
        audio.addEventListener('play', () => dockPlayer.classList.add('is-playing'));
        ['pause', 'ended'].forEach((ev) => audio.addEventListener(ev, () =>
            dockPlayer.classList.remove('is-playing', 'is-loading')));
        audio.addEventListener('waiting', () => dockPlayer.classList.add('is-loading'));
        audio.addEventListener('playing', () => dockPlayer.classList.remove('is-loading'));
        audio.addEventListener('error', () => {
            dockPlayer.classList.remove('is-playing', 'is-loading');
            time.textContent = 'failed — tap ▶ to retry';
        });
        playBtn.addEventListener('click', () => {
            if (dockPlayer.classList.contains('is-idle')) return;
            // Re-arm after a failed fetch, same as enhanceStudioPlayers: play()
            // alone won't re-run resource selection after a media error.
            if (audio.error) audio.load();
            if (audio.paused) {
                if (audio.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) dockPlayer.classList.add('is-loading');
                audio.play().catch(() => dockPlayer.classList.remove('is-loading'));
            } else {
                audio.pause();
            }
        });
        track.addEventListener('click', (e) => {
            const r = track.getBoundingClientRect();
            if (audio.duration) audio.currentTime = ((e.clientX - r.left) / r.width) * audio.duration;
        });
        syncTransport();
    }

    // ---- Status row + primary mirror ------------------------------------------
    const dot = document.getElementById('dock-dot');
    const statusOut = document.getElementById('dock-status');
    const metaOut = document.getElementById('dock-meta');
    const primary = document.getElementById('dock-primary');
    const sheetTitle = document.getElementById('sheet-title');
    const sheetChunks = document.getElementById('sheet-chunks');
    const sheetSpend = document.getElementById('sheet-spend');
    const TONES = {
        ok: ['bg-ok', 'text-ok'],
        warn: ['bg-warn', 'text-warn'],
        idle: ['bg-zinc-500', 'text-zinc-400'],
        busy: ['bg-accent', 'text-zinc-300'],
    };
    const setTone = (tone) => {
        dot.className = 'h-1.5 w-1.5 shrink-0 rounded-full ' + TONES[tone][0];
        statusOut.className = 'truncate ' + TONES[tone][1];
    };
    const visible = (el) => !!el && !el.classList.contains('hidden');
    const syncDock = () => {
        const chunkCount = root.querySelectorAll('.studio-chunk').length;
        const spend = spendValue ? spendValue.textContent.trim() : '';
        const live = finalStatus ? finalStatus.textContent.trim() : '';
        const status = statusPill ? statusPill.textContent.trim() : '';
        if (live) {
            // A live message owns the line — run progress ("Creating clip N of
            // M"), download %, build results, errors — until setStatus clears it.
            setTone('busy');
            statusOut.textContent = live;
            metaOut.textContent = '';
        } else {
            setTone(status === 'ready' ? 'ok' : status === 'stale' ? 'warn' : 'idle');
            statusOut.textContent = status;
            metaOut.textContent = '· ' + chunkCount + ' chunks' + (spend ? ' · ' + spend : '');
        }
        // Header data mirrored into the sheet's own stats row.
        if (sheetChunks) sheetChunks.textContent = chunkCount;
        if (sheetSpend && spend) sheetSpend.textContent = spend;
        if (sheetTitle && titleLabel) sheetTitle.textContent = titleLabel.textContent;
        // No final audio yet → the transport has nothing to play.
        dockPlayer?.classList.toggle('is-idle', !visible(finalPlayer));
        // The primary proxies whichever download currently leads the action
        // cluster: the approved package once sealed, else the bare preview;
        // dimmed while neither is offered (chunks outstanding, run active).
        const lead = visible(receiptLink) ? receiptLink : (visible(downloadLink) ? downloadLink : null);
        primary.textContent = lead === receiptLink ? '⤓ Approved' : '↓ Preview';
        if (lead) primary.href = lead.href;
        primary.classList.toggle('opacity-40', !lead);
        primary.classList.toggle('pointer-events-none', !lead);
        primary.setAttribute('aria-disabled', lead ? 'false' : 'true');
        primary.tabIndex = lead ? 0 : -1;
    };
    // Route through the cluster's managed download so its staged progress and
    // errors land in the status line — which this dock mirrors right here.
    primary.addEventListener('click', (e) => {
        e.preventDefault();
        (visible(receiptLink) ? receiptLink : (visible(downloadLink) ? downloadLink : null))?.click();
    });

    // Everything the mirror reads is already kept current by initStudioProject —
    // watch exactly those nodes rather than re-implementing any state logic.
    const mo = new MutationObserver(syncDock);
    [statusPill, finalStatus, spendValue, titleLabel].forEach((n) =>
        n && mo.observe(n, { childList: true, characterData: true, subtree: true }));
    [downloadLink, receiptLink, finalPlayer].forEach((n) =>
        n && mo.observe(n, { attributes: true, attributeFilter: ['class'] }));
    const chunkList = root.querySelector('.studio-chunk')?.parentElement;
    if (chunkList) mo.observe(chunkList, { childList: true }); // delete-chunk updates the count

    // ---- Keyboard: a focused text field owns the bottom of the screen ---------
    // The on-screen keyboard covers the dock anyway; hide it so it can't hover
    // mid-screen on browsers that resize the viewport instead. Deferred restore
    // so focus hopping field → field doesn't flicker the dock in between.
    let kbTimer;
    const editsText = (el) => el instanceof Element
        && el.matches('textarea, input:not([type="checkbox"]):not([type="radio"])');
    document.addEventListener('focusin', (e) => {
        if (!mobile.matches || isOpen()) return;
        if (editsText(e.target)) {
            clearTimeout(kbTimer);
            dock.classList.add('is-tucked');
        }
    });
    document.addEventListener('focusout', () => {
        if (isOpen()) return;
        clearTimeout(kbTimer);
        kbTimer = setTimeout(() => {
            if (!editsText(document.activeElement)) dock.classList.remove('is-tucked');
        }, 120);
    });

    // ---- The breakpoint owns which placement is live ---------------------------
    mobile.addEventListener('change', (e) => {
        if (!e.matches && isOpen()) close({ refocus: false });
        place(e.matches);
        syncDock();
    });
    place(mobile.matches);
    syncDock();
}
initStudioMobileDock();

// ---- Studio project "Revise text" page --------------------------------------
// Paste the updated manuscript, preview the chunk-level diff (AJAX), then the
// plain form POST applies it. The preview is advisory — the server recomputes
// the plan on apply — so this only has to render honestly, not stay in sync.
function initReviseText() {
    const root = document.getElementById('revise-root');
    if (!root) return;

    const textarea = document.getElementById('revise-text');
    const previewBtn = document.getElementById('revise-preview');
    const results = document.getElementById('revise-results');
    const status = document.getElementById('revise-status');

    const KINDS = {
        update: ['updated', 'border-amber-500/30 bg-amber-500/10 text-amber-300'],
        insert: ['new', 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'],
        delete: ['removed', 'border-red-500/30 bg-red-500/10 text-red-300'],
        moved: ['moved', 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300'],
        break: ['pause change', 'border-zinc-600 bg-zinc-800/60 text-zinc-300'],
    };

    const row = (kind, text, oldText) => {
        const el = document.createElement('div');
        el.className = 'rounded-lg border border-zinc-800 bg-zinc-950/60 p-3 text-sm';
        const [label, badgeClasses] = KINDS[kind];
        const badge = document.createElement('span');
        badge.className = `mb-1.5 inline-flex rounded-md border px-2 py-0.5 text-xs ${badgeClasses}`;
        badge.textContent = label;
        el.append(badge);
        if (oldText && oldText !== text) {
            const old = document.createElement('p');
            old.className = 'text-zinc-500 line-through';
            old.textContent = oldText;
            el.append(old);
        }
        if (kind !== 'delete') {
            const now = document.createElement('p');
            now.className = 'text-zinc-200';
            now.textContent = text;
            el.append(now);
        }
        return el;
    };

    async function preview() {
        startBusy(previewBtn, 'Previewing…');
        setStatus(status, '');
        try {
            const res = await fetch(root.dataset.previewUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ text: textarea.value }),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            render(await res.json());
        } catch (err) {
            setStatus(status, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(previewBtn);
        }
    }

    function render(data) {
        results.replaceChildren();
        results.classList.remove('hidden', 'opacity-50');

        const c = data.counts;
        const unchanged = c.keep - c.moved - c.break_only;
        const summary = document.createElement('div');
        summary.className = 'rounded-lg border border-zinc-800 bg-zinc-900/70 px-4 py-3 text-sm text-zinc-200';
        summary.textContent = data.changed
            ? [
                `${unchanged} unchanged`,
                c.update && `${c.update} updated`,
                c.insert && `${c.insert} new`,
                c.delete && `${c.delete} removed`,
                c.moved && `${c.moved} moved`,
                c.break_only && `${c.break_only} pause-only`,
            ].filter(Boolean).join(' · ')
            : '✓ No changes — the project already matches this text.';
        results.append(summary);

        // Every change came from the pipeline, not the paste: say so, or the
        // dictionary-repair flow reads as a bug ("I didn't edit anything!").
        if (data.pipeline_only && data.changed) {
            const note = document.createElement('div');
            note.className = 'rounded-lg border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-sm text-cyan-300';
            note.textContent = 'You didn’t change the text — these updates come from your pronunciation dictionary or text settings.';
            results.append(note);
        }

        (data.changes || []).forEach((ch) => results.append(row(ch.kind, ch.text, ch.old_text)));
        (data.deletes || []).forEach((d) => results.append(row('delete', null, d.text)));
    }

    previewBtn.addEventListener('click', preview);
    // A preview describes the text it was computed from — dim it the moment
    // the textarea moves on, so stale results never read as current.
    textarea.addEventListener('input', () => {
        if (!results.classList.contains('hidden')) {
            results.classList.add('opacity-50');
            setStatus(status, 'Text changed — preview again for a current diff.');
        }
    });
}

initReviseText();

// Skin every statically-rendered player on the page (voices table, health,
// the Studio inspector) — enhancing is idempotent, so pages that already
// enhance their own scope are unaffected. Dynamically-built players are
// enhanced where they're created.
enhanceStudioPlayers();

// Only one audio player at a time: when any <audio> starts, pause the others and
// mark it `is-playing` (CSS keeps the active player bright and dims the rest, so
// it's obvious which clip is sounding). Studio auto-plays each chunk/seam/final
// as it finishes, which otherwise leads to several clips overlapping. Media
// play/pause/ended events don't bubble, so listen in the capture phase to catch
// them from any (including dynamically-added) player.
document.addEventListener('play', (e) => {
    document.querySelectorAll('audio').forEach((audio) => {
        if (audio !== e.target) audio.pause();
        audio.classList.toggle('is-playing', audio === e.target);
    });
}, true);
document.addEventListener('pause', (e) => e.target.classList.remove('is-playing'), true);
document.addEventListener('ended', (e) => e.target.classList.remove('is-playing'), true);

// Genblaze: one POST kicks off the whole orchestrated run (generate → QA-gated
// re-roll → stitch → B2) and renders the per-chunk provenance it returns.
function initGenblaze() {
    const root = document.getElementById('genblaze');
    if (!root) return;
    const runBtn = document.getElementById('gb-run');
    if (!runBtn) return;

    const statusEl = document.getElementById('gb-status');
    const result = document.getElementById('gb-result');
    const finalAudio = document.getElementById('gb-final-audio');
    const finalPlayer = document.getElementById('gb-final-player');
    const finalUrl = document.getElementById('gb-final-url');
    const manifestEl = document.getElementById('gb-manifest');
    const rerollsEl = document.getElementById('gb-rerolls');
    const chunksEl = document.getElementById('gb-chunks');

    enhanceStudioPlayers(root); // skin the final-audio player (same hero transport as Studio)

    const el = (tag, cls, text) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text != null) node.textContent = text;
        return node;
    };
    const pill = (cls, text) => el('span', `ml-2 inline-flex rounded-md border px-1.5 py-0.5 text-xs ${cls}`, text);
    const httpOnly = (url) => (/^https?:\/\//.test(url || '') ? url : '#');

    const render = (data) => {
        rerollsEl.textContent = `${data.reroll_count ?? 0} re-roll(s)`;
        // Play through the app-proxied URL (works on a private B2 bucket); still
        // show the real B2 location as the provenance link.
        const finalSrc = data.final_play_url || data.final_url;
        if (finalSrc) {
            finalAudio.src = httpOnly(finalSrc);
            finalPlayer.classList.remove('hidden');
        }
        if (data.final_url) {
            finalUrl.textContent = data.final_url;
            finalUrl.href = httpOnly(data.final_play_url || data.final_url);
        }
        manifestEl.textContent = data.final_manifest_hash || '—';
        // Genblaze's SHA-256 provenance check (manifest.verify()): green when the
        // final asset verifies against its manifest, red if it doesn't, hidden when
        // the runner couldn't compute it. Set className wholesale each render so a
        // re-run can't leave a stale colour — and never co-place `hidden` with a
        // display utility (they fight; hidden can lose).
        const verifiedEl = document.getElementById('gb-manifest-verified');
        if (verifiedEl) {
            const base = 'ml-2 rounded-md border px-1.5 py-0.5 text-xs';
            if (data.final_manifest_verified === true) {
                verifiedEl.className = `${base} inline border-emerald-500/30 bg-emerald-500/10 text-emerald-300`;
                verifiedEl.textContent = '✓ verified · SHA-256';
            } else if (data.final_manifest_verified === false) {
                verifiedEl.className = `${base} inline border-red-500/30 bg-red-500/10 text-red-300`;
                verifiedEl.textContent = '✗ verification failed';
            } else {
                verifiedEl.className = `${base} hidden`;
                verifiedEl.textContent = '';
            }
        }

        // The sealed final deliverable — download via the app proxy (works on a
        // private bucket) with a friendly, hash-stamped filename.
        const dl = document.getElementById('gb-download');
        if (dl) {
            const src = data.final_play_url || data.final_url;
            if (src) {
                dl.href = data.final_play_url
                    ? data.final_play_url + (data.final_play_url.includes('?') ? '&' : '?') + 'download=1'
                    : data.final_url;
                const hash8 = (data.final_manifest_hash || '').slice(0, 8);
                const ext = (data.final_url || '').toLowerCase().endsWith('.wav') ? 'wav' : 'mp3';
                dl.setAttribute('download', `alias-sealed-final${hash8 ? '-' + hash8 : ''}.${ext}`);
                dl.className = 'mt-2 inline-flex items-center gap-1.5 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-300 hover:bg-emerald-500/20';
            } else {
                dl.className = 'hidden';
                dl.removeAttribute('href');
            }
        }

        chunksEl.replaceChildren();
        (data.chunks || []).forEach((c) => {
            const attempts = c.attempts ?? 1;
            const score = c.verdict && typeof c.verdict.score === 'number' ? c.verdict.score.toFixed(2) : null;
            const problems = c.verdict && Array.isArray(c.verdict.problems) ? c.verdict.problems.filter((p) => p && p !== '-') : [];

            const li = el('li', 'rounded-lg border border-zinc-800 bg-zinc-950/40 p-3');
            const head = el('div', 'flex items-center justify-between gap-2 text-sm');
            const title = el('span', 'font-medium text-zinc-200', `Chunk ${c.position ?? '?'}`);
            if (attempts > 1) title.append(pill('border-amber-500/30 bg-amber-500/10 text-amber-300', `re-rolled ×${attempts - 1}`));
            if (c.trim_applied) title.append(pill('border-sky-500/30 bg-sky-500/10 text-sky-300', 'trimmed'));
            head.append(title, el('span', 'text-xs text-zinc-500', `${attempts} attempt(s)${score !== null ? ' · score ' + score : ''}`));
            li.append(head);

            if (problems.length) li.append(el('div', 'mt-1 text-xs text-red-300', problems.join(', ')));

            const link = el('a', 'mt-1 block break-all font-mono text-xs text-cyan-400 hover:underline', c.audio_url || '');
            link.target = '_blank';
            link.rel = 'noopener';
            link.href = httpOnly(c.play_url || c.audio_url);
            li.append(link);

            chunksEl.append(li);
        });
        result.classList.remove('hidden');
    };

    // Poll the run's status URL until it completes or fails. The orchestration
    // runs in a queued job, so this can take minutes without any HTTP timeout.
    const POLL_MS = 1200; // tight-ish so the live checklist advances smoothly
    const POLL_MAX_MS = 10 * 60 * 1000;
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    // Pipeline walkthrough. The runner reports no mid-run progress (one blocking
    // call), so we can't animate the steps live; instead, once the real run
    // completes we reveal each step one-by-one at a readable pace from the actual
    // provenance — the "Queued/Generating" flash was too fast for a judge to read.
    const STEP_KEYS = ['pronounce', 'chunk', 'generate', 'stitch', 'seal', 'upload'];
    const DOT_BASE = 'mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium';
    const stepEl = (key) => root.querySelector(`[data-gb-step="${key}"]`);

    const setStep = (key, state, detail) => {
        const li = stepEl(key);
        if (!li) return;
        const dot = li.querySelector('[data-gb-dot]');
        const detailEl = li.querySelector('[data-gb-detail]');
        if (state === 'done') {
            dot.className = `${DOT_BASE} border-emerald-500/40 bg-emerald-500/10 text-emerald-300`;
            dot.textContent = '✓';
        } else if (state === 'active') {
            dot.className = `${DOT_BASE} border-cyan-500/50 bg-cyan-500/10 text-cyan-300 animate-pulse`;
            dot.textContent = String(STEP_KEYS.indexOf(key) + 1);
        } else if (state === 'skip') {
            dot.className = `${DOT_BASE} border-zinc-700 bg-zinc-800 text-zinc-500`;
            dot.textContent = '–';
        } else {
            dot.className = `${DOT_BASE} border-zinc-700 text-zinc-500`;
            dot.textContent = String(STEP_KEYS.indexOf(key) + 1);
        }
        if (detail) {
            detailEl.textContent = detail;
            detailEl.classList.remove('hidden');
        } else {
            detailEl.textContent = '';
            detailEl.classList.add('hidden');
        }
    };

    const resetSteps = () => STEP_KEYS.forEach((k) => setStep(k, 'pending', ''));

    // Derive the ordered [key, state, detail] rows from a completed provenance payload.
    const stepsFrom = (data) => {
        const chunks = data.chunks || [];
        const p = data.pronunciation || {};
        const subs = Array.isArray(p.substitutions) ? p.substitutions : [];
        const trimmed = chunks.filter((c) => c.trim_applied).length;
        const hash = data.final_manifest_hash || '';
        const shortHash = hash ? `${hash.slice(0, 12)}…` : '';
        const finalName = (data.final_url || '').split('/').pop() || data.final_url || '';

        let pron;
        if (p.available === false) {
            pron = ['pronounce', 'skip', 'pronunciation service unavailable — text sent unchanged'];
        } else if (subs.length) {
            const list = subs.slice(0, 4).map((s) => `${s.term} → ${s.phonetic}`).join(', ');
            pron = ['pronounce', 'done', `${subs.length} term(s) respelled (${p.provider || 'llm'}): ${list}${subs.length > 4 ? ', …' : ''}`];
        } else {
            pron = ['pronounce', 'done', `no changes needed${p.provider ? ' · ' + p.provider : ''}`];
        }

        const seal = data.final_manifest_verified === true
            ? `SHA-256 verified${shortHash ? ' · ' + shortHash : ''}`
            : data.final_manifest_verified === false
                ? 'verification failed'
                : (shortHash ? `manifest ${shortHash}` : 'manifest sealed');

        return [
            pron,
            // Single-chunk runs shouldn't leave this step silent — say so explicitly.
            // Wording mirrors the runner's live 'chunk' ping (orchestrator.py) so the
            // detail doesn't flicker when finalizeSteps snaps to the provenance.
            ['chunk', 'done', chunks.length <= 1
                ? 'no chunking needed — short enough for a single segment'
                : `split into ${chunks.length} segments`],
            ['generate', 'done', `${chunks.length} chunk(s) · ${data.reroll_count ?? 0} re-roll(s)${trimmed ? ' · ' + trimmed + ' trimmed' : ''}`],
            ['stitch', 'done', `joined ${chunks.length} chunk(s)`],
            ['seal', 'done', seal],
            ['upload', 'done', finalName],
        ];
    };

    // A token guards against an in-flight reveal being clobbered by a Replay (or a
    // fresh run) started mid-walk.
    let revealToken = 0;
    const REVEAL_MS = 550;
    const revealSteps = async (data) => {
        const mine = ++revealToken;
        resetSteps();
        for (const [key, state, detail] of stepsFrom(data)) {
            await sleep(REVEAL_MS);
            if (mine !== revealToken) return; // superseded by a newer reveal
            setStep(key, state, detail);
        }
    };

    // Drive the checklist LIVE from the runner's progress pings while the run is
    // in flight: every stage already entered is done, the current one active, the
    // rest pending. This is what makes the checks track the REAL pipeline rather
    // than replay after the fact.
    const applyProgress = (events) => {
        const detailByStep = {};
        let current = null;
        (events || []).forEach((e) => {
            if (e && e.step && STEP_KEYS.includes(e.step)) {
                current = e.step;
                if (e.detail) detailByStep[e.step] = e.detail;
            }
        });
        if (!current) current = STEP_KEYS[0]; // nothing reported yet → first step active
        const curIdx = STEP_KEYS.indexOf(current);
        STEP_KEYS.forEach((k, i) => {
            if (i < curIdx) setStep(k, 'done', detailByStep[k] || '');
            else if (i === curIdx) setStep(k, 'active', detailByStep[k] || '');
            else setStep(k, 'pending', '');
        });
    };

    // On completion, snap every step to its authoritative final state/detail from
    // the provenance (exact respellings, scores, hash) — no animation, they were
    // already lit live during the run.
    const finalizeSteps = (data) => { for (const [k, s, d] of stepsFrom(data)) setStep(k, s, d); };

    let lastData = null;
    const replayBtn = document.getElementById('gb-replay');
    if (replayBtn) replayBtn.addEventListener('click', () => { if (lastData) revealSteps(lastData); });

    const poll = async (statusUrl, t0) => {
        for (;;) {
            if (Date.now() - t0 > POLL_MAX_MS) throw new Error('Timed out waiting for the run.');
            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error(await errorMessage(res));
            const body = await res.json();
            if (body.status === 'completed') return body.result || {};
            if (body.status === 'failed') throw new Error(body.error || 'The run failed.');
            applyProgress(body.progress);
            const secs = Math.round((Date.now() - t0) / 1000);
            setStatus(statusEl, `⏳ ${body.status === 'running' ? 'Generating' : 'Queued'} — ${secs}s elapsed…`, 'pending');
            await sleep(POLL_MS);
        }
    };

    runBtn.addEventListener('click', async () => {
        const text = document.getElementById('gb-text').value.trim();
        const voice = document.getElementById('gb-voice').value;
        if (!text) { setStatus(statusEl, 'Enter some text first.', 'error'); return; }

        runBtn.disabled = true;
        result.classList.add('hidden');
        revealToken++; // cancel any in-flight reveal from a prior run/replay
        resetSteps();
        if (replayBtn) replayBtn.classList.add('hidden');
        const t0 = Date.now();
        setStatus(statusEl, '⏳ Queued — generate → QA → re-roll → stitch → B2…', 'pending');
        try {
            const res = await fetch(root.dataset.runUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ text, voice }),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const { status_url: statusUrl } = await res.json();
            const data = await poll(statusUrl, t0);
            render(data);
            lastData = data;
            if (replayBtn) replayBtn.classList.remove('hidden');
            finalizeSteps(data);
            const secs = Math.round((Date.now() - t0) / 1000);
            setStatus(statusEl, `✓ Done in ${secs}s — ${data.reroll_count || 0} re-roll(s) across ${(data.chunks || []).length} chunk(s).`, 'ok');
        } catch (err) {
            setStatus(statusEl, `✗ ${err.message}`, 'error');
        } finally {
            runBtn.disabled = false;
        }
    });
}
initGenblaze();

// New-project form: creating a project runs a (potentially ~minute-long) LLM
// pronunciation check before the text is chunked. To keep that check skippable,
// JS drives it as an abortable fetch to `detect` (which creates nothing) instead
// of a blocking full-page POST:
//   • suggestions found → hand off to `review` with the check's one-shot token
//     (the server renders the review screen without re-running the check);
//   • nothing to review → hand off to `store` (create + chunk, no review);
//   • Skip clicked → abort the check and hand off to `store` — chunks intact,
//     existing dictionary applied, no LLM gate. The aborted check made nothing
//     server-side, so this can't duplicate the project.
// With JS off the form falls back to its plain POST to `review` (unchanged).
function initCreateProjectForm() {
    const form = document.getElementById('create-project-form');
    if (!form) return;

    const btn = form.querySelector('button[type=submit]');
    const status = document.getElementById('create-project-status');
    const skipBtn = document.getElementById('skip-pronunciation');
    const cancelLink = document.getElementById('create-project-cancel');
    const detectUrl = form.dataset.detectUrl;
    const reviewUrl = form.action; // the form's own action is the review route
    const storeUrl = form.dataset.storeUrl;

    let controller = null; // aborts the in-flight pronunciation check
    let navigating = false; // set once we hand off to a real full-page submit

    const reset = () => {
        controller = null;
        navigating = false;
        if (btn) endBusy(btn);
        setStatus(status, '');
        if (skipBtn) skipBtn.hidden = true;
        if (cancelLink) cancelLink.hidden = false;
    };

    // Full-page POST to `action` (with any extra hidden fields), which the server
    // answers with a redirect to the project or the review screen. form.submit()
    // doesn't fire the submit event, so the handler below never re-intercepts it;
    // the spinner clears when the browser navigates away.
    const navigateTo = (action, fields = {}) => {
        navigating = true;
        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        form.action = action;
        form.submit();
    };

    // Native `required` validation blocks submit before this fires, so reaching
    // here means the form is valid. Without the detect URL (or a hand-off already
    // in progress) let the plain POST through — that's the no-JS fallback.
    form.addEventListener('submit', async (e) => {
        if (navigating || !btn || !detectUrl) return;
        e.preventDefault();

        startBusy(btn, 'Checking pronunciations…');
        setStatus(status, 'This can take up to a minute for long articles — please keep this page open.');
        if (cancelLink) cancelLink.hidden = true;
        if (skipBtn) skipBtn.hidden = false;

        controller = new AbortController();
        try {
            const res = await fetch(detectUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.fromEntries(new FormData(form))),
                signal: controller.signal,
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            data.token
                ? navigateTo(reviewUrl, { detect_token: data.token })
                : navigateTo(storeUrl);
        } catch (err) {
            if (err.name === 'AbortError') return; // Skip owns the hand-off
            // The check itself failed (network/LLM) — fall back to the plain POST
            // so a hiccup in an optional step never blocks project creation.
            navigateTo(reviewUrl);
        }
    });

    if (skipBtn) {
        skipBtn.addEventListener('click', () => {
            setStatus(status, 'Skipping pronunciation check…');
            if (controller) controller.abort();
            navigateTo(storeUrl);
        });
    }

    // A hand-off is a full-page POST, so a successful submit navigates away and
    // the spinner clears on its own. But the back/forward cache can restore this
    // page mid-spin — reset it when that happens.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) reset();
    });
}
initCreateProjectForm();

// Health page: the diagnostic checks are slow (ffmpeg, storage probe, sidecar
// pings, deep provider/queue probes), so the page ships as a shell and fetches
// the rendered report fragment here — showing a "running diagnostics" indicator
// so the page never reads as frozen. The Run / Run-live buttons re-fetch the
// same fragment (delegated on the stable region, since each result swap replaces
// the buttons). See HealthController::results + admin/health/_report.blade.php.
function initHealthReport() {
    const region = document.querySelector('[data-health-report]');
    if (!region) return;
    const baseUrl = region.dataset.resultsUrl;

    const showLoading = (label) => {
        const box = document.createElement('div');
        box.className = 'flex items-center gap-3 rounded-xl border border-zinc-800 bg-zinc-900/50 p-5 text-sm text-zinc-400';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');
        box.append(spinnerSvg(), document.createTextNode(label));
        region.replaceChildren(box);
    };

    const showError = (message) => {
        const box = document.createElement('div');
        box.className = 'rounded-xl border border-red-500/30 bg-red-500/10 p-5 text-sm text-red-300';
        box.setAttribute('role', 'alert');
        box.append(document.createTextNode('✗ ' + message + ' '));
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.dataset.healthRun = '';
        retry.dataset.deep = '0';
        retry.className = 'font-medium underline';
        retry.textContent = 'Retry';
        box.append(retry);
        region.replaceChildren(box);
    };

    async function run(deep) {
        showLoading(deep
            ? 'Running live checks… validating the provider token, probing the queue worker, and testing the upload limit. This can take a few seconds.'
            : 'Running diagnostics… checking PHP, the database, ffmpeg, storage, the provider, the queue, and the scheduler.');
        try {
            const url = deep ? baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'deep=1' : baseUrl;
            const res = await fetch(url, { headers: { 'Accept': 'text/html' } });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            // Trusted HTML: our own same-origin, admin-only Blade fragment whose
            // every dynamic value is {{ }}-escaped server-side. It's a rendered
            // partial, so it must be injected as markup (not textContent).
            region.innerHTML = await res.text();
        } catch (err) {
            showError(`Couldn't run the diagnostics (${err.message}).`);
        }
    }

    region.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-health-run]');
        if (!btn) return;
        e.preventDefault();
        run(btn.dataset.deep === '1');
    });

    run(false); // kick off the initial run once the shell has painted
}
initHealthReport();

// Full-page POST forms whose server-side work is slow (ffmpeg-normalizing a new
// voice's reference clip, unpacking a voice import) read as frozen after submit.
// Mark such a form [data-busy]: this shows a spinner + label on its submit button
// (and an optional status line) until the browser navigates away. A generalized
// take on initCreateProjectForm for the one-off, two-stage new-project form.
function initBusyForms() {
    document.querySelectorAll('form[data-busy]').forEach((form) => {
        // A <button> with no explicit type is still the form's submit button, so
        // match both — the destructive action menus render bare <button>s.
        const btn = form.querySelector('button[type=submit], button:not([type])');
        const status = form.querySelector('[data-busy-status]');
        const reset = () => {
            if (btn) endBusy(btn);
            if (status) setStatus(status, '');
        };
        // Native validation blocks submit before this fires, so reaching here
        // means the form is valid and the (slow) request is on its way.
        form.addEventListener('submit', () => {
            // A data-confirm form fires submit twice: once to open the dialog
            // (the guard at the top of this file prevents it) and again after the
            // user confirms. Only spin on the confirmed pass — otherwise a cancel
            // leaves the button stuck mid-spin. The guard runs on document (bubble
            // phase), after this target-phase listener, so on the re-fire the
            // one-shot `confirmed` flag is already set when we look.
            if (form.dataset.confirm !== undefined && form.dataset.confirmed === undefined) return;
            // Same deal for the type-to-confirm delete guard.
            if (form.dataset.deleteUser !== undefined && form.dataset.confirmed === undefined) return;
            if (btn) startBusy(btn, form.dataset.busyLabel || 'Working…');
            if (status && form.dataset.busyMessage) setStatus(status, form.dataset.busyMessage);
        });
        // bfcache can restore this page with the button stuck mid-spin — reset it.
        window.addEventListener('pageshow', (e) => {
            if (e.persisted) reset();
        });
    });
}
initBusyForms();

// initBusyForms' counterpart for plain links: a navigation that lands on (or
// leaves) a heavy page can sit on the old page for seconds with zero feedback —
// a dead-feeling click. Mark such links [data-busy]: clicking drops a
// full-screen veil (spinner + data-busy-label) that stays up until the next
// page paints over it. Delegated, so it also covers links in loops (the
// projects index rows).
function initBusyLinks() {
    const showVeil = (label) => {
        if (document.getElementById('nav-veil')) return;
        const veil = document.createElement('div');
        veil.id = 'nav-veil';
        veil.className = 'fixed inset-0 z-[60] flex cursor-wait items-center justify-center bg-zinc-950/90';
        const box = document.createElement('div');
        box.className = 'inline-flex items-center gap-2.5 text-sm font-medium text-zinc-200';
        box.setAttribute('role', 'status');
        box.append(spinnerSvg(), document.createTextNode(label));
        veil.append(box);
        document.body.append(veil);
    };
    const hideVeil = () => document.getElementById('nav-veil')?.remove();

    document.addEventListener('click', (e) => {
        const link = e.target instanceof Element ? e.target.closest('a[data-busy]') : null;
        // Guards that leave: e.defaultPrevented covers the Studio unsaved-edits
        // interceptor (registered earlier, so it has already run); the modifier/
        // target/download checks cover clicks that don't navigate this tab.
        if (!link || e.defaultPrevented) return;
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (link.target === '_blank' || link.hasAttribute('download')) return;
        showVeil(link.dataset.busyLabel || 'Loading…');
    });
    // The page can outlive the click: Escape cancels an in-flight navigation,
    // bfcache restores the old page veil-and-all on Back. Clear it for both.
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideVeil(); });
    window.addEventListener('pageshow', (e) => { if (e.persisted) hideVeil(); });
}
initBusyLinks();

// Global-nav account menu: the avatar pill toggles a dropdown; dismiss on outside
// click or Escape. The menu root is display:block so toggling `hidden` is safe
// (no co-present flex to fight — see the hidden/display-conflict gotcha).
function initAccountMenu() {
    const pill = document.getElementById('account-pill');
    const menu = document.getElementById('account-menu');
    if (!pill || !menu) return;

    const isOpen = () => !menu.classList.contains('hidden');
    const close = () => {
        menu.classList.add('hidden');
        pill.setAttribute('aria-expanded', 'false');
    };
    const open = () => {
        menu.classList.remove('hidden');
        pill.setAttribute('aria-expanded', 'true');
    };

    pill.addEventListener('click', (e) => {
        e.stopPropagation();
        isOpen() ? close() : open();
    });
    document.addEventListener('click', (e) => {
        if (isOpen() && !menu.contains(e.target) && !pill.contains(e.target)) close();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen()) close();
    });
}
initAccountMenu();

// Mobile global nav: the labelled "Menu" button opens a full-screen sheet
// (Option 6C). Close on ✕, Escape, or crossing to desktop; trap focus and lock
// body scroll while open. Visibility/transition live in CSS (#mobile-nav-sheet);
// here we only toggle `.is-open` and manage focus/scroll.
function initMobileNav() {
    const btn = document.getElementById('mobile-menu-button');
    const sheet = document.getElementById('mobile-nav-sheet');
    const closeBtn = document.getElementById('mobile-menu-close');
    if (!btn || !sheet) return;

    const desktop = window.matchMedia('(min-width: 768px)');
    const focusable = () =>
        [...sheet.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')];
    const isOpen = () => sheet.classList.contains('is-open');

    const open = () => {
        sheet.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        (closeBtn || focusable()[0])?.focus();
    };
    const close = ({ refocus = true } = {}) => {
        sheet.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (refocus) btn.focus();
    };

    btn.addEventListener('click', () => (isOpen() ? close() : open()));
    closeBtn?.addEventListener('click', () => close());
    document.addEventListener('keydown', (e) => {
        if (!isOpen()) return;
        if (e.key === 'Escape') {
            close();
        } else if (e.key === 'Tab') {
            const items = focusable();
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
    // Resizing up to desktop hides the sheet via CSS — unlock scroll to match.
    desktop.addEventListener('change', (e) => {
        if (e.matches && isOpen()) close({ refocus: false });
    });
}
initMobileNav();

// Voices page: "Add voice" split-button. The main segment links straight to the
// New voice screen; the caret opens a menu whose second item ("Import a voice
// file…") drives a hidden file input + form — picking a file submits the import
// immediately, with the split button marked busy for the slow server-side unzip.
function initVoicesAddMenu() {
    const main = document.getElementById('add-voice-main');
    const caret = document.getElementById('add-voice-caret');
    const menu = document.getElementById('add-voice-menu');
    if (!main || !caret || !menu) return;

    const items = [...menu.querySelectorAll('[role=menuitem]')];
    const isOpen = () => !menu.classList.contains('hidden');
    const close = (refocus = false) => {
        menu.classList.add('hidden');
        caret.setAttribute('aria-expanded', 'false');
        if (refocus) caret.focus();
    };
    const open = () => {
        menu.classList.remove('hidden');
        caret.setAttribute('aria-expanded', 'true');
        items[0]?.focus();
    };

    caret.addEventListener('click', (e) => {
        e.stopPropagation();
        isOpen() ? close() : open();
    });
    document.addEventListener('click', (e) => {
        if (isOpen() && !menu.contains(e.target)) close();
    });
    document.addEventListener('keydown', (e) => {
        if (!isOpen()) return;
        if (e.key === 'Escape') {
            close(true);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const idx = items.indexOf(document.activeElement);
            items[Math.min(Math.max(idx + (e.key === 'ArrowDown' ? 1 : -1), 0), items.length - 1)]?.focus();
        }
    });

    const importBtn = document.getElementById('add-voice-import');
    const form = document.getElementById('voice-import-form');
    const file = document.getElementById('voice-import-file');
    if (!importBtn || !form || !file) return;

    importBtn.addEventListener('click', () => {
        close();
        file.click();
    });
    file.addEventListener('change', () => {
        if (!file.files.length) return;
        startBusy(main, 'Importing…');
        startBusy(caret, '▾');
        form.submit();
    });
    // bfcache can restore this page with the button stuck mid-import — reset it.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            endBusy(main);
            endBusy(caret);
            file.value = '';
        }
    });
}
initVoicesAddMenu();

// Account screen: "Change photo" opens the file picker and auto-submits on pick;
// "Change password" and "Delete account" reveal their inline forms.
function initAccount() {
    const changeBtn = document.getElementById('avatar-change');
    const fileInput = document.getElementById('avatar-input');
    const avatarForm = document.getElementById('avatar-form');
    if (changeBtn && fileInput && avatarForm) {
        // Instant feedback only — the server re-validates, re-encodes, and downsamples.
        const errorEl = document.getElementById('avatar-error');
        const MAX_BYTES = 4 * 1024 * 1024;
        const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
        const showError = (msg) => {
            if (errorEl) { errorEl.textContent = msg; errorEl.classList.remove('hidden'); }
        };
        const clearError = () => errorEl && errorEl.classList.add('hidden');

        changeBtn.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            clearError();
            const file = fileInput.files[0];
            if (!file) return;
            // Trust an explicit disallowed type; let an empty/unknown type fall through to the server.
            if (file.type && !ALLOWED.includes(file.type)) {
                showError('Please choose a JPG, PNG, or WebP image.');
                fileInput.value = '';
                return;
            }
            if (file.size > MAX_BYTES) {
                showError('That image is over 4 MB. Please choose a smaller file.');
                fileInput.value = '';
                return;
            }
            // Re-encoding + the B2 upload run server-side before the redirect, so
            // spin the trigger instead of leaving a dead page. form.submit() skips
            // the submit event (so data-busy/initBusyForms can't see it); drive the
            // spinner here. It clears on navigation; bfcache restore is handled below.
            startBusy(changeBtn, 'Uploading…');
            avatarForm.submit();
        });
        // bfcache can restore this page with the button stuck mid-spin — reset it.
        window.addEventListener('pageshow', (e) => {
            if (e.persisted && changeBtn.dataset.busy) endBusy(changeBtn);
        });
    }

    const wireReveal = (toggleId, panelId, cancelId) => {
        const toggle = document.getElementById(toggleId);
        const panel = document.getElementById(panelId);
        if (!toggle || !panel) return;
        toggle.addEventListener('click', () => panel.classList.toggle('hidden'));
        const cancel = cancelId && document.getElementById(cancelId);
        if (cancel) cancel.addEventListener('click', () => panel.classList.add('hidden'));
    };
    wireReveal('password-toggle', 'password-form', 'password-cancel');
    wireReveal('danger-toggle', 'danger-confirm', 'danger-cancel');
    wireReveal('twofa-manage-toggle', 'twofa-manage');

    // Connect-SSO buttons reveal a per-provider password form (connecting is gated).
    document.querySelectorAll('.connect-toggle').forEach((btn) => {
        const target = document.getElementById(btn.dataset.target);
        if (target) btn.addEventListener('click', () => target.classList.toggle('hidden'));
    });
}
initAccount();

// Users admin: reveal the invite/create forms on the list page. The detail
// page's guards are declarative (data-confirm + data-delete-user above), and
// the reveal panel's Copy buttons ride the global delegated copy listener.
function initUsers() {
    const toggle = (btnId, panelId) => {
        const btn = document.getElementById(btnId);
        const panel = document.getElementById(panelId);
        if (btn && panel) btn.addEventListener('click', () => panel.classList.toggle('hidden'));
    };
    toggle('invite-toggle', 'invite-form');
    toggle('create-toggle', 'create-form');
}
initUsers();

/* ---------------------------------------------------------------------------
 * Voices page — drag rows to reorder. The order is per-user and drives every
 * voice dropdown; the first voice is what New Project pre-selects.
 * ------------------------------------------------------------------------ */
function initVoicesReorder() {
    const tbody = document.getElementById('voices-rows');
    if (!tbody || !tbody.dataset.orderUrl) return;

    const status = document.getElementById('voices-order-status');
    let dragging = null;

    const rows = () => [...tbody.querySelectorAll('tr[data-voice-id]')];

    // Accept the drag anywhere over the list — including over the dragged row
    // itself (where the cursor usually is, since the row live-moves under it)
    // and the gaps between rows. A release the browser doesn't consider a
    // valid drop plays the drop-cancel animation: the ghost flies back to the
    // row's OLD position even though the DOM order is already correct.
    tbody.addEventListener('dragover', (e) => {
        if (!dragging) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });
    tbody.addEventListener('drop', (e) => e.preventDefault());

    rows().forEach((row) => {
        const handle = row.querySelector('[data-drag-handle]');
        if (!handle) return;

        // Only grabbing the handle arms the row, so text selection and the
        // row's buttons keep working normally.
        handle.addEventListener('mousedown', () => { row.draggable = true; });
        row.addEventListener('dragstart', (e) => {
            dragging = row;
            e.dataTransfer.effectAllowed = 'move';
            row.classList.add('opacity-40');
        });
        row.addEventListener('dragover', (e) => {
            if (!dragging || dragging === row) return;
            const rect = row.getBoundingClientRect();
            const before = e.clientY < rect.top + rect.height / 2;
            tbody.insertBefore(dragging, before ? row : row.nextSibling);
        });
        row.addEventListener('dragend', () => {
            row.classList.remove('opacity-40');
            row.draggable = false;
            if (dragging === row) {
                dragging = null;
                saveOrder();
            }
        });
    });

    async function saveOrder() {
        if (status) status.textContent = 'Saving order…';
        try {
            const res = await fetch(tbody.dataset.orderUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ order: rows().map((r) => r.dataset.voiceId) }),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            if (status) {
                status.textContent = 'Order saved — voice dropdowns now follow it.';
                setTimeout(() => {
                    if (status.textContent.startsWith('Order saved')) status.textContent = '';
                }, 3000);
            }
        } catch (err) {
            if (status) status.textContent = `Couldn’t save the order (${err.message}) — refresh and try again.`;
        }
    }
}
initVoicesReorder();

// Voice reference-clip widget: a segmented Upload / Record panel that previews a
// clip's cleanup (denoise + enhance) and A/Bs "Original" vs "Cleaned up" before
// saving. The prepare endpoint stages both takes server-side and returns ranged
// URLs; choosing one submits its token with the form so the saved reference is
// byte-identical to the preview.
function initVoiceRecorder() {
    const widget = document.getElementById('voice-clip-widget');
    if (!widget) return;

    const prepareUrl = widget.dataset.prepareUrl;
    // Nothing to preview when cleanup is disabled — the plain upload still works.
    if (!prepareUrl || widget.dataset.enhanceEnabled !== '1') return;

    const q = (sel) => widget.querySelector(sel);
    const fileInput = q('[data-clip-file]');
    const enhanceBox = q('[data-clip-enhance]');
    const previewBtn = q('[data-clip-preview]');
    const rerecordBtn = q('[data-clip-rerecord]');
    const rerecordRow = q('[data-clip-rerecord-row]');
    const panel = q('[data-clip-panel]');
    const abPanel = q('[data-clip-ab]');
    const warningEl = q('[data-clip-ab-warning]');
    const statusEl = q('[data-clip-status]');
    const tokenInput = q('[data-clip-token]');
    const choices = widget.querySelectorAll('[data-clip-choice]');

    // A recording Blob from the mic recorder; null on the upload path.
    let recordedBlob = null;

    const nativeOf = (v) => widget.querySelector(`[data-clip-player="${v}"] .aplayer__native`);
    const rowOf = (v) => widget.querySelector(`[data-clip-row="${v}"]`);
    const hasSource = () => recordedBlob || (fileInput && fileInput.files && fileInput.files.length);

    // ── Segmented Upload / Record ──────────────────────────────────────────
    const modeButtons = widget.querySelectorAll('[data-clip-mode]');
    const bodyOf = (m) => widget.querySelector(`[data-clip-body="${m}"]`);
    const canRecord = !!(navigator.mediaDevices?.getUserMedia && window.MediaRecorder);

    function setMode(mode) {
        modeButtons.forEach((b) => {
            const active = b.dataset.clipMode === mode;
            b.classList.toggle('bg-accent', active);
            b.classList.toggle('text-accent-on', active);
            b.classList.toggle('font-semibold', active);
            b.classList.toggle('text-zinc-400', !active);
        });
        bodyOf('upload').classList.toggle('hidden', mode !== 'upload');
        bodyOf('record').classList.toggle('hidden', mode !== 'record');
    }
    modeButtons.forEach((b) => b.addEventListener('click', () => setMode(b.dataset.clipMode)));

    if (!canRecord) {
        // Non-secure context / unsupported browser: hide the segmented bar, upload only.
        const recBtn = widget.querySelector('[data-clip-mode="record"]');
        if (recBtn) recBtn.parentElement.classList.add('hidden');
    }
    // Record leads when the browser supports it: the common case on this page is
    // "I need to make a clip", not "I already have a file".
    setMode(canRecord ? 'record' : 'upload');

    function refreshPreviewBtn() {
        const show = !!hasSource() && enhanceBox && enhanceBox.checked && !tokenInput.value;
        previewBtn.classList.toggle('hidden', !show);
    }

    // The token is the staged-clip state; anything watching the form (the save
    // bar, the staged-clip row) learns about it only if the change is announced.
    const announceToken = () => tokenInput.dispatchEvent(new Event('change', { bubbles: true }));

    function clearPreview() {
        tokenInput.value = '';
        announceToken();
        choices.forEach((r) => { r.checked = false; });
        abPanel.classList.add('hidden');
        panel.classList.remove('hidden');
        if (warningEl) { warningEl.classList.add('hidden'); warningEl.textContent = ''; }
        if (fileInput) fileInput.disabled = false;
        setStatus(statusEl, '', 'muted');
        refreshPreviewBtn();
    }

    // opts.trigger: the button that kicked this off — busied so it can't double-fire.
    // opts.status: a status element beside that button (the recorder's), mirrored so
    // progress isn't only reported by the widget-footer line the eye has left.
    async function prepareClip(blob, filename, opts = {}) {
        const trigger = opts.trigger || previewBtn;
        const say = (msg, kind) => {
            setStatus(statusEl, msg, kind);
            if (opts.status && opts.status !== statusEl) setStatus(opts.status, msg, kind);
        };
        const enhancing = enhanceBox && enhanceBox.checked;
        say(enhancing ? 'Cleaning up your clip — this takes about a minute…' : 'Preparing your clip…', 'muted');
        startBusy(trigger, enhancing ? 'Cleaning up…' : 'Preparing…');
        try {
            const fd = new FormData();
            fd.append('audio', blob, filename);
            if (enhancing) fd.append('enhance', '1');
            const res = await fetch(prepareUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                body: fd,
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            let data = await res.json();
            // An over-long take is trimmed server-side at staging; only the FIRST
            // response knows, so carry the notice across the poll below.
            const notice = data.notice;
            // Cleanup now runs off the request in a queued job — the upload returns
            // right away as "processing" so a long clip can't hold the POST open
            // until a gateway 504s it. Poll the status URL until the take is staged.
            if (data.status === 'processing' && data.status_url) data = await pollClipReady(data.status_url);
            renderAB(data);
            say(notice || '', 'muted');
        } catch (err) {
            say(`✗ ${err.message}`, 'error');
        } finally {
            endBusy(trigger);
            refreshPreviewBtn();
        }
    }

    // Wait for the queued cleanup to finish. Degrade-safe server-side: a failed or
    // timed-out enhance still resolves to a ready clip (the original take + a
    // warning), so this only errors if the clip never becomes ready in time —
    // e.g. no queue worker is running.
    async function pollClipReady(statusUrl) {
        const DEADLINE_MS = 240000; // comfortably past the enhancer's own ceiling
        const INTERVAL_MS = 2500;
        const startedAt = performance.now();
        for (;;) {
            await new Promise((resolve) => setTimeout(resolve, INTERVAL_MS));
            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            if (data.status === 'ready') return data;
            if (performance.now() - startedAt > DEADLINE_MS) {
                throw new Error('Cleanup is taking longer than expected — please try again.');
            }
        }
    }

    function renderAB(data) {
        tokenInput.value = data.token || '';
        announceToken();

        const hasEnhanced = !!data.enhanced;
        rowOf('enhanced').classList.toggle('hidden', !hasEnhanced);
        if (hasEnhanced) nativeOf('enhanced').src = data.enhanced.url;
        nativeOf('original').src = data.original.url;

        // Preselect the cleaned-up take when present, else the original.
        const preferred = hasEnhanced ? 'enhanced' : 'original';
        choices.forEach((r) => { r.checked = r.value === preferred; });

        if (warningEl) {
            const warn = !hasEnhanced && data.enhance_error;
            warningEl.textContent = warn ? data.enhance_error : '';
            warningEl.classList.toggle('hidden', !warn);
        }

        enhanceStudioPlayers(widget);        // skin the A/B players now that src is set
        panel.classList.add('hidden');       // swap the Upload/Record panel for the chooser
        abPanel.classList.remove('hidden');
        previewBtn.classList.add('hidden');
        if (rerecordRow) { // mic takes can be re-recorded in place; hidden+flex toggled together (display-conflict gotcha)
            rerecordRow.classList.toggle('hidden', !recordedBlob);
            rerecordRow.classList.toggle('flex', !!recordedBlob);
        }
        if (fileInput) fileInput.disabled = true; // the token supersedes a raw upload
        announceToken(); // the panel swap changes which undo affordance applies
    }

    // Hook the mic recorder uses to route a recording through the same preview path.
    widget.__prepareRecording = (blob, filename, opts) => { recordedBlob = blob; return prepareClip(blob, filename, opts); };

    // The teleprompter's "We'll … after." sentence promises only what will run:
    // room-noise cleanup follows the enhance checkbox, loudness normalization is
    // skipped when Store raw is checked (mirrors VoiceController's normalize flag).
    const processingEl = q('[data-recorder-processing]');
    const rawBox = q('[data-clip-raw]');
    function refreshProcessingHint() {
        if (!processingEl) return;
        const parts = [];
        if (enhanceBox && enhanceBox.checked) parts.push('clean up room noise');
        if (widget.dataset.normalizeEnabled && !(rawBox && rawBox.checked)) parts.push('normalize loudness');
        processingEl.textContent = parts.length ? ` We'll ${parts.join(' and ')} after.` : '';
    }
    refreshProcessingHint();
    if (rawBox) rawBox.addEventListener('change', refreshProcessingHint);

    if (fileInput) fileInput.addEventListener('change', () => { recordedBlob = null; clearPreview(); });
    if (enhanceBox) enhanceBox.addEventListener('change', () => { refreshPreviewBtn(); refreshProcessingHint(); });
    previewBtn.addEventListener('click', () => {
        const file = fileInput && fileInput.files && fileInput.files[0];
        if (recordedBlob) prepareClip(recordedBlob, 'recording.webm');
        else if (file) prepareClip(file, file.name);
    });
    // Start over also clears the recorder's review of the discarded take —
    // without this it resurfaces showing a player for audio that's gone. The
    // mic stays released: Start over may be headed for the upload tab, and
    // grabbing the mic uninvited there would be noise.
    widget.querySelector('[data-clip-reset]')?.addEventListener('click', () => {
        recordedBlob = null;
        clearPreview();
        widget.__recorderReset?.();
    });

    // Reject the previewed mic take: back to the recorder, which re-arms the mic.
    rerecordBtn?.addEventListener('click', () => {
        recordedBlob = null;
        clearPreview();
        setMode('record');
        widget.__recorderRedo?.();
    });

    // ── Teleprompter: swap the passage per script + A−/A+ sizing (persisted) ──
    const passageEl = q('[data-recorder-passage]');
    const titleEl = q('[data-recorder-title]');
    const taglineEl = q('[data-recorder-tagline]');
    const SIZE_KEY = 'aliasTeleprompterSize';
    const sizes = [17, 20, 23, 27, 31];
    let sizeIdx = parseInt(localStorage.getItem(SIZE_KEY) ?? '2', 10);
    if (!Number.isFinite(sizeIdx)) sizeIdx = 2;
    sizeIdx = Math.max(0, Math.min(sizes.length - 1, sizeIdx));
    const applySize = () => { if (passageEl) passageEl.style.fontSize = sizes[sizeIdx] + 'px'; };
    applySize();
    widget.querySelectorAll('[data-recorder-size]').forEach((b) => b.addEventListener('click', () => {
        sizeIdx = Math.max(0, Math.min(sizes.length - 1, sizeIdx + Number(b.dataset.recorderSize)));
        try { localStorage.setItem(SIZE_KEY, String(sizeIdx)); } catch (_) {}
        applySize();
    }));
    widget.querySelectorAll('[data-recorder-script]').forEach((r) => r.addEventListener('change', () => {
        if (passageEl) passageEl.textContent = r.dataset.text;
        if (titleEl) titleEl.textContent = r.dataset.title;
        if (taglineEl) taglineEl.textContent = '— ' + r.dataset.tagline;
    }));

    if (canRecord) initVoiceMicRecorder(widget);

    refreshPreviewBtn();
}

// In-browser mic capture for the voice widget: getUserMedia + MediaRecorder with
// a live level meter and timed guidance, feeding the recording through the
// widget's preview path (widget.__prepareRecording). Only wired when supported.
function initVoiceMicRecorder(widget) {
    const q = (sel) => widget.querySelector(sel);
    const enableBtn = q('[data-recorder-enable]');
    const recordBtn = q('[data-recorder-record]');
    const stopBtn = q('[data-recorder-stop]');
    const redoBtn = q('[data-recorder-redo]');
    const hintEl = q('[data-recorder-hint]');
    const timerEl = q('[data-recorder-timer]');
    const meterWrap = q('[data-recorder-meter-wrap]');
    const meterBar = q('[data-recorder-meter]');
    const guideEl = q('[data-recorder-guide]');
    const reviewWrap = q('[data-recorder-review]');
    const reviewAudio = q('[data-recorder-player] .aplayer__native');
    const useBtn = q('[data-recorder-use]');
    const recStatus = q('[data-recorder-status]');
    const deviceSel = q('[data-recorder-device]');
    if (!enableBtn) return;

    const targetMin = Number(widget.dataset.targetMin) || 15;
    const targetMax = Number(widget.dataset.targetMax) || 30;
    const maxSeconds = Number(widget.dataset.maxSeconds) || 60;
    const DEVICE_KEY = 'aliasMicInput';

    let stream = null, audioCtx = null, analyser = null, micSource = null, meterRAF = 0;
    let mr = null, chunks = [], recBlob = null, startedAt = 0, timerId = 0;

    const show = (node, on) => node && node.classList.toggle('hidden', !on);
    // Icon buttons need inline-flex; toggle it WITH hidden so neither lingers.
    const showFlex = (node, on) => { node.classList.toggle('hidden', !on); node.classList.toggle('inline-flex', on); };
    const fmt = (s) => Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0');

    const pickMime = () => ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4']
        .find((t) => MediaRecorder.isTypeSupported(t)) || '';

    function updateMeter() {
        if (!analyser) return;
        const buf = new Uint8Array(analyser.fftSize);
        analyser.getByteTimeDomainData(buf);
        let sum = 0;
        for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; sum += v * v; }
        meterBar.style.width = Math.min(100, Math.round(Math.sqrt(sum / buf.length) * 240)) + '%';
        meterRAF = requestAnimationFrame(updateMeter);
    }

    function tick() {
        const s = (performance.now() - startedAt) / 1000;
        timerEl.textContent = fmt(s);
        if (s < targetMin) { guideEl.textContent = 'Keep reading…'; guideEl.className = 'mt-2 text-xs text-zinc-500'; }
        else if (s <= targetMax) { guideEl.textContent = '✓ Good length — stop when you finish the paragraph.'; guideEl.className = 'mt-2 text-xs text-emerald-400'; }
        else { guideEl.textContent = 'Getting long — you can stop any time.'; guideEl.className = 'mt-2 text-xs text-amber-400'; }
        if (s >= maxSeconds) stopRecording(`Stopped at ${maxSeconds}s — that's plenty.`);
    }

    // Capture constraints; a specific device can be requested, and the last-used
    // device is preferred on re-enable (`ideal` falls back cleanly if it's gone).
    function acquireStream(deviceId) {
        // Browser DSP off — resemble-enhance does the cleanup, and its
        // artifacts hurt cloning; AGC on for safe input levels.
        const audio = { echoCancellation: false, noiseSuppression: false, autoGainControl: true, channelCount: 1 };
        if (deviceId) audio.deviceId = { exact: deviceId };
        else if (localStorage.getItem(DEVICE_KEY)) audio.deviceId = { ideal: localStorage.getItem(DEVICE_KEY) };
        return navigator.mediaDevices.getUserMedia({ audio });
    }

    // track.stop() doesn't fire 'ended' — this only catches the device going away
    // under us (USB mic unplugged, Bluetooth dropout), never our own switches.
    function watchTrackEnd() {
        stream.getAudioTracks()[0]?.addEventListener('ended', onTrackEnded, { once: true });
    }
    function onTrackEnded() {
        if (mr && mr.state !== 'inactive') stopRecording('');
        if (stream) stream.getTracks().forEach((t) => t.stop());
        stream = null;
        cancelAnimationFrame(meterRAF);
        show(meterWrap, false); show(deviceSel, false);
        showFlex(recordBtn, false); showFlex(stopBtn, false);
        showFlex(enableBtn, true);
        setStatus(recStatus, '✗ The microphone was disconnected — enable it again to continue.', 'error');
    }

    // The in-page input picker doubles as the "which mic is hot" readout. It can
    // only be populated once permission is granted (labels are blank before), and
    // it's disabled while recording — MediaRecorder is bound to the live stream.
    async function refreshDeviceList() {
        if (!deviceSel || !navigator.mediaDevices.enumerateDevices) return;
        const inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'audioinput' && d.deviceId);
        const current = stream?.getAudioTracks()[0]?.getSettings?.().deviceId;
        deviceSel.replaceChildren();
        inputs.forEach((d, i) => deviceSel.add(new Option(d.label || `Microphone ${i + 1}`, d.deviceId, false, d.deviceId === current)));
        show(deviceSel, !!stream && inputs.length > 0);
    }

    // Swap the live stream to the picked device and re-point the level meter.
    // Firefox may re-confirm with its own prompt (permissions are per-device there).
    async function switchDevice() {
        setStatus(recStatus, 'Switching microphone…', 'muted');
        try {
            const next = await acquireStream(deviceSel.value);
            if (stream) stream.getTracks().forEach((t) => t.stop());
            stream = next;
            if (micSource) micSource.disconnect();
            micSource = audioCtx.createMediaStreamSource(stream);
            micSource.connect(analyser);
            watchTrackEnd();
            try { localStorage.setItem(DEVICE_KEY, deviceSel.value); } catch (_) {}
            setStatus(recStatus, 'Microphone ready — press Record and read the passage.', 'muted');
        } catch (err) {
            setStatus(recStatus, '✗ Could not switch microphones: ' + (err?.message || err), 'error');
        }
        refreshDeviceList(); // reflect what's actually live (snaps the pick back on failure)
    }

    async function enableMic() {
        setStatus(recStatus, 'Requesting microphone…', 'muted');
        try {
            stream = await acquireStream();
        } catch (err) {
            const denied = err && (err.name === 'NotAllowedError' || err.name === 'SecurityError');
            setStatus(recStatus, denied
                ? "✗ Microphone blocked — allow it in your browser's site settings, or upload a file instead."
                : '✗ Could not access the microphone: ' + (err?.message || err), 'error');
            return;
        }
        // AudioContext created on the user gesture (Safari requires it); reused
        // if the mic is re-enabled after a disconnect.
        if (!audioCtx || audioCtx.state === 'closed') audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') await audioCtx.resume();
        analyser = audioCtx.createAnalyser();
        analyser.fftSize = 512;
        micSource = audioCtx.createMediaStreamSource(stream);
        micSource.connect(analyser); // never to destination (feedback)
        watchTrackEnd();
        show(meterWrap, true);
        updateMeter();

        showFlex(enableBtn, false); // static inline-flex would beat `hidden` (display-conflict gotcha)
        show(hintEl, false);
        showFlex(recordBtn, true);
        refreshDeviceList();
        setStatus(recStatus, 'Microphone ready — press Record and read the passage.', 'muted');
    }

    function startRecording() {
        const mimeType = pickMime();
        recBlob = null; chunks = [];
        mr = mimeType ? new MediaRecorder(stream, { mimeType, audioBitsPerSecond: 128000 }) : new MediaRecorder(stream);
        mr.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
        mr.onstop = () => {
            recBlob = new Blob(chunks, { type: mr.mimeType || mimeType || 'audio/webm' });
            if (reviewAudio.src) URL.revokeObjectURL(reviewAudio.src);
            reviewAudio.src = URL.createObjectURL(recBlob);
            enhanceStudioPlayers(widget);
            show(reviewWrap, true);
            if ((performance.now() - startedAt) / 1000 < 10) {
                setStatus(recStatus, 'That was short — aim for 15–20s for a better clone. You can re-record.', 'muted');
            }
        };
        mr.start();
        startedAt = performance.now();
        timerId = setInterval(tick, 200);
        show(timerEl, true); show(guideEl, true); show(reviewWrap, false);
        showFlex(recordBtn, false); showFlex(stopBtn, true);
        if (deviceSel) deviceSel.disabled = true; // the recorder is bound to this stream
        setStatus(recStatus, '', 'muted');
    }

    function stopRecording(msg) {
        if (mr && mr.state !== 'inactive') mr.stop();
        clearInterval(timerId);
        // The pacing guide ("Keep reading…") is live-recording chrome — left up, it
        // reads as an instruction while the take sits in review.
        show(guideEl, false);
        showFlex(stopBtn, false); showFlex(recordBtn, false);
        if (deviceSel) deviceSel.disabled = false;
        if (msg) setStatus(recStatus, msg, 'muted');
    }

    function teardown() {
        cancelAnimationFrame(meterRAF);
        if (stream) stream.getTracks().forEach((t) => t.stop());
        if (audioCtx && audioCtx.state !== 'closed') audioCtx.close();
        stream = null; audioCtx = null; analyser = null;
    }

    // "Use this recording" commits the take — past that point a hot mic (the
    // tab's recording indicator, a dancing level meter, a device picker) reads
    // as leftover machinery, so let go of all of it.
    function releaseMic() {
        teardown();
        show(meterWrap, false); show(deviceSel, false); show(timerEl, false);
    }

    // Clear the review of a discarded take. Use/Re-record live inside
    // reviewWrap, so its visibility carries both. reacquire=true re-arms the
    // mic in the same click when it was released — a remembered permission
    // re-grants silently; enableMic() swaps Enable for Record on success, and
    // on failure reports beside the Enable button we've just put back.
    function backToRecorder(reacquire) {
        show(reviewWrap, false);
        timerEl.textContent = '0:00'; show(guideEl, false);
        setStatus(recStatus, '', 'muted');
        if (stream) { showFlex(recordBtn, true); return; }
        showFlex(enableBtn, true);
        if (reacquire) enableMic();
    }

    enableBtn.addEventListener('click', enableMic);
    recordBtn.addEventListener('click', startRecording);
    stopBtn.addEventListener('click', () => stopRecording(''));
    deviceSel?.addEventListener('change', switchDevice);
    // Keep the picker current as devices come and go (labels need a granted mic).
    navigator.mediaDevices.addEventListener?.('devicechange', () => { if (stream) refreshDeviceList(); });
    redoBtn.addEventListener('click', () => backToRecorder(true));
    // The A/B chooser's Re-record lands back here re-arming the mic; Start over
    // clears the review too but leaves the mic released (see data-clip-reset).
    widget.__recorderRedo = () => backToRecorder(true);
    widget.__recorderReset = () => backToRecorder(false);
    useBtn.addEventListener('click', () => {
        if (!recBlob || recBlob.size < 1) { setStatus(recStatus, '✗ The recording is empty — try again.', 'error'); return; }
        const ext = /mp4|m4a/.test(recBlob.type) ? 'mp4' : recBlob.type.includes('ogg') ? 'ogg' : 'webm';
        releaseMic();
        // Freeze Re-record while the clip is preparing — a retake started mid-flight
        // would be yanked away when the A/B chooser replaces the panel.
        redoBtn.classList.add('pointer-events-none', 'opacity-50');
        widget.__prepareRecording(recBlob, 'recording.' + ext, { trigger: useBtn, status: recStatus })
            .finally(() => redoBtn.classList.remove('pointer-events-none', 'opacity-50'));
    });
    window.addEventListener('pagehide', teardown);
}
initVoiceRecorder();

// Voice pages: the chosen engine swaps which controls apply. Every element
// tagged data-engine-only="<model key>" shows only while that engine is
// selected (dials, the built-in preset picker, per-engine help text). These
// wrappers are plain block elements, so `hidden` alone is enough — no
// competing flex class to out-specificity.
//
// Edit voice picks the engine with a <select> (behind the "Change engine…"
// gate); Add a voice picks it with radio cards, so the tradeoffs read side by
// side. Both shapes land here.
function initVoiceEngineToggle() {
    const select = document.getElementById('voice-model');
    const radios = Array.from(document.querySelectorAll('input[name="model"][data-engine-input]'));
    if (!select && !radios.length) return;
    const chosen = () => (select ? select.value : (radios.find((r) => r.checked)?.value ?? ''));

    const sync = () => {
        const model = chosen();
        // data-engine-only holds one engine key or a space-separated list.
        document.querySelectorAll('[data-engine-only]').forEach((el) => {
            el.classList.toggle('hidden', !el.dataset.engineOnly.split(' ').includes(model));
        });
        // The shared preset select only offers the selected engine's built-in
        // voices; a now-foreign pick clears rather than riding along invisibly.
        const preset = document.getElementById('preset-voice');
        if (preset) {
            preset.querySelectorAll('option[data-model]').forEach((opt) => {
                opt.classList.toggle('hidden', opt.dataset.model !== model);
            });
            const picked = preset.selectedOptions[0];
            if (picked?.dataset.model && picked.dataset.model !== model) preset.value = '';
        }
        document.dispatchEvent(new CustomEvent('voice-engine-changed', { detail: { model } }));
    };

    select?.addEventListener('change', sync);
    radios.forEach((radio) => radio.addEventListener('change', sync));
    sync();
}
initVoiceEngineToggle();

// ---------------------------------------------------------------------------
// Disclosures — a labelled button that shows/hides one panel.
// ---------------------------------------------------------------------------
// Used for the things that are essential exactly once and noise the rest of the
// time: recording tips (inside the Record flow, not before it), "Replace" on an
// existing clip, and Qwen's "Compare takes". `hidden` alone is enough — every
// panel is a plain block, so there's no competing flex class to out-specificity.
function initDisclosures(scope) {
    (scope || document).querySelectorAll('[data-disclosure-toggle]').forEach((btn) => {
        const panel = document.querySelector(`[data-disclosure="${btn.dataset.disclosureToggle}"]`);
        if (!panel || btn.dataset.wired) return;
        btn.dataset.wired = '1';
        const sync = () => {
            const open = !panel.classList.contains('hidden');
            btn.setAttribute('aria-expanded', String(open));
            const label = open ? btn.dataset.closeLabel : btn.dataset.openLabel;
            if (label) btn.textContent = label;
        };
        sync();
        btn.addEventListener('click', () => { panel.classList.toggle('hidden'); sync(); });
    });
}
initDisclosures();

// ---------------------------------------------------------------------------
// Voice pages: the step rail, the collapsible steps, and the one save bar.
// ---------------------------------------------------------------------------
// Add a voice and Edit voice run the same pipeline — Identity → Voice source →
// Delivery defaults — and this drives all of it:
//
//   • the rail's per-step state (done / current / to-do / locked) and its meta
//     lines, kept live as fields change;
//   • opening and closing a step (a saved voice's source collapses to a summary
//     row until you go looking for it);
//   • change tracking → the ONE save bar, which names exactly what differs from
//     what was loaded. Nothing on the page saves on its own;
//   • the "Change engine…" gate, because switching engines changes results
//     dramatically AND swaps step 3's controls;
//   • on Add, whether Create can be pressed yet — mirroring the server's own
//     "a voice needs a source" rule rather than inventing a stricter one.
function initVoiceFlow() {
    const form = document.querySelector('form[data-voice-flow]');
    if (!form) return;
    const mode = form.dataset.voiceFlow; // 'edit' | 'create'

    const railItems = new Map();
    form.querySelectorAll('[data-rail-step]').forEach((btn) => railItems.set(btn.dataset.railStep, btn));
    const sections = new Map();
    form.querySelectorAll('[data-voice-step]').forEach((el) => sections.set(el.dataset.voiceStep, el));

    // The rail meta's stable tail: "Chatterbox · 18s clip" keeps "· 18s clip"
    // while the engine label ahead of it follows the picker.
    const metaOf = (key) => railItems.get(key)?.querySelector('[data-rail-meta]');
    const metaTail = new Map();
    railItems.forEach((btn, key) => {
        const text = metaOf(key)?.textContent ?? '';
        metaTail.set(key, text.includes(' · ') ? text.slice(text.indexOf(' · ')) : '');
    });

    // ── which engine is chosen (a select on Edit, radio cards on Add) ────────
    const engineSelect = form.querySelector('#voice-model');
    const engineRadios = Array.from(form.querySelectorAll('input[name="model"][data-engine-input]'));
    const engineValue = () => engineSelect
        ? engineSelect.value
        : (engineRadios.find((r) => r.checked)?.value ?? '');
    const engineLabel = () => engineSelect
        ? (engineSelect.selectedOptions[0]?.textContent.trim() ?? '')
        : (engineRadios.find((r) => r.checked)?.dataset.label ?? '');

    // ── completion, per step ────────────────────────────────────────────────
    const nameInput = form.querySelector('#name');
    const sourceInputs = Array.from(form.querySelectorAll('[data-voice-source]'));
    const hasSource = () => sourceInputs.some((el) =>
        el.type === 'file' ? (el.files?.length ?? 0) > 0 : el.value.trim() !== '');
    // A voice whose engine ships built-in voices needs a clip OR a built-in;
    // one whose engine has none (Chatterbox) speaks with the model's own voice.
    // Same rule VoiceService::assertVoiceHasASource() enforces on save.
    const engineNeedsSource = () => form.querySelector(`#preset-voice option[data-model="${engineValue()}"]`) !== null;
    // ✓ on the rail means "you've done this", not "the server would accept it":
    // on Add, Voice source is done once a clip or built-in actually exists. The
    // Create button is deliberately looser — see refreshCreate().
    const complete = {
        identity: () => (nameInput?.value.trim() ?? '') !== '',
        source: () => mode === 'edit' || hasSource(),
        delivery: () => mode === 'edit',
    };

    // ── open / close a step ─────────────────────────────────────────────────
    const openers = new Map();
    sections.forEach((section, key) => {
        const summary = section.querySelector('[data-step-summary]');
        const body = section.querySelector('[data-step-body]');
        if (!summary || !body) return;
        // `hidden` loses to a co-present `flex`, so both are toggled together.
        const setOpen = (open) => {
            summary.classList.toggle('hidden', open);
            summary.classList.toggle('flex', !open);
            body.classList.toggle('hidden', !open);
        };
        openers.set(key, setOpen);
        section.querySelectorAll('[data-step-toggle]').forEach((btn) =>
            btn.addEventListener('click', () => {
                const opening = body.classList.contains('hidden');
                setOpen(opening);
                if (opening) setActive(key);
                else refresh();
            }));
    });

    // ── which step the rail calls "current" ─────────────────────────────────
    let active = mode === 'edit' ? 'delivery' : 'identity';
    function setActive(key) {
        if (!railItems.has(key) || railItems.get(key).dataset.state === 'locked') return;
        active = key;
        refresh();
    }
    railItems.forEach((btn, key) => btn.addEventListener('click', () => {
        openers.get(key)?.(true);
        setActive(key);
        sections.get(key)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    // Typing in a step makes it the current one — the rail follows the work.
    form.addEventListener('focusin', (e) => {
        const key = e.target.closest?.('[data-voice-step]')?.dataset.voiceStep;
        if (key && key !== active) setActive(key);
    });

    // ── change tracking ─────────────────────────────────────────────────────
    const tracked = () => Array.from(form.elements).filter(
        (el) => el.dataset?.dirtyGroup && !el.disabled && /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName));
    const keyOf = (el) => `${el.name}|${el.type === 'radio' || el.type === 'checkbox' ? el.value : ''}`;
    const valueOf = (el) => {
        if (el.type === 'file') return Array.from(el.files ?? []).map((f) => f.name).join(',');
        if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? '1' : '';
        return el.value;
    };
    const snapshot = new Map(tracked().map((el) => [keyOf(el), valueOf(el)]));

    // Changed controls, grouped by the human label each one declares.
    const changedGroups = () => {
        const groups = new Map();
        tracked().forEach((el) => {
            if (valueOf(el) === (snapshot.get(keyOf(el)) ?? '')) return;
            const label = el.dataset.dirtyGroup;
            if (!groups.has(label)) groups.set(label, []);
            groups.get(label).push(el);
        });
        return groups;
    };
    const describe = (el) => {
        // A bare "on"/"off" says nothing once a group holds more than one
        // control ("sample.wav / off" — off what?), so a checkbox names itself.
        if (el.type === 'checkbox') {
            const state = el.checked ? 'on' : 'off';
            return el.dataset.dirtyValue ? `${el.dataset.dirtyValue} ${state}` : state;
        }
        if (el.dataset.dirtyValue) return el.dataset.dirtyValue;
        if (el.type === 'file') return el.files?.[0]?.name ?? '';
        // An empty select is a clearing, not a choice — its placeholder option
        // ("— none, use the reference clip —") reads as noise in the change list.
        if (el.tagName === 'SELECT') return el.value === '' ? 'cleared' : (el.selectedOptions[0]?.textContent.trim() ?? el.value);
        return el.value.trim() || 'cleared';
    };

    // ── the save bar ────────────────────────────────────────────────────────
    const bar = form.querySelector('[data-save-bar]');
    const barCount = bar?.querySelector('[data-save-count]');
    const barDetail = bar?.querySelector('[data-save-detail]');
    const discardBtn = bar?.querySelector('[data-save-discard]');

    discardBtn?.addEventListener('click', async () => {
        if (!(await confirmDialog({
            title: 'Discard your changes?',
            message: 'Everything you changed since this page loaded is dropped, including any clip you recorded or uploaded but haven’t saved.',
            label: 'Discard changes',
        }))) return;
        // The reload IS the revert; release the unsaved-changes guard for it.
        form.dataset.dirtyGuardOff = '1';
        window.location.reload();
    });

    function refreshSaveBar(groups) {
        if (!bar) return;
        const n = groups.size;
        bar.dataset.state = n ? 'dirty' : 'clean';
        barCount.textContent = n ? `${n} unsaved change${n === 1 ? '' : 's'}` : 'No unsaved changes';
        if (n === 1) {
            const [label, els] = [...groups.entries()][0];
            const values = els.map(describe).filter(Boolean);
            barDetail.textContent = values.length ? `${label} → ${values.join(' / ')}` : label;
        } else {
            barDetail.textContent = n
                ? [...groups.keys()].join(' · ')
                : 'Nothing is sent to the engines until you save.';
        }
        discardBtn?.classList.toggle('hidden', n === 0);
    }

    // ── Add a voice: what Create still needs ────────────────────────────────
    const createBtn = form.querySelector('[data-create-submit]');
    const createHint = form.querySelector('[data-create-hint]');
    function refreshCreate() {
        if (!createBtn) return;
        const named = complete.identity();
        const sourced = hasSource() || !engineNeedsSource();
        createBtn.disabled = !(named && sourced);
        // The hint doubles as a jump-to-the-blocker button while one exists.
        if (!createHint) return;
        createHint.disabled = !createBtn.disabled;
        createHint.textContent = !named
            ? 'Name the voice to continue'
            : (!sourced
                ? `Add a reference clip, or pick a built-in ${engineLabel()} voice`
                : (hasSource()
                    ? 'Step 2 of 2 — tuning unlocks once the voice exists'
                    : `No clip — ${engineLabel()} will speak in its own generic voice`));
    }

    // ── nudge: walk the eye to what Create still needs ──────────────────────
    // With the source ready and the page parked at the take chooser, an empty
    // Name up top reads as done and the disabled Create as broken. The pulse is
    // the field-nudge cue in app.css; refresh() clears it once the field fills
    // (also the reduced-motion exit, where animationend never comes).
    function pulseField(el) {
        if (!el) return;
        el.classList.remove('field-nudge');
        void el.offsetWidth; // restart the animation when re-fired mid-run
        el.classList.add('field-nudge');
    }
    nameInput?.addEventListener('animationend', () => nameInput.classList.remove('field-nudge'));
    const motionOK = () => !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function nudgeCreateBlocker() {
        if (!complete.identity()) {
            sections.get('identity')?.scrollIntoView({ behavior: motionOK() ? 'smooth' : 'auto', block: 'center' });
            nameInput?.focus({ preventScroll: true });
            pulseField(nameInput);
        } else {
            sections.get('source')?.scrollIntoView({ behavior: motionOK() ? 'smooth' : 'auto', block: 'start' });
        }
    }
    createHint?.addEventListener('click', nudgeCreateBlocker); // only fires while enabled = blocked
    // The disabled Create button swallows clicks (and pointer-events are off so
    // they land here) — answer them by pointing at the blocker instead.
    form.querySelector('[data-create-guard]')?.addEventListener('click', () => {
        if (createBtn?.disabled) nudgeCreateBlocker();
    });
    let sourcedBefore = hasSource();

    // ── built-in voice vs reference clip: one source, not two ──────────────
    // The provider gives a clip absolute precedence — `voice` (turbo) and
    // `speaker` (qwen) are sent ONLY when there is no reference audio. So a
    // recorder sitting next to a chosen built-in is not just noise: taking it
    // would silently override the built-in with no feedback anywhere. Show one
    // or the other, and say which is actually going to be heard.
    const presetSelect = form.querySelector('#preset-voice');
    const clipSection = form.querySelector('[data-clip-section]');
    const clipNote = form.querySelector('[data-clip-built-in-note]');
    const removeField = form.querySelector('[data-remove-clip-field]');
    // Follows the PENDING state, not just what's on disk: once removal is queued
    // the built-in really is what will speak, so the notes below must agree.
    const storedClip = () => form.dataset.hasClip === '1' && removeField?.value !== '1';
    let lastPreset = presetSelect?.value ?? '';

    const fileInput = form.querySelector('input[name="audio"]');
    const tokenInput = form.querySelector('[data-clip-token]');
    const clipStaged = () => (fileInput?.files?.length ?? 0) > 0 || (tokenInput?.value ?? '') !== '';
    const clearStagedClip = () => {
        if (fileInput) { fileInput.value = ''; fileInput.dispatchEvent(new Event('change', { bubbles: true })); }
        if (tokenInput) { tokenInput.value = ''; tokenInput.dispatchEvent(new Event('change', { bubbles: true })); }
        // Put the widget back in its default state so switching away and back
        // doesn't land on a stale A/B chooser for a clip that's gone.
        form.querySelector('[data-clip-reset]')?.click();
    };

    const NOTE_PLAIN = 'rounded-[12px] border border-white/9 bg-inset px-5 py-4 text-[13px] leading-relaxed text-zinc-400';
    const NOTE_WARN = 'rounded-[12px] border border-warn/30 bg-warn/[0.05] px-5 py-4 text-[13px] leading-relaxed text-zinc-300';

    // The transcript describes a CLIP. It has nothing to say beside a built-in
    // voice, on a voice whose clip is queued for removal, or before one is added
    // — and VoiceService drops it with the clip anyway.
    function refreshTranscript() {
        form.querySelector('[data-clip-transcript]')
            ?.classList.toggle('hidden', !(storedClip() || clipStaged()));
    }

    function refreshSourceChoice() {
        refreshTranscript();
        if (!clipSection) return;
        const preset = presetSelect && !presetSelect.closest('[data-engine-only]')?.classList.contains('hidden')
            ? presetSelect.value
            : '';
        // A stored clip still wins on save, so Edit keeps the clip on screen and
        // says so rather than hiding it behind a built-in that won't be used.
        const overridden = preset !== '' && storedClip();
        clipSection.classList.toggle('hidden', preset !== '' && !overridden);
        // "Turbo needs a clip longer than 5 seconds" is guidance for the CLIP
        // path; it only muddies the picture once a built-in is the source.
        form.querySelectorAll('[data-clip-path-hint]').forEach((hint) => {
            hint.classList.toggle('hidden', preset !== ''
                || !hint.dataset.engineOnly.split(' ').includes(engineValue()));
        });
        if (clipNote) {
            clipNote.className = overridden ? NOTE_WARN : NOTE_PLAIN;
            clipNote.classList.toggle('hidden', preset === '');
            clipNote.textContent = preset === ''
                ? ''
                : (overridden
                    ? `This voice has a reference clip, and a clip always wins — ${engineLabel()} will keep cloning it. The built-in ${preset} voice is saved, but nothing will speak through it while a clip is present.`
                    : `${engineLabel()} will speak through its built-in ${preset} voice, so there's no clip to record or upload. Choose “none” above to clone from a clip instead.`);
        }
    }

    // ── discarding a STAGED clip ────────────────────────────────────────────
    // Distinct from removing a stored one: this drops the clip you just recorded
    // or picked, before it has ever been saved. On Add it clears the voice's only
    // source; on Edit it abandons a replacement and leaves the stored clip alone.
    const stagedRow = form.querySelector('[data-staged-clip]');
    const stagedText = form.querySelector('[data-staged-clip-text]');
    const clearStagedBtn = form.querySelector('[data-clear-staged-clip]');
    const abPanel = form.querySelector('[data-clip-ab]');
    const isReplace = clipSection?.dataset.clipReplace === '1';

    const stagedName = () => fileInput?.files?.[0]?.name || 'Your recording';

    function refreshStagedClip() {
        if (!stagedRow) return;
        // The A/B chooser carries its own "Start over" — two ways to undo the
        // same thing, side by side, is worse than one.
        const chooserUp = !!abPanel && !abPanel.classList.contains('hidden');
        const show = clipStaged() && !chooserUp;
        stagedRow.classList.toggle('hidden', !show);
        stagedRow.classList.toggle('flex', show);
        if (!show) return;
        stagedText.textContent = isReplace
            ? `${stagedName()} — replaces the current clip when you save.`
            : `${stagedName()} — this is the voice's source.`;
        clearStagedBtn.textContent = isReplace ? 'Discard replacement' : 'Remove clip';
    }

    clearStagedBtn?.addEventListener('click', async () => {
        if (!(await confirmDialog({
            title: isReplace ? 'Discard this replacement?' : 'Remove this clip?',
            message: isReplace
                ? 'The clip you just added is dropped and this voice keeps the reference clip it already has.'
                : `${stagedName()} is dropped and this voice goes back to having no source. Nothing has been saved yet, so there is nothing to undo it from.`,
            label: isReplace ? 'Discard it' : 'Remove clip',
        }))) return;
        clearStagedClip();
        refresh();
    });

    // ── removing the stored clip ────────────────────────────────────────────
    // A stored clip wins over a built-in at render time, so removing it is what
    // makes a built-in take effect. Like every other edit here it stays PENDING
    // until the save bar is used — the bytes are only dropped server-side, once
    // the row commits.
    const removeBtn = form.querySelector('[data-remove-clip]');
    const clipCard = form.querySelector('[data-current-clip]');
    const clipHelp = form.querySelector('[data-clip-help]');
    const removeNote = form.querySelector('[data-remove-clip-note]');
    const removeText = form.querySelector('[data-remove-clip-text]');

    // What the voice would speak with once the clip is gone. Mirrors
    // VoiceService::assertVoiceHasASource(): engines that ship built-ins need
    // one, engines that don't fall back to the model's own generic voice.
    const sourceAfterRemoval = () => {
        const preset = presetSelect?.value ?? '';
        if (preset !== '') return { ok: true, text: `the built-in ${preset} voice` };
        if (!engineNeedsSource()) return { ok: true, text: `${engineLabel()}'s own generic voice` };
        return { ok: false, text: '' };
    };

    // "clones from the clip above" stops being true the moment removal is
    // pending, so the engine row says what the voice will ACTUALLY speak with.
    const engineSourceNote = form.querySelector('[data-engine-source-note]');
    function refreshEngineSourceNote() {
        if (!engineSourceNote) return;
        const preset = presetSelect?.value ?? '';
        engineSourceNote.textContent = '· ' + (storedClip()
            ? 'clones from the clip above'
            : (preset !== '' ? `speaks through the built-in ${preset} voice` : 'speaks with the model’s own generic voice'));
    }

    function refreshRemoval() {
        refreshEngineSourceNote();
        if (!removeField || !removeNote) return;
        const pending = removeField.value === '1';
        clipCard?.classList.toggle('hidden', pending);
        clipHelp?.classList.toggle('hidden', pending);
        removeNote.classList.toggle('hidden', !pending);
        removeNote.classList.toggle('flex', pending);
        if (!pending) return;

        const after = sourceAfterRemoval();
        removeText.textContent = after.ok
            ? `The reference clip will be deleted when you save, and this voice will speak with ${after.text}.`
            : `The reference clip will be deleted when you save — but ${engineLabel()} then has nothing to speak with. Pick a built-in voice above first.`;
        removeNote.classList.toggle('border-bad/40', !after.ok);
        removeNote.classList.toggle('border-warn/30', after.ok);
    }

    removeBtn?.addEventListener('click', async () => {
        const after = sourceAfterRemoval();
        if (!(await confirmDialog({
            title: 'Remove this reference clip?',
            message: after.ok
                ? `The clip is deleted when you save, and this voice speaks with ${after.text} instead. This can't be undone — download it first if you want to keep a copy.`
                : `The clip is deleted when you save. ${engineLabel()} needs a built-in voice to fall back on, so pick one before saving or the save will be refused.`,
            label: 'Remove on save',
        }))) return;
        removeField.value = '1';
        removeField.dispatchEvent(new Event('change', { bubbles: true }));
        refresh();
    });

    form.querySelector('[data-keep-clip]')?.addEventListener('click', () => {
        removeField.value = '0';
        removeField.dispatchEvent(new Event('change', { bubbles: true }));
        refresh();
    });

    // Last line of defence for the source rule the server enforces: a save that
    // would strand the voice is stopped here, where the fix is one field away.
    // Capture phase at the document so this lands BEFORE the form's own
    // data-busy listener — otherwise a blocked submit leaves the Save button
    // spinning on a request that was never sent.
    document.addEventListener('submit', (e) => {
        if (e.target !== form || removeField?.value !== '1' || sourceAfterRemoval().ok) return;
        e.preventDefault();
        e.stopPropagation();
        removeNote.scrollIntoView({ behavior: 'smooth', block: 'center' });
        presetSelect?.focus();
    }, true);

    presetSelect?.addEventListener('change', async () => {
        const preset = presetSelect.value;
        // Never drop a clip they just recorded on the strength of a dropdown.
        if (preset !== '' && clipStaged()) {
            const ok = await confirmDialog({
                title: 'Use the built-in voice instead?',
                message: `A voice has one source. Switching to ${preset} discards the clip you just added — it would override the built-in anyway.`,
                label: 'Use ' + preset,
                tone: 'warn',
            });
            if (!ok) { presetSelect.value = lastPreset; refresh(); return; }
            clearStagedClip();
        }
        lastPreset = preset;
        refresh();
    });

    // ── Edit: the engine gate, and step 3's engine-owned contents ───────────
    const gate = form.querySelector('[data-engine-gate]');
    const picker = form.querySelector('[data-engine-picker]');
    gate?.addEventListener('click', async () => {
        if (!(await confirmDialog({
            title: 'Change this voice’s engine?',
            message: 'Each engine has its own controls, its own reference-clip rules and its own per-character rate — and this voice’s current tuning won’t carry over. Nothing changes until you save.',
            label: 'Choose an engine',
            tone: 'warn',
        }))) return;
        picker.classList.remove('hidden');
        picker.classList.add('grid');
        gate.disabled = true;
        gate.textContent = 'Choosing…';
        engineSelect?.focus();
    });

    // Step 3's controls belong to the engine, and the bench is built for the
    // SAVED one — so an unsaved engine change stands the card down rather than
    // showing knobs that don't apply yet.
    const deliveryBody = form.querySelector('[data-delivery-for]');
    const deliveryPending = form.querySelector('[data-delivery-pending]');
    function refreshDelivery() {
        if (!deliveryBody || !deliveryPending) return;
        const settled = engineValue() === deliveryBody.dataset.deliveryFor;
        deliveryBody.classList.toggle('hidden', !settled);
        deliveryPending.classList.toggle('hidden', settled);
        const label = deliveryPending.querySelector('[data-delivery-pending-label]');
        if (label) label.textContent = engineLabel();
    }

    // ── one pass over everything ────────────────────────────────────────────
    function refresh() {
        const groups = changedGroups();

        // How many distinct changes sit inside each step — the rail says so.
        const perStep = new Map();
        groups.forEach((els, label) => {
            const key = els[0].closest('[data-voice-step]')?.dataset.voiceStep;
            if (key) perStep.set(key, (perStep.get(key) ?? 0) + 1);
        });

        railItems.forEach((btn, key) => {
            if (btn.dataset.state !== 'locked') {
                // A step holding unsaved changes is never ✓ — done means settled,
                // and this one still has something waiting on the save bar.
                const unsaved = mode === 'edit' && (perStep.get(key) ?? 0) > 0;
                btn.dataset.state = key === active
                    ? 'current'
                    : (!unsaved && complete[key]?.() ? 'done' : 'todo');
            }
            const section = sections.get(key);
            if (section && section.dataset.state !== 'locked') section.dataset.state = btn.dataset.state;

            const meta = metaOf(key);
            if (!meta) return;
            // Add a voice tracks nothing: there's no saved state to be out of
            // step with, so its rail always shows the live value.
            const n = mode === 'edit' ? (perStep.get(key) ?? 0) : 0;
            if (n) {
                meta.textContent = `${n} unsaved change${n === 1 ? '' : 's'}`;
                meta.dataset.tone = 'warn';
                return;
            }
            delete meta.dataset.tone;
            if (key === 'identity') {
                const slug = form.querySelector('[data-rail-source="identity"]')?.value.trim();
                meta.textContent = slug || nameInput?.value.trim() || (mode === 'create' ? 'name it' : '');
            } else if (key === 'source') {
                meta.textContent = mode === 'create'
                    ? `${engineLabel()} · ${hasSource() ? 'source ready' : 'clip or built-in'}`
                    : engineLabel() + metaTail.get(key);
            }
        });

        // The step-2 summary row and the engine gate both name the engine.
        form.querySelectorAll('[data-engine-label]').forEach((el) => { el.textContent = engineLabel(); });

        refreshDelivery();
        refreshRemoval();
        refreshSourceChoice();
        refreshStagedClip();
        refreshSaveBar(groups);
        refreshCreate();

        // A source landing while Name is still blank is the moment the empty
        // field gets missed — one finite pulse, no scroll (the footer hint and
        // Create button carry the jump for when the field is off-screen).
        const sourcedNow = hasSource();
        if (mode === 'create' && sourcedNow && !sourcedBefore && !complete.identity()) pulseField(nameInput);
        sourcedBefore = sourcedNow;
        if (complete.identity()) nameInput?.classList.remove('field-nudge');
    }

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    document.addEventListener('voice-engine-changed', refresh);
    refresh();
}
initVoiceFlow();

// New Project page: the Delivery preset picker only offers presets authored
// for the chosen voice's engine; switching to a voice on another engine
// hides the foreign presets and clears a now-foreign pick.
function initCreateProjectPresets() {
    const form = document.getElementById('create-project-form');
    const voice = form?.querySelector('#voice');
    const preset = form?.querySelector('#preset');
    if (!voice || !preset) return;

    const sync = () => {
        const model = modelOfSelect(voice);
        preset.querySelectorAll('option[data-model]').forEach((opt) => {
            opt.classList.toggle('hidden', opt.dataset.model !== model);
        });
        const picked = preset.selectedOptions[0];
        if (picked?.dataset.model && picked.dataset.model !== model) preset.value = '';
    };

    voice.addEventListener('change', sync);
    sync();
}
initCreateProjectPresets();

// Per-knob ⓘ popovers (tuning-knob component): click the ⓘ to reveal its deeper
// explanation, click elsewhere or press Escape to dismiss. One document-level
// handler covers every knob on any page (Studio editor + inspector). The ⓘ and
// its .knob-popover are adjacent siblings in the component markup.
function initKnobPopovers() {
    const closeAll = (except) => {
        document.querySelectorAll('.knob-popover:not(.hidden)').forEach((pop) => {
            if (pop === except) return;
            pop.classList.add('hidden');
            const info = pop.previousElementSibling;
            if (info?.classList.contains('knob-info')) info.setAttribute('aria-expanded', 'false');
        });
    };
    document.addEventListener('click', (e) => {
        const info = e.target.closest?.('.knob-info');
        if (info) {
            e.preventDefault();
            const pop = info.nextElementSibling;
            const willOpen = pop?.classList.contains('hidden');
            closeAll(pop);
            if (pop) {
                pop.classList.toggle('hidden', !willOpen);
                info.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            }
            return;
        }
        if (!e.target.closest?.('.knob-popover')) closeAll();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
}
initKnobPopovers();

// QA badge popovers (design "QA Badge States"): open on hover or keyboard focus,
// toggle on click (touch), close on leaving, outside-click, or Escape. One
// document-level set covers every .qa-badge-wrap — the chunk header badge AND the
// take-list pills, including cards inserted later — so it stays in step as
// verdicts rebuild them. The chunk header's footer buttons are wired per-card in
// initStudioProject; this only shows/hides.
function initQaPopovers() {
    let closeTimer = null;
    const popIn = (badge) => badge?.querySelector('.qa-popover');
    const setExpanded = (badge, on) => badge?.querySelector('.qa-badge')?.setAttribute('aria-expanded', on ? 'true' : 'false');
    const hide = (pop) => {
        pop.classList.add('hidden');
        setExpanded(pop.closest('.qa-badge-wrap'), false);
    };
    const closeAll = (except) => document.querySelectorAll('.qa-popover:not(.hidden)').forEach((p) => { if (p !== except) hide(p); });
    const open = (badge) => {
        clearTimeout(closeTimer);
        const pop = popIn(badge);
        if (!pop) return;
        closeAll(pop);
        pop.classList.remove('hidden');
        setExpanded(badge, true);
    };

    document.addEventListener('mouseover', (e) => {
        const badge = e.target.closest?.('.qa-badge-wrap');
        if (badge) open(badge);
    });
    document.addEventListener('mouseout', (e) => {
        const badge = e.target.closest?.('.qa-badge-wrap');
        if (!badge) return;
        // Moving pill -> popover (or between popover children) stays inside the
        // wrapper: only a move out of it schedules the close.
        if (e.relatedTarget && badge.contains(e.relatedTarget)) return;
        clearTimeout(closeTimer);
        closeTimer = setTimeout(() => { const p = popIn(badge); if (p) hide(p); }, 140);
    });
    document.addEventListener('focusin', (e) => {
        const badge = e.target.closest?.('.qa-badge-wrap');
        if (badge) open(badge);
        else closeAll();
    });
    document.addEventListener('click', (e) => {
        if (e.target.closest?.('.qa-badge')) {
            const badge = e.target.closest('.qa-badge-wrap');
            const pop = popIn(badge);
            if (pop?.classList.contains('hidden')) open(badge);
            else if (pop) hide(pop);
            return;
        }
        // A click inside the popover (an action) is handled per-card; anything
        // else outside a QA badge dismisses the open card.
        if (!e.target.closest?.('.qa-badge-wrap')) closeAll();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });
}
initQaPopovers();

// Pronunciation "▶ Test" buttons (review screen, dictionary form + table):
// speak a respelling so the writer can judge it before approving. Buttons wired
// to an input (data-input) read its CURRENT value, so edits count; table rows
// carry a fixed data-phonetic. data-voice pins the voice (the review screen
// passes the project's); without it the server uses the writer's default.
function initPronunciationTest() {
    const buttons = document.querySelectorAll('[data-pron-test]');
    if (!buttons.length) return;
    const status = document.getElementById('pron-test-status');
    const player = new Audio(); // shared — starting a new test replaces the last
    buttons.forEach((btn) => {
        btn.addEventListener('click', async () => {
            const input = btn.dataset.input ? document.querySelector(btn.dataset.input) : null;
            const phonetic = (input ? input.value : btn.dataset.phonetic || '').trim();
            if (!phonetic) {
                setStatus(status, 'Type a respelling first.', 'error');
                input?.focus();
                return;
            }
            startBusy(btn, input ? 'Testing…' : '');
            setStatus(status, `Generating “${phonetic}”…`);
            try {
                const res = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        // JSON Accept so a validation failure comes back as a
                        // 422 body, not a redirect; the audio bytes still flow.
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ phonetic, voice: btn.dataset.voice || null }),
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                const blob = await res.blob();
                player.pause();
                if (player.src) URL.revokeObjectURL(player.src);
                player.src = URL.createObjectURL(blob);
                await player.play();
                setStatus(status, `✓ Played “${phonetic}”.`, 'ok');
            } catch (err) {
                setStatus(status, `✗ ${err.message}`, 'error');
            } finally {
                endBusy(btn);
            }
        });
    });
}
initPronunciationTest();

// Pronunciation review screen: make Apply/Skip an explicit, tallied decision,
// and let the writer remove an already-approved term that would otherwise apply
// silently. The checkbox stays the submitted source of truth (unchecked = skip);
// everything here is progressive enhancement over a form that works without JS.
function initPronunciationReview() {
    const form = document.querySelector('[data-pron-review]');
    if (form) {
        const tally = form.querySelector('[data-pron-tally]');
        const toggles = () => [...form.querySelectorAll('[data-pron-toggle]')];

        const renderTally = () => {
            if (!tally) return;
            const apply = toggles().filter((t) => t.checked).length;
            tally.textContent = `${apply} will be applied · ${toggles().length - apply} skipped`;
        };

        // The segments are <label for> pairs, so a bare click merely toggles.
        // Intercept so "Apply" always applies and "Skip" always skips — a real
        // segmented control, not a toggle you have to reason about.
        form.addEventListener('click', (e) => {
            const seg = e.target.closest('[data-seg]');
            if (!seg) return;
            e.preventDefault();
            const box = document.getElementById(seg.getAttribute('for'));
            if (!box) return;
            box.checked = seg.dataset.seg === 'apply';
            renderTally();
        });

        // Keyboard: Space on the focused checkbox toggles it directly.
        form.addEventListener('change', (e) => {
            if (e.target.matches('[data-pron-toggle]')) renderTally();
        });

        renderTally();
    }

    // "Already in your dictionary" panel: Remove deletes the approved entry (in
    // this project and every future one) so it stops being applied silently.
    const applied = document.querySelector('[data-pron-applied]');
    if (applied) {
        applied.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-pron-remove]');
            if (!btn || btn.dataset.busy) return;
            const term = btn.dataset.term || 'this term';
            const ok = await confirmDialog({
                title: 'Remove from dictionary?',
                message: `“${term}” won’t be respelled in this project or future ones.`,
                label: 'Remove',
                tone: 'danger',
            });
            if (!ok) return;
            startBusy(btn, 'Removing…');
            try {
                const res = await fetch(btn.dataset.url, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                btn.closest('[data-pron-applied-row]')?.remove();
                // Nothing left auto-applying to this text — drop the whole panel.
                if (!applied.querySelector('[data-pron-applied-row]')) applied.remove();
            } catch (err) {
                endBusy(btn);
                btn.textContent = '✗ Failed';
                setTimeout(() => { btn.textContent = 'Remove'; }, 4000);
            }
        });
    }
}
initPronunciationReview();

// ---------------------------------------------------------------------------
// Jobs page: background "Generate remaining" runs — live rows + Stop.
// ---------------------------------------------------------------------------
const JOB_STYLES = {
    queued: 'border-zinc-700 bg-zinc-800 text-zinc-400',
    running: 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',
    completed: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    failed: 'border-red-500/30 bg-red-500/10 text-red-300',
    cancelled: 'border-amber-500/30 bg-amber-500/10 text-amber-300',
};

function initJobsPage() {
    const root = document.getElementById('jobs-page');
    if (!root) return;

    const rows = new Map([...root.querySelectorAll('.job-row')].map((r) => [r.dataset.jobId, r]));
    const rowActive = (row) => ['queued', 'running'].includes(row.querySelector('.job-status').textContent.trim());
    const anyActive = () => [...rows.values()].some(rowActive);

    const apply = (job) => {
        const row = rows.get(job.id);
        if (!row) return; // a run dispatched after this page rendered — appears on reload
        const pill = row.querySelector('.job-status');
        pill.textContent = job.status;
        pill.className = 'job-status inline-flex rounded-md border px-2 py-0.5 text-xs ' + (JOB_STYLES[job.status] || JOB_STYLES.queued);
        row.querySelector('.job-progress').textContent = `${job.chunks_done + job.chunks_failed}/${job.chunks_total} · ${job.percent}%`;
        row.querySelector('.job-message').textContent = job.message;
        // A run finishing live grows its extras in place: the measured duration
        // under "Started", and — for a completed duplicate — the copy link.
        const duration = row.querySelector('.job-duration');
        if (duration && job.duration_human) {
            duration.textContent = `took ${job.duration_human}`;
            duration.classList.remove('hidden');
        }
        const copy = row.querySelector('.job-open-copy');
        if (copy && job.redirect_url) {
            copy.href = job.redirect_url;
            copy.classList.remove('hidden');
        }
        if (!job.active) row.querySelector('.job-cancel')?.classList.add('hidden');
    };

    // Poll only while something is actually moving; a page of settled runs is
    // static until reloaded.
    let timer = null;
    const poll = async () => {
        try {
            const res = await fetch(root.dataset.statusUrl, { headers: { 'Accept': 'application/json' } });
            if (res.ok) ((await res.json()).jobs || []).forEach(apply);
        } catch { /* transient — next tick retries */ }
        timer = anyActive() ? setTimeout(poll, 4000) : null;
    };
    if (anyActive()) timer = setTimeout(poll, 4000);

    root.addEventListener('click', async (e) => {
        const btn = e.target.closest('.job-cancel');
        if (!btn || btn.dataset.busy) return;
        startBusy(btn, 'Stopping…');
        try {
            const res = await fetch(btn.dataset.cancelUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            endBusy(btn);
            if (data.job) apply(data.job);
            if (!timer && anyActive()) timer = setTimeout(poll, 1500);
        } catch (err) {
            endBusy(btn);
            setStatus(document.getElementById('jobs-status'), `✗ ${err.message}`, 'error');
        }
    });
}
initJobsPage();

// ---------------------------------------------------------------------------
// Unsaved-changes guard
// ---------------------------------------------------------------------------
// A form marked [data-dirty-guard] warns before the page is left — tab close,
// reload, Back, or an in-app link — while any field differs from what it was on
// load. A real navigation only honours the native beforeunload prompt, so the
// shared confirm dialog can't stand in here. Comparing a fresh serialization to
// the on-load snapshot means reverting an edit (including a "Reset to default"
// back to the original) clears the guard, and submitting the form releases it.
function initDirtyGuard() {
    document.querySelectorAll('form[data-dirty-guard]').forEach((form) => {
        const serialize = () => new URLSearchParams(new FormData(form)).toString();
        const initial = serialize();
        let submitting = false;

        form.addEventListener('submit', () => { submitting = true; });

        window.addEventListener('beforeunload', (e) => {
            // `data-dirty-guard-off` is the in-app escape hatch: a control that
            // discards ON PURPOSE (the voice save bar's Discard reloads the page
            // to revert) sets it, so the user isn't asked to confirm twice.
            if (submitting || form.dataset.dirtyGuardOff === '1' || serialize() === initial) return;
            e.preventDefault();
            e.returnValue = ''; // Chrome shows the prompt only when returnValue is set.
        });
    });
}
initDirtyGuard();
