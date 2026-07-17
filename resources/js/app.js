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
function buildAPlayer(variant, { label = 'Play audio', hidden = false, extraClass = '' } = {}) {
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
    const audio = document.createElement('audio');
    audio.className = 'aplayer__native';
    el.append(btn, track, time, audio);
    return el;
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
    btn.textContent = btn.dataset.originalText;
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
};

function confirmDialog({ title = 'Are you sure?', message = '', label = 'Confirm', tone = 'danger' } = {}) {
    const dialog = document.getElementById('confirm-dialog');
    if (!dialog || dialog.classList.contains('flex')) {
        return Promise.resolve(window.confirm([title, message].filter(Boolean).join('\n\n')));
    }
    document.getElementById('confirm-dialog-title').textContent = title;
    const messageEl = document.getElementById('confirm-dialog-message');
    messageEl.textContent = message;
    messageEl.classList.toggle('hidden', !message);
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
    confirmDialog({
        title: form.dataset.confirmTitle,
        message: form.dataset.confirm,
        label: form.dataset.confirmLabel,
        tone: form.dataset.confirmTone,
    }).then((ok) => {
        if (!ok) return;
        form.dataset.confirmed = '1';
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
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

// Which engine each engine-specific tuning knob belongs to. Temperature and
// the seed pin are shared by both engines, so they're absent here. Mirrors
// the knob sets in ChatterboxTuning / ChatterboxTurboTuning (PHP owns the
// formulas; this map only decides which controls SHOW).
const KNOB_ENGINES = {
    exaggeration: 'chatterbox',
    cfg_weight: 'chatterbox',
    top_p: 'chatterbox-turbo',
    top_k: 'chatterbox-turbo',
    repetition_penalty: 'chatterbox-turbo',
};

// The engine behind a voice <select>: its selected option's data-model
// (stamped server-side from voices.model; absent = classic chatterbox).
const modelOfSelect = (select) => select?.selectedOptions[0]?.dataset.model || 'chatterbox';

// Show exactly the given engine's knobs inside `scope` (a chunk card or a
// knobs row) and hide the other engine's, plus filter preset options and the
// engine-specific help sentences to match. Tuning-knob roots are flex
// containers, so `hidden` and `flex` are ALWAYS toggled as a pair — a
// co-present flex class would win over hidden. (Help spans and preset options
// are plain inline elements; `hidden` alone is safe there.)
function syncKnobEngines(scope, model) {
    scope.querySelectorAll('.tuning-knob[data-knob]').forEach((knob) => {
        const engine = KNOB_ENGINES[knob.dataset.knob];
        if (!engine) return;
        const hide = engine !== model;
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
        const balance = estimate.balance;
        els.balance.classList.toggle('hidden', !balance);
        if (balance) {
            els.balance.textContent = balance.label;
            els.balance.classList.toggle('text-red-300', balance.low);
            els.balance.classList.toggle('border-red-500/40', balance.low);
        }
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
};

// Row two of the bench: a more-expressive contrast to row one's defaults.
const BENCH_CONTRAST = {
    'chatterbox': { exaggeration: '0.95', cfg_weight: '0.8', temperature: '0.9' },
    'chatterbox-turbo': { top_p: '0.85', top_k: '300', repetition_penalty: '1.35', temperature: '1' },
};

function initTuningBench(bench) {
    const synthUrl = bench.dataset.synthesizeUrl;
    const saveUrl = bench.dataset.voiceDefaultsUrl;
    const voice = bench.dataset.voice;
    // The voice's SAVED engine decides the knob columns (the blade renders the
    // matching header). An unsaved engine change retunes the bench after save.
    const model = bench.dataset.model || 'chatterbox';
    const knobDefs = BENCH_KNOBS[model] || BENCH_KNOBS.chatterbox;
    const rowGrid = model === 'chatterbox-turbo'
        ? 'grid-cols-[44px_1fr_1fr_1fr_1fr_0.8fr_1.5fr_40px]'
        : 'grid-cols-[44px_1.1fr_1.1fr_1.1fr_0.8fr_1.5fr_40px]';
    const els = {
        text: bench.querySelector('.bench-text'),
        rows: bench.querySelector('.bench-rows'),
        addBtn: bench.querySelector('.bench-add'),
        genBtn: bench.querySelector('.bench-generate'),
        saveBtn: bench.querySelector('.bench-save'),
        status: bench.querySelector('.bench-status'),
    };

    const rows = [];

    const knob = (value, def) => {
        const input = document.createElement('input');
        Object.assign(input, { type: 'number', step: def.step, min: def.min, max: def.max, placeholder: def.ph });
        if (value !== null && value !== '' && value !== undefined) input.value = value;
        input.className = 'w-[74px] rounded-[8px] border border-white/12 bg-inset px-2.5 py-2 text-[15px] text-zinc-100 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30';
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

    // The bench body for one candidate row: the sample line, the bench's voice,
    // and this row's native knobs. Returns null when there's no text to synthesize.
    const rowBody = (state) => {
        const text = els.text.value.trim();
        if (!text) return null;
        const body = new URLSearchParams({ text, voice });
        Object.entries(rowValues(state)).forEach(([param, value]) => body.set(param, value));
        return body;
    };

    const loadAudio = (audio, blob) => {
        audio.src = URL.createObjectURL(blob);
        (audio.closest('.aplayer') || audio).classList.remove('hidden');
    };

    async function generateRow(state, btn, autoplay = true) {
        const body = rowBody(state);
        if (!body) { setStatus(els.status, 'Paste some text first.', 'error'); return; }
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
            autoplay ? playAudio(state.audio, blob) : loadAudio(state.audio, blob);
            if (state.placeholder) state.placeholder.classList.add('hidden');
            setStatus(els.status, `✓ Generated in ${elapsed(t0)}s.`, 'ok');
        } catch (err) {
            setStatus(els.status, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(btn);
        }
    }

    function addRow(values = {}) {
        const li = document.createElement('li');
        li.className = `grid ${rowGrid} items-center gap-2 border-b border-white/6 px-4 py-3.5 last:border-b-0`;

        const pick = document.createElement('input');
        Object.assign(pick, { type: 'radio', name: 'bench-pick', title: 'Pick this setting to save' });
        pick.className = 'accent-emerald-500';

        const inputs = {};
        knobDefs.forEach((def) => { inputs[def.param] = knob(values[def.param] ?? null, def); });

        const playBtn = document.createElement('button');
        playBtn.type = 'button';
        playBtn.className = 'grid h-[34px] w-[34px] place-items-center rounded-full border border-accent/50 text-accent transition hover:bg-accent/10';
        playBtn.textContent = '▶';

        // Take cell: a "not generated" placeholder swapped for the app's
        // standard player (take weight) once made.
        const take = document.createElement('div');
        take.className = 'min-w-0';
        const placeholder = document.createElement('span');
        placeholder.className = 'text-[13px] text-zinc-600';
        placeholder.textContent = 'not generated';
        const player = buildAPlayer('take', { label: 'Play take', hidden: true });
        const audio = player.querySelector('.aplayer__native');
        take.append(placeholder, player);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'text-center text-zinc-600 hover:text-zinc-300';
        remove.title = 'Remove';
        remove.textContent = '✕';

        li.append(pick, ...knobDefs.map((def) => inputs[def.param]), playBtn, take, remove);

        const state = { inputs, audio, pick, placeholder };
        rows.push(state);
        if (rows.length === 1) pick.checked = true;

        playBtn.addEventListener('click', () => generateRow(state, playBtn));
        remove.addEventListener('click', () => {
            const i = rows.indexOf(state);
            if (i >= 0) rows.splice(i, 1);
            li.remove();
            if (pick.checked && rows[0]) rows[0].pick.checked = true;
        });

        els.rows.append(li);
        enhanceStudioPlayers(li); // skin the row's take player
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

    async function savePick() {
        const picked = rows.find((s) => s.pick.checked);
        if (!picked) { setStatus(els.status, 'Pick a setting first.', 'error'); return; }
        const body = new URLSearchParams({ voice });
        Object.entries(rowValues(picked)).forEach(([param, value]) => body.set(param, value));
        startBusy(els.saveBtn, 'Saving…');
        try {
            const res = await fetch(saveUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
            setStatus(els.status, `✓ ${data.message || 'Saved.'}`, 'ok');
        } catch (err) {
            setStatus(els.status, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(els.saveBtn);
        }
    }

    els.addBtn.addEventListener('click', () => addRow());
    els.genBtn.addEventListener('click', generateAll);
    els.saveBtn.addEventListener('click', savePick);

    // --- Named presets (3b): apply adds a pre-filled row; ✕ deletes; save the
    // picked row's values as a new named preset. ---
    const presetsBar = bench.querySelector('.bench-presets');
    if (presetsBar) {
        const storeUrl = presetsBar.dataset.storeUrl;
        const emptyHint = bench.querySelector('.bench-preset-empty');
        const presetSaveBtn = bench.querySelector('.bench-preset-save');

        const refreshEmpty = () =>
            emptyHint?.classList.toggle('hidden', presetsBar.querySelectorAll('.bench-preset').length > 0);

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
            chip.querySelector('.preset-apply').addEventListener('click', () => addRow(chipValues(chip)));
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
            chip.className = 'bench-preset inline-flex items-center gap-1 rounded-full border border-zinc-700 bg-zinc-800 py-0.5 pl-2.5 pr-1.5 text-xs';
            chip.dataset.id = preset.id;
            chip.dataset.exaggeration = preset.exaggeration ?? '';
            chip.dataset.cfg = preset.cfg_weight ?? '';
            chip.dataset.temperature = preset.temperature ?? '';
            chip.dataset.topP = preset.top_p ?? '';
            chip.dataset.topK = preset.top_k ?? '';
            chip.dataset.repetitionPenalty = preset.repetition_penalty ?? '';
            const apply = document.createElement('button');
            apply.type = 'button';
            apply.className = 'preset-apply text-zinc-200 hover:text-cyan-300';
            apply.textContent = preset.name;
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'preset-delete text-zinc-500 hover:text-red-300';
            del.title = 'Delete preset';
            del.textContent = '✕';
            chip.append(apply, del);
            presetsBar.insertBefore(chip, emptyHint);
            wireChip(chip);
            refreshEmpty();
        };

        presetsBar.querySelectorAll('.bench-preset').forEach(wireChip);

        presetSaveBtn?.addEventListener('click', async () => {
            const picked = rows.find((s) => s.pick.checked);
            if (!picked) { setStatus(els.status, 'Pick a row to save as a preset.', 'error'); return; }
            const name = (window.prompt('Preset name?') || '').trim();
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

    // Seed row one with the voice's CURRENT defaults (blank = inherit the
    // system default) and row two with a more-expressive contrast to compare.
    const currentDefaults = {};
    knobDefs.forEach((def) => {
        if (bench.dataset[def.data]) currentDefaults[def.param] = bench.dataset[def.data];
    });
    addRow(currentDefaults);
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
};

// ASR transcript-QA badge tones (server sends {tone, text, title} or null).
const ASR_TONE = {
    ok: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    bad: 'border-red-500/30 bg-red-500/10 text-red-300',
};

// Human labels for a take's provenance (its stored `source` token — see
// ProjectService::recordTake and the duplicate copier). Presentation only:
// the payload keeps the raw token, and an unknown token shows itself.
// "QA auto-fix" is deliberately outcome-neutral — the adjacent QA badge says
// whether the fix actually recovered ("fixed by re-roll" vs "still flagged").
const TAKE_SOURCE_LABELS = {
    generate: 'rendered with Generate',
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

    const finalUrl = root.dataset.finalUrl;
    const rebuildUrl = root.dataset.rebuildUrl;
    const finalAudio = document.getElementById('project-final-audio');
    const finalStatus = document.getElementById('project-final-status');
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
            t.closest('.seam-preview');             // renders a temporary preview only

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
    // Show/hide the ASR verdict pill from the server's {tone, text, title} (or null).
    // Toggle hidden vs inline-flex by rewriting the class entirely, never leaving
    // both set (inline-flex would otherwise win over hidden in the compiled CSS).
    const setChunkAsrBadge = (card, info) => {
        const el = card.querySelector('.chunk-asr-badge');
        if (!el) return;
        if (!info) {
            el.className = 'chunk-asr-badge hidden';
            el.textContent = '';
            el.removeAttribute('title');
            return;
        }
        el.className = 'chunk-asr-badge inline-flex cursor-help rounded-md border px-2 py-0.5 text-xs ' + (ASR_TONE[info.tone] || ASR_TONE.bad);
        el.textContent = info.text;
        if (info.title) el.title = info.title; else el.removeAttribute('title');
    };
    const setChunkStatus = (card, status) => {
        badge(card.querySelector('.chunk-status'), status, 'chunk-status ');
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
    // following it — the queue worker does the generating). While true, the
    // per-chunk generate/reroll buttons and Build final are locked: the server
    // 409s them anyway, since they'd race the worker over the same chunks.
    let runActive = false;
    const stopBtn = document.getElementById('project-generate-stop');

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

        // Generate-all shows while chunks remain outstanding — and since Build
        // final is off in exactly those states (below), it's the single next step,
        // so it always leads. It pulses when existing work (generated audio or a
        // built final) has fallen out of sync with the text; a brand-new project
        // gets the lit primary without the nudge. look() rewrites className with
        // ACT_BASE (which carries `inline-flex`), so hiding must clear inline-flex
        // too — a lone `hidden` loses to it. showEl toggles both, so re-adding a
        // chunk (→ anyPending) brings the button back.
        if (generateAllBtn) {
            look(generateAllBtn, 'primary');
            setPulse(generateAllBtn, anyPending && (anyCompleted || hasFinal));
            showEl(generateAllBtn, anyPending, 'inline-flex');
        }
        // Stop lives next to Generate remaining, only while a run is in flight.
        showEl(stopBtn, runActive, 'inline-flex');
        // Build final stays off while any chunk needs generating: a stale chunk
        // still holds its OLD audio, so stitching now would put outdated audio
        // under the edited text (the server only rejects chunks with NO audio).
        // Once every chunk is current it lights and pulses until the final is
        // (re)built; a ready final steps it down to a quiet secondary. During a
        // background run it's always off — the run isn't done stitching-worthy
        // work yet, and the server 409s a rebuild mid-run.
        look(rebuildBtn, runActive ? 'off' : (ready ? 'outline' : (allCompleted ? 'primary' : 'off')));
        setPulse(rebuildBtn, ! runActive && allCompleted && ! ready);

        // The draft download (bare final audio) is offered until the project is
        // approved; then the approved-version package supersedes it, so it hides.
        look(downloadLink, ready ? 'primary' : 'off');
        showEl(downloadLink, ! isSealed, 'inline-flex');

        // Approve ⇆ approved-download share one slot. "Approve as final" stays visible
        // (lit when a clean final exists, greyed otherwise) until the project is
        // approved; then it's replaced in place by the approved-version download,
        // which becomes the primary action.
        look(sealBtn, (ready && ! isSealed) ? 'seal' : 'off');
        showEl(sealBtn, ! isSealed, 'inline-flex');
        if (receiptLink) {
            look(receiptLink, 'primary');
            showEl(receiptLink, isSealed, 'inline-flex');
        }
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

    // A chunk is "dirty" while its textarea differs from the last-saved text
    // (data-original). Show an amber badge + border and reveal Revert; warn before
    // leaving the page with any dirty chunk. Set when an intended reload (insert /
    // re-chunk) navigates away on purpose, so the guard doesn't fire for those.
    let skipUnloadGuard = false;
    const isDirty = (card) => {
        const t = card.querySelector('.chunk-text');
        return t.value !== t.dataset.original;
    };
    const setDirty = (card, dirty) => {
        const textarea = card.querySelector('.chunk-text');
        const dirtyBadge = card.querySelector('.chunk-dirty');
        // Toggle hidden AND inline-flex together — leaving both on an element lets
        // inline-flex win over hidden in the compiled CSS, so the badge would always show.
        dirtyBadge.classList.toggle('hidden', !dirty);
        dirtyBadge.classList.toggle('inline-flex', dirty);
        card.querySelector('.chunk-revert').classList.toggle('hidden', !dirty);
        textarea.classList.toggle('border-amber-500/50', dirty);
        textarea.classList.toggle('border-zinc-800', !dirty);
        // Save applies only to an unsaved edit; Regenerate renders the SAVED text,
        // so only one is actionable at a time (the Blade markup starts a clean
        // chunk with Save disabled). The `disabled:` classes handle the dimming.
        const saveBtn = card.querySelector('.chunk-save');
        const genBtn = card.querySelector('.chunk-generate');
        if (saveBtn) saveBtn.disabled = !dirty;
        if (genBtn) genBtn.disabled = dirty;
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
        setDirty(card, false);
        card.querySelector('.chunk-chars').textContent = `${data.characters} chars`;
        setChunkStatus(card, data.status);
        setProjectStatus(data.project_status);
        refreshSeams(); // a now-stale chunk hides its adjacent seam previews
        return false;
    }

    // Generate (or re-roll) one chunk: optionally persist a pending text edit
    // first, POST to `url`, then refresh status + audio. Re-roll uses the same
    // flow against the reroll endpoint (which drops the pinned seed).
    async function runGeneration(card, url, btn, label) {
        const textarea = card.querySelector('.chunk-text');
        startBusy(btn, label);
        try {
            if (textarea.value !== textarea.dataset.original) {
                // Persist the edit first. If that split the chunk, the page is
                // reloading — don't generate the now-orphaned original chunk.
                if (await patchChunk(card)) return;
            }
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();

            setChunkStatus(card, data.status);
            setChunkAsrBadge(card, data.asr_badge ?? null);
            setProjectStatus(data.project_status);
            const audio = card.querySelector('.chunk-audio');
            audio.src = bust(card.dataset.audioUrl);
            audio.closest('.aplayer')?.classList.remove('hidden');
            audio.play().catch(() => {});
            endBusy(btn);
            card.querySelector('.chunk-generate').textContent = '▶ Regenerate';
            card.querySelector('.chunk-tune-keep')?.classList.add('hidden'); // this take replaces any pending preview
            renderTakes(card, data); // the new take joins the history (and becomes selected)
            refreshSeams(); // may reveal an inline seam preview next to a generated neighbor
        } catch (err) {
            setChunkStatus(card, 'failed');
            reflectActionState(); // a failed chunk is outstanding again — Build final goes off
            endBusy(btn);
            throw err;
        }
    }

    const generateChunk = (card) =>
        runGeneration(card, card.dataset.generateUrl, card.querySelector('.chunk-generate'), 'Generating…');

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
                gen.disabled = isDirty(card);
                gen.title = locked
                    ? 'Adds this clip to the active background run — it regenerates after the clips already in line.'
                    : "Render this chunk's audio from its current text and tuning.";
            }
            const reroll = card.querySelector('.chunk-reroll');
            if (reroll) reroll.disabled = locked;
        });
    };

    // "Regenerate" while a background run is active: the direct endpoint would
    // 409 (the worker owns generation), so append the chunk to the run instead.
    // The server marks a generated chunk stale as it adopts it; the poll then
    // reports it like any other clip in the run.
    async function queueChunkRegen(card) {
        const btn = card.querySelector('.chunk-generate');
        startBusy(btn, 'Queueing…');
        try {
            const res = await fetch(card.dataset.queueUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            setChunkStatus(card, data.status);
            setStatus(finalStatus, data.message || data.job?.message);
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(btn);
        }
    }

    // One chunk entry from the poll. Light entries ({id, status}) are chunks the
    // run hasn't finished with; full ones carry the same payload generateChunk()
    // returns, and flow through the same render path. Returns whether the
    // chunk's audio changed (the caller refreshes seams once per batch).
    const applyRunChunk = (data) => {
        const card = root.querySelector(`.studio-chunk[data-chunk-id="${data.id}"]`);
        if (!card) return false;
        setChunkStatus(card, data.status);
        if (data.asr_badge !== undefined) setChunkAsrBadge(card, data.asr_badge ?? null);
        if (data.selected_take_id === undefined || renderedTakes.get(data.id) === data.selected_take_id) {
            return false;
        }
        renderedTakes.set(data.id, data.selected_take_id);
        const audio = card.querySelector('.chunk-audio');
        if (audio && data.selected_take_id !== null) {
            // No autoplay: chunks land while the user is elsewhere on the page
            // (or was — a resumed run may deliver several at once).
            audio.src = bust(card.dataset.audioUrl);
            audio.closest('.aplayer')?.classList.remove('hidden');
        }
        card.querySelector('.chunk-generate').textContent = '▶ Regenerate';
        card.querySelector('.chunk-tune-keep')?.classList.add('hidden'); // this take replaces any pending preview
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

    async function rebuild() {
        startBusy(rebuildBtn, 'Rebuilding…');
        setStatus(finalStatus, 'Stitching chunks…');
        try {
            const res = await fetch(rebuildUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            finalAudio.src = bust(finalUrl);
            hasFinal = true;
            finalPlayer?.classList.remove('hidden');
            document.getElementById('project-final-placeholder')?.remove();
            finalAudio.play().catch(() => {});
            isSealed = false; // new bytes — the server cleared the seal; offer to re-seal
            setProjectStatus('ready'); // also re-lights the action cluster (Download leads)
            setStatus(finalStatus, '✓ Rebuilt.', 'ok');
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(rebuildBtn);
        }
    }

    // A chunk's status badge is the source of truth for "is it generated?".
    const isChunkCompleted = (card) =>
        card && card.querySelector('.chunk-status')?.textContent.trim() === 'completed';

    // Skipped = left out of the stitched final (reversible). data-skipped also
    // drives the row's dimmed look via CSS.
    const isChunkSkipped = (card) => card?.dataset.skipped === '1';

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

    // Show the inline "Preview stitch" connector only between two generated,
    // included chunks — a seam next to a skipped chunk won't exist in the final.
    function refreshSeams() {
        root.querySelectorAll('.chunk-seam').forEach((seam) => {
            const prev = root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.prev}"]`);
            const next = root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.next}"]`);
            seam.classList.toggle('hidden', !(isChunkCompleted(prev) && isChunkCompleted(next)
                && !isChunkSkipped(prev) && !isChunkSkipped(next)));
        });
    }

    // Stitch the two adjacent chunks this connector sits between, playing inline.
    async function previewSeam(seam) {
        const btn = seam.querySelector('.seam-preview');
        const player = seam.querySelector('.seam-player');
        const status = seam.querySelector('.seam-status');
        const t0 = performance.now();
        startBusy(btn, 'Stitching…');
        player.classList.remove('hidden');
        try {
            const res = await fetch(previewUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*', 'Content-Type': 'application/json' },
                body: JSON.stringify({ chunks: [seam.dataset.prev, seam.dataset.next] }),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            playAudio(seam.querySelector('.seam-audio'), await res.blob());
            setStatus(status, `✓ Stitched in ${elapsed(t0)}s.`, 'ok');
        } catch (err) {
            setStatus(status, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(btn);
        }
    }

    root.querySelectorAll('.chunk-seam .seam-preview').forEach((btn) => {
        btn.addEventListener('click', () => previewSeam(btn.closest('.chunk-seam')));
    });

    // ---- Take history -------------------------------------------------------
    // Every render is kept as a selectable take. These render the per-chunk list
    // from the JSON the server returns (embedded on load, or from each action's
    // response / the listTakes endpoint).
    function takeRow(card, take) {
        const li = document.createElement('li');
        li.dataset.takeId = take.id;
        li.className = 'chunk-take flex flex-wrap items-center gap-2 rounded-lg border px-2 py-1.5 '
            + (take.selected ? 'border-emerald-600/50 bg-emerald-500/10' : 'border-zinc-800 bg-zinc-950/40');

        // Custom player (take weight): enhanceStudioPlayers() (called by
        // renderTakes) wires it up.
        const player = buildAPlayer('take', {
            label: 'Play take',
            extraClass: 'min-w-0 flex-1' + (take.selected ? ' aplayer--selected' : ''),
        });
        // Recorded length: enhanceStudioPlayers prints it immediately, so the
        // duration is visible without playing (preload stays 'none' — no request).
        if (take.duration_ms) player.dataset.durationMs = take.duration_ms;
        const audio = player.querySelector('.aplayer__native');
        audio.preload = 'none';
        audio.src = take.audio_url;

        const meta = document.createElement('div');
        meta.className = 'flex min-w-0 flex-col text-xs text-zinc-500';
        const line1 = document.createElement('span');
        line1.className = take.selected ? 'text-emerald-300' : 'text-zinc-400';
        line1.textContent = (TAKE_SOURCE_LABELS[take.source] || take.source) + (take.selected ? ' · selected' : '');
        const line2 = document.createElement('span');
        // Show the seed this take rendered at: the pinned number, or "random" when
        // it rolled unpinned (Replicate doesn't report the seed it chose). Lets a
        // good pinned take be spotted and re-pinned in the field above.
        const seedText = take.seed ? `seed ${take.seed}` : 'seed random';
        line2.textContent = take.tuning_label + ' · ' + seedText + (take.created_human ? ' · ' + take.created_human : '');
        meta.append(line1, line2);
        if (take.asr_badge) {
            const b = document.createElement('span');
            b.className = 'mt-0.5 inline-flex w-fit cursor-help rounded-md border px-1.5 py-0.5 text-[11px] '
                + (ASR_TONE[take.asr_badge.tone] || ASR_TONE.bad);
            b.textContent = take.asr_badge.text;
            if (take.asr_badge.title) b.title = take.asr_badge.title;
            meta.append(b);
        }

        const actions = document.createElement('div');
        actions.className = 'ml-auto flex items-center gap-1.5';
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
        const header = document.getElementById('project-spend');
        if (header && spend.project) {
            header.textContent = spend.project.label;
            header.title = spend.project.title;
        }
        // Remaining prepaid credit for the project owner; the span only exists
        // for limited owners, and the label arrives server-formatted like the
        // rest. `low` flips it to the warning color at/below $0.
        const balance = document.getElementById('credit-balance');
        if (balance && spend.balance) {
            balance.textContent = spend.balance.label;
            balance.classList.toggle('text-amber-400', !!spend.balance.low);
            balance.classList.toggle('text-zinc-500', !spend.balance.low);
        }
    }

    function renderTakes(card, data) {
        renderSpend(card, data && data.spend);
        // Keep the main player's duration fallback in step with whichever take the
        // chunk audio now points at (its src is cache-busted on select/generate, so
        // audio.duration is briefly unavailable — durationchange re-syncs from this).
        const selected = ((data && data.takes) || []).find((t) => t.selected);
        const mainPlayer = card.querySelector('.chunk-audio')?.closest('.aplayer');
        if (mainPlayer && selected?.duration_ms) mainPlayer.dataset.durationMs = selected.duration_ms;
        const list = card.querySelector('.chunk-takes');
        if (!list) return;
        const takes = (data && data.takes) || [];
        list.innerHTML = '';
        if (!takes.length) {
            const li = document.createElement('li');
            li.className = 'text-xs text-zinc-600';
            li.textContent = 'No takes yet — Generate or Preview to create one.';
            list.append(li);
            return;
        }
        takes.forEach((take) => list.append(takeRow(card, take)));
        enhanceStudioPlayers(list); // skin the freshly-built take players
    }

    async function refreshTakes(card) {
        try {
            const res = await fetch(card.dataset.takesUrl, { headers: { 'Accept': 'application/json' } });
            if (res.ok) renderTakes(card, await res.json());
        } catch { /* a missing list is non-fatal */ }
    }

    async function selectTake(card, takeId, btn) {
        startBusy(btn, 'Selecting…');
        try {
            const res = await fetch(card.dataset.takesUrl + '/' + takeId + '/select', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            const data = await res.json();
            setChunkStatus(card, data.status);
            setChunkAsrBadge(card, data.asr_badge ?? null);
            setProjectStatus(data.project_status);
            const audio = card.querySelector('.chunk-audio');
            audio.src = bust(card.dataset.audioUrl);
            audio.closest('.aplayer')?.classList.remove('hidden');
            card.querySelector('.chunk-generate').textContent = '▶ Regenerate';
            renderTakes(card, data); // rebuilds the list (and detaches btn)
            refreshSeams();
            setStatus(finalStatus, '✓ Selected this take as the chunk audio.', 'ok');
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
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
            setStatus(finalStatus, '✓ Take deleted.', 'ok');
        } catch (err) {
            setStatus(finalStatus, `✗ ${err.message}`, 'error');
            endBusy(btn);
        }
    }

    root.querySelectorAll('.studio-chunk').forEach((card) => {
        // The blob + settings from the last successful tuning preview, so
        // "Use this take" can persist the exact clip the user just heard. Any
        // edit that would change how a fresh preview sounds clears it (below).
        let previewBlob = null;
        let previewKnobs = {}; // knob key -> string value, only the ones that were set
        let previewSeed = '';
        const keepBtn = card.querySelector('.chunk-tune-keep');
        const invalidatePreview = () => {
            previewBlob = null;
            keepBtn?.classList.add('hidden');
        };

        // Render the take history embedded on the card, and wire Select/Delete via
        // one delegated listener (the list is rebuilt wholesale on every change).
        try { renderTakes(card, JSON.parse(card.dataset.takes || '{}')); } catch { /* ignore */ }
        card.querySelector('.chunk-takes')?.addEventListener('click', (e) => {
            const row = e.target.closest('.chunk-take');
            if (!row) return;
            const selBtn = e.target.closest('.chunk-take-select');
            const delBtn = e.target.closest('.chunk-take-delete');
            if (selBtn && !selBtn.disabled) selectTake(card, row.dataset.takeId, selBtn);
            else if (delBtn && !delBtn.disabled) deleteTake(card, row.dataset.takeId, delBtn);
        });

        card.querySelector('.chunk-save').addEventListener('click', async () => {
            const btn = card.querySelector('.chunk-save');
            startBusy(btn, 'Saving…');
            try {
                await patchChunk(card);
                setStatus(finalStatus, '');
            } catch (err) {
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            } finally {
                endBusy(btn);
            }
        });
        card.querySelector('.chunk-generate').addEventListener('click', () => (
            runActive ? queueChunkRegen(card) : generateChunk(card).catch(() => {})
        ));

        // Per-chunk voice override: PATCH the chosen voice. A generated chunk goes
        // stale (its audio used the previous voice), and the chunk stops mirroring
        // the project voice (data-inherits -> 0). Revert the picker on failure.
        const chunkVoice = card.querySelector('.chunk-voice');
        if (chunkVoice) {
            chunkVoice.dataset.current = chunkVoice.value;
            chunkVoice.addEventListener('change', async () => {
                const voice = chunkVoice.value;
                const previous = chunkVoice.dataset.current;
                if (voice === previous) return;
                chunkVoice.disabled = true;
                try {
                    const res = await fetch(chunkVoice.dataset.voiceUrl, {
                        method: 'PATCH',
                        headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                        body: JSON.stringify({ voice }),
                    });
                    if (!res.ok) throw new Error(await errorMessage(res));
                    const data = await res.json();
                    chunkVoice.dataset.current = voice;
                    chunkVoice.dataset.inherits = data.inherits ? '1' : '0';
                    // The new voice may run a different engine — swap the knob set.
                    syncKnobEngines(card, modelOfSelect(chunkVoice));
                    setChunkStatus(card, data.status);
                    setProjectStatus(data.project_status);
                    refreshSeams();
                    setStatus(finalStatus, `✓ Chunk voice set to ${data.voice_name}. Regenerate to apply.`, 'ok');
                } catch (err) {
                    chunkVoice.value = previous; // revert the picker on failure
                    setStatus(finalStatus, `✗ ${err.message}`, 'error');
                } finally {
                    chunkVoice.disabled = false;
                }
            });
        }

        // Re-roll: regenerate this chunk with a fresh random seed (a new take).
        card.querySelector('.chunk-reroll')?.addEventListener('click', () =>
            runGeneration(card, card.dataset.rerollUrl, card.querySelector('.chunk-reroll'), 'Re-rolling…').catch(() => {}));

        // The value of one knob input, or '' while its knob is hidden (the
        // OTHER engine's knob — a leftover value there must not ride along).
        const knobVal = (sel) => {
            const input = card.querySelector(sel);
            if (!input || input.closest('.tuning-knob')?.classList.contains('hidden')) return '';
            return input.value;
        };

        // Every engine-specific knob, read via knobVal so only the active
        // engine's set is ever sent; temperature is shared.
        const KNOB_INPUTS = [
            ['exaggeration', '.chunk-exaggeration'],
            ['cfg_weight', '.chunk-cfg'],
            ['temperature', '.chunk-temperature'],
            ['top_p', '.chunk-top-p'],
            ['top_k', '.chunk-top-k'],
            ['repetition_penalty', '.chunk-repetition-penalty'],
        ];

        // A/B preview: audition the typed native knobs (saved as a non-selected take).
        card.querySelector('.chunk-tune-preview')?.addEventListener('click', async () => {
            const btn = card.querySelector('.chunk-tune-preview');
            const seed = card.querySelector('.chunk-seed').value;
            const body = new URLSearchParams();
            KNOB_INPUTS.forEach(([key, sel]) => {
                const value = knobVal(sel);
                if (value !== '') body.set(key, value);
            });
            if (seed !== '') body.set('seed', seed);
            startBusy(btn, 'Previewing…');
            try {
                const res = await fetch(card.dataset.previewTuningUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*' },
                    body,
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                const blob = await res.blob();
                playAudio(card.querySelector('.chunk-tune-audio'), blob);
                // Remember this exact clip so "Use this take" can keep it verbatim.
                previewBlob = blob;
                previewKnobs = {};
                KNOB_INPUTS.forEach(([key, sel]) => {
                    const value = knobVal(sel);
                    if (value !== '') previewKnobs[key] = value;
                });
                previewSeed = seed;
                keepBtn?.classList.remove('hidden');
                refreshTakes(card); // the preview was saved as a (non-selected) take
                setStatus(finalStatus, '✓ Preview ready — "Use this take" keeps it, or play it from the list.', 'ok');
            } catch (err) {
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            } finally {
                endBusy(btn);
            }
        });

        // Use this take: upload the exact previewed clip back and store it as the
        // chunk's audio (with the settings it was previewed at). No regeneration,
        // so what's saved is byte-for-byte what the user just auditioned — the only
        // reliable way to keep a good take given the provider's non-determinism.
        keepBtn?.addEventListener('click', async () => {
            if (!previewBlob) return;
            startBusy(keepBtn, 'Saving…');
            try {
                const ext = previewBlob.type === 'audio/mpeg' ? 'mp3' : 'wav';
                const fd = new FormData();
                fd.append('audio', previewBlob, `take.${ext}`);
                Object.entries(previewKnobs).forEach(([key, value]) => fd.append(key, value));
                if (previewSeed !== '') fd.append('seed', previewSeed);
                const res = await fetch(card.dataset.usePreviewUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
                    body: fd,
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                const data = await res.json();
                setChunkStatus(card, data.status);
                setChunkAsrBadge(card, data.asr_badge ?? null);
                setProjectStatus(data.project_status);
                const audio = card.querySelector('.chunk-audio');
                audio.src = bust(card.dataset.audioUrl);
                audio.closest('.aplayer')?.classList.remove('hidden');
                audio.play().catch(() => {});
                card.querySelector('.chunk-generate').textContent = '▶ Regenerate';
                invalidatePreview();
                renderTakes(card, data); // the kept clip is now a selected take
                refreshSeams();
                setStatus(finalStatus, '✓ Saved this take as the chunk audio.', 'ok');
            } catch (err) {
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            } finally {
                endBusy(keepBtn);
            }
        });

        // Save this chunk's native tuning override; the server marks it stale.
        // Hidden knobs (the other engine's) post null, so switching a chunk's
        // engine then saving clears any leftover foreign-engine override.
        card.querySelector('.chunk-tune-save')?.addEventListener('click', async () => {
            const btn = card.querySelector('.chunk-tune-save');
            const seed = card.querySelector('.chunk-seed').value;
            const payload = { seed: seed === '' ? null : Number(seed) };
            KNOB_INPUTS.forEach(([key, sel]) => {
                const value = knobVal(sel);
                payload[key] = value === '' ? null : Number(value);
            });
            startBusy(btn, 'Saving…');
            try {
                const res = await fetch(card.dataset.tuningUrl, {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                const data = await res.json();
                setChunkStatus(card, data.status);
                setProjectStatus(data.project_status);
                refreshSeams();
                setStatus(finalStatus, '✓ Tuning saved — regenerate to hear it.', 'ok');
            } catch (err) {
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            } finally {
                endBusy(btn);
            }
        });

        // A preview reflects the inputs at preview time; once any of them change,
        // the kept clip would no longer match, so retire the "Use this take" offer.
        KNOB_INPUTS.forEach(([, sel]) => card.querySelector(sel)?.addEventListener('input', invalidatePreview));
        card.querySelector('.chunk-seed').addEventListener('input', invalidatePreview);
        card.querySelector('.chunk-text').addEventListener('input', invalidatePreview);
        card.querySelector('.chunk-voice').addEventListener('change', invalidatePreview);

        // 🎲 rolls a fresh random seed into the field so the pin is visible and
        // re-usable (clearing the field by hand still means inherit/random).
        card.querySelector('.chunk-seed-random')?.addEventListener('click', () => {
            const seedInput = card.querySelector('.chunk-seed');
            seedInput.value = Math.floor(Math.random() * 1_000_000);
            seedInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        // "Apply preset" fills the native knobs (dispatching input so the sliders
        // sync and the preview invalidates); nothing persists until Save tuning.
        // Seed is deliberately not part of a preset — it's a per-take pin, not a
        // reusable delivery.
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

        // Track dirty state as the user types; Revert restores the saved text.
        card.querySelector('.chunk-text').addEventListener('input', () => setDirty(card, isDirty(card)));
        card.querySelector('.chunk-revert').addEventListener('click', () => {
            const textarea = card.querySelector('.chunk-text');
            textarea.value = textarea.dataset.original;
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
                    setStatus(finalStatus, data.skipped
                        ? "✓ Chunk skipped — it won't be in the final."
                        : '✓ Chunk included again.', 'ok');
                } catch (err) {
                    endBusy(skipBtn);
                    setStatus(finalStatus, `✗ ${err.message}`, 'error');
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
                    setStatus(finalStatus, `✗ ${err.message}`, 'error');
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
    if (root.dataset.activeRun === '1') followRun();

    // Don't let the user navigate away (or trigger a reload) with unsaved chunk
    // edits without a heads-up. Intentional reloads set skipUnloadGuard first.
    window.addEventListener('beforeunload', (e) => {
        if (skipUnloadGuard) return;
        if ([...root.querySelectorAll('.studio-chunk')].some(isDirty)) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Insert an empty chunk at a gap, then reload to re-render the (renumbered) list.
    root.querySelectorAll('.chunk-insert button').forEach((btn) => {
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
            startBusy(btn, 'Inserting…');
            try {
                const res = await fetch(insertUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ position: Number(btn.closest('.chunk-insert').dataset.position) }),
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                skipUnloadGuard = true; // reload is intentional
                window.location.reload();
            } catch (err) {
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
                endBusy(btn);
            }
        });
    });

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
                    const cv = card.querySelector('.chunk-voice');
                    if (!cv || cv.dataset.inherits !== '1') return;
                    cv.value = voice;
                    cv.dataset.current = voice;
                    // Following the project voice may change the engine too.
                    syncKnobEngines(card, modelOfSelect(cv));
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
        const audio = el.querySelector('.aplayer__native');
        const btn = el.querySelector('.aplayer__btn');
        const track = el.querySelector('.aplayer__track');
        const fill = el.querySelector('.aplayer__fill');
        const knob = el.querySelector('.aplayer__knob');
        const time = el.querySelector('.aplayer__time');
        if (!audio || !btn || !track) return;
        el.dataset.enhanced = '1';

        const fmt = (s) => (isFinite(s) && s >= 0)
            ? Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0')
            : '0:00';
        const sync = () => {
            // Until the audio's own metadata loads, fall back to the server-recorded
            // length (data-duration-ms) so the duration shows without any interaction
            // — take players are preload="none", so metadata only loads on play.
            const d = audio.duration || (parseInt(el.dataset.durationMs, 10) || 0) / 1000;
            const pct = d ? (audio.currentTime / d) * 100 : 0;
            if (fill) fill.style.width = pct + '%';
            if (knob) knob.style.left = pct + '%';
            if (time) time.textContent = fmt(audio.currentTime) + ' / ' + fmt(d);
        };

        btn.addEventListener('click', () => { audio.paused ? audio.play().catch(() => {}) : audio.pause(); });
        track.addEventListener('click', (e) => {
            const r = track.getBoundingClientRect();
            if (audio.duration) audio.currentTime = ((e.clientX - r.left) / r.width) * audio.duration;
        });
        audio.addEventListener('timeupdate', sync);
        audio.addEventListener('loadedmetadata', sync);
        // Fires on src swap (duration resets) and when the new metadata arrives, so
        // the readout tracks a re-selected take via the data-duration-ms fallback.
        audio.addEventListener('durationchange', sync);
        audio.addEventListener('play', () => el.classList.add('is-playing'));
        audio.addEventListener('pause', () => el.classList.remove('is-playing'));
        audio.addEventListener('ended', () => el.classList.remove('is-playing'));
        sync();
    });
}

initStudioProject();

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

// New-project form: the "Create project" POST blocks while the server normalizes
// the text and runs the (potentially ~minute-long) LLM pronunciation check before
// it can render the review screen. Without feedback the page reads as frozen, so
// show a spinner + honest step messaging until the browser navigates away.
function initCreateProjectForm() {
    const form = document.getElementById('create-project-form');
    if (!form) return;

    const btn = form.querySelector('button[type=submit]');
    const status = document.getElementById('create-project-status');
    let timers = [];

    const reset = () => {
        timers.forEach(clearTimeout);
        timers = [];
        if (btn) endBusy(btn);
        setStatus(status, '');
    };

    // Native `required` validation blocks submit before this fires, so reaching
    // here means the form is valid and the request is on its way.
    form.addEventListener('submit', () => {
        if (!btn) return;
        startBusy(btn, 'Normalizing text…');
        setStatus(status, 'This can take up to a minute for long articles — please keep this page open.');
        timers = [
            setTimeout(() => setRunning(btn, 'Checking pronunciations…'), 900),
        ];
    });

    // The form is a full-page POST, so a successful submit navigates away and the
    // spinner clears on its own. But the back/forward cache can restore this page
    // with the button still stuck in its busy state — reset it when that happens.
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
        const btn = form.querySelector('button[type=submit]');
        const status = form.querySelector('[data-busy-status]');
        const reset = () => {
            if (btn) endBusy(btn);
            if (status) setStatus(status, '');
        };
        // Native validation blocks submit before this fires, so reaching here
        // means the form is valid and the (slow) request is on its way.
        form.addEventListener('submit', () => {
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
        changeBtn.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) avatarForm.submit();
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

// Users admin (2B): reveal the invite/create forms and the drawer's delete confirm,
// and wire the one-time "copy" button for a temp password / invite link.
function initUsers() {
    const toggle = (btnId, panelId) => {
        const btn = document.getElementById(btnId);
        const panel = document.getElementById(panelId);
        if (btn && panel) btn.addEventListener('click', () => panel.classList.toggle('hidden'));
    };
    toggle('invite-toggle', 'invite-form');
    toggle('create-toggle', 'create-form');
    toggle('user-delete-toggle', 'user-delete-confirm');

    const copyBtn = document.querySelector('[data-copy-btn]');
    const copyInput = document.querySelector('[data-copy]');
    if (copyBtn && copyInput) {
        copyBtn.addEventListener('click', async () => {
            copyInput.select();
            try {
                await navigator.clipboard.writeText(copyInput.value);
            } catch (_) {
                document.execCommand('copy');
            }
            copyBtn.textContent = 'Copied';
            setTimeout(() => (copyBtn.textContent = 'Copy'), 1500);
        });
    }
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
            e.preventDefault();
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
    setMode('upload'); // default to Upload; the user can switch to Record when available

    function refreshPreviewBtn() {
        const show = !!hasSource() && enhanceBox && enhanceBox.checked && !tokenInput.value;
        previewBtn.classList.toggle('hidden', !show);
    }

    function clearPreview() {
        tokenInput.value = '';
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
            renderAB(await res.json());
            say('', 'muted');
        } catch (err) {
            say(`✗ ${err.message}`, 'error');
        } finally {
            endBusy(trigger);
            refreshPreviewBtn();
        }
    }

    function renderAB(data) {
        tokenInput.value = data.token || '';

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
        if (rerecordBtn) rerecordBtn.classList.toggle('hidden', !recordedBlob); // mic takes can be rejected in place
        if (fileInput) fileInput.disabled = true; // the token supersedes a raw upload
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
    widget.querySelector('[data-clip-reset]')?.addEventListener('click', () => { recordedBlob = null; clearPreview(); });

    // Reject the previewed mic take: back to the recorder, mic still armed, ready to retake.
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
            reviewAudio.src = URL.createObjectURL(recBlob);
            enhanceStudioPlayers(widget);
            show(reviewWrap, true);
            show(useBtn, true);
            if ((performance.now() - startedAt) / 1000 < 10) {
                setStatus(recStatus, 'That was short — aim for 15–30s for a better clone. You can re-record.', 'muted');
            }
        };
        mr.start();
        startedAt = performance.now();
        timerId = setInterval(tick, 200);
        show(timerEl, true); show(guideEl, true); show(reviewWrap, false);
        showFlex(recordBtn, false); showFlex(stopBtn, true); show(redoBtn, false);
        if (deviceSel) deviceSel.disabled = true; // the recorder is bound to this stream
        setStatus(recStatus, '', 'muted');
    }

    function stopRecording(msg) {
        if (mr && mr.state !== 'inactive') mr.stop();
        clearInterval(timerId);
        showFlex(stopBtn, false); showFlex(recordBtn, false); show(redoBtn, true);
        if (deviceSel) deviceSel.disabled = false;
        if (msg) setStatus(recStatus, msg, 'muted');
    }

    function teardown() {
        cancelAnimationFrame(meterRAF);
        if (stream) stream.getTracks().forEach((t) => t.stop());
        if (audioCtx && audioCtx.state !== 'closed') audioCtx.close();
        stream = null; audioCtx = null; analyser = null;
    }

    function resetForRetake() {
        show(reviewWrap, false); show(useBtn, false); show(redoBtn, false);
        showFlex(recordBtn, true); timerEl.textContent = '0:00'; show(guideEl, false);
        setStatus(recStatus, '', 'muted');
    }

    enableBtn.addEventListener('click', enableMic);
    recordBtn.addEventListener('click', startRecording);
    stopBtn.addEventListener('click', () => stopRecording(''));
    deviceSel?.addEventListener('change', switchDevice);
    // Keep the picker current as devices come and go (labels need a granted mic).
    navigator.mediaDevices.addEventListener?.('devicechange', () => { if (stream) refreshDeviceList(); });
    redoBtn.addEventListener('click', resetForRetake);
    // The A/B chooser's Reject & re-record lands back here with the mic still armed.
    widget.__recorderRedo = resetForRetake;
    useBtn.addEventListener('click', () => {
        if (!recBlob || recBlob.size < 1) { setStatus(recStatus, '✗ The recording is empty — try again.', 'error'); return; }
        const ext = /mp4|m4a/.test(recBlob.type) ? 'mp4' : recBlob.type.includes('ogg') ? 'ogg' : 'webm';
        // Freeze Re-record while the clip is preparing — a retake started mid-flight
        // would be yanked away when the A/B chooser replaces the panel.
        redoBtn.classList.add('pointer-events-none', 'opacity-50');
        widget.__prepareRecording(recBlob, 'recording.' + ext, { trigger: useBtn, status: recStatus })
            .finally(() => redoBtn.classList.remove('pointer-events-none', 'opacity-50'));
    });
    window.addEventListener('pagehide', teardown);
}
initVoiceRecorder();

// Default-tuning dials (voice edit page): a number field and a slider are two
// views of one value. Editing either updates the other and the slider's cyan
// fill; a blank number rests the slider at neutral without writing a value.
function initVoiceTuningDials() {
    document.querySelectorAll('[data-tuning-dial]').forEach((dial) => {
        const number = dial.querySelector('[data-tuning-number]');
        const slider = dial.querySelector('[data-tuning-slider]');
        if (!number || !slider) return;

        const min = Number(slider.min), max = Number(slider.max);
        // Resting point when the field is blank (0.5 for exaggeration/cfg, 0.8 for
        // temperature — the dial declares it via data-neutral).
        const neutral = Number(dial.dataset.neutral) || 0.5;
        const fill = (val) => {
            const pct = Math.max(0, Math.min(100, ((val - min) / (max - min)) * 100));
            slider.style.setProperty('--fill', pct + '%');
        };

        const syncFromNumber = () => {
            const raw = number.value.trim();
            const val = raw === '' ? neutral : Number(raw);
            slider.value = val;
            fill(val);
        };
        syncFromNumber();

        number.addEventListener('input', syncFromNumber);
        slider.addEventListener('input', () => {
            number.value = slider.value;
            fill(Number(slider.value));
        });
    });
}
initVoiceTuningDials();

// Voice pages: the Engine select swaps which controls apply. Every element
// tagged data-engine-only="<model key>" shows only while that engine is
// selected (dials, the built-in preset picker, per-engine help text). These
// wrappers are plain block elements, so `hidden` alone is enough — no
// competing flex class to out-specificity.
function initVoiceEngineToggle() {
    const select = document.getElementById('voice-model');
    if (!select) return;

    const sync = () => {
        document.querySelectorAll('[data-engine-only]').forEach((el) => {
            el.classList.toggle('hidden', el.dataset.engineOnly !== select.value);
        });
        document.dispatchEvent(new CustomEvent('voice-engine-changed', { detail: { model: select.value } }));
    };

    select.addEventListener('change', sync);
    sync();
}
initVoiceEngineToggle();

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
