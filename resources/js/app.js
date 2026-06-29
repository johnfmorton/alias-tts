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
    audio.classList.remove('hidden');
    audio.play().catch(() => {});
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
    btn.classList.add('pointer-events-none', 'opacity-50');
    setRunning(btn, label);
}

function endBusy(btn) {
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
    // Copy-to-clipboard: <button data-copy="value">
    const copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
        try {
            await navigator.clipboard.writeText(copyBtn.getAttribute('data-copy'));
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
            const blob = await res.blob();
            if (audio) {
                audio.src = URL.createObjectURL(blob);
                audio.classList.remove('hidden');
                audio.play().catch(() => {});
            }
        } catch (_) {
            alert('Preview failed — check your provider credit and try again.');
        } finally {
            testBtn.disabled = false;
            testBtn.textContent = label;
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

    // Health checks: <a data-health-run data-loading-label="Running…">. These
    // are full-page GETs that can take several seconds (the queue probe), so we
    // paint a loading state immediately — the page stays visible until the
    // server responds and replaces it. We do NOT preventDefault: let it navigate.
    const runBtn = e.target.closest('[data-health-run]');
    if (runBtn) {
        const label = runBtn.getAttribute('data-loading-label') || 'Running…';
        document.querySelectorAll('[data-health-run]').forEach((b) => {
            if (!b.dataset.originalText) b.dataset.originalText = b.textContent;
            b.classList.add('pointer-events-none', 'opacity-50');
            b.setAttribute('aria-disabled', 'true');
        });
        setRunning(runBtn, label);
        document.querySelector('[data-health-results]')?.classList.add('opacity-40', 'pointer-events-none');
    }
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

function initStudio() {
    const root = document.getElementById('studio');
    if (!root) return;

    const urls = {
        preview: root.dataset.previewUrl,
        synthesize: root.dataset.synthesizeUrl,
        stitch: root.dataset.stitchUrl,
        concat: root.dataset.concatUrl,
    };

    const els = {
        text: document.getElementById('studio-text'),
        voice: document.getElementById('studio-voice'),
        exaggeration: root.querySelector('.studio-exaggeration'),
        cfg: root.querySelector('.studio-cfg'),
        status: document.getElementById('studio-status'),
        results: document.getElementById('studio-results'),
        normalized: document.getElementById('studio-normalized'),
        normChars: document.getElementById('studio-norm-chars'),
        chunkCount: document.getElementById('studio-chunk-count'),
        chunks: document.getElementById('studio-chunks'),
        previewBtn: document.getElementById('studio-preview'),
        wholeBtn: document.getElementById('studio-whole'),
        wholeAudio: document.getElementById('studio-whole-audio'),
        stitchBtn: document.getElementById('studio-stitch'),
        concatBar: document.getElementById('studio-concat-bar'),
        concatBtn: document.getElementById('studio-concat'),
        concatStatus: document.getElementById('studio-concat-status'),
        concatAudio: document.getElementById('studio-concat-audio'),
    };

    let normalizedText = '';
    // Per-chunk state for the current preview, in chunk order. Each entry holds
    // the generated raw WAV blob (once generated) so we can stitch the EXACT
    // audio the user heard, plus the checkbox that selects it for concatenation.
    let chunkStates = [];

    // Common generation params from the form controls. `text` is whatever we want
    // synthesized (a chunk, or the whole normalized text).
    const params = (text) => {
        const body = { text };
        if (els.voice?.value) body.voice = els.voice.value;
        if (els.exaggeration?.value !== '') body.exaggeration = els.exaggeration.value;
        if (els.cfg?.value !== '') body.cfg_weight = els.cfg.value;
        return new URLSearchParams(body);
    };

    async function fetchBlob(url, text) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*' },
            body: params(text),
        });
        if (!res.ok) throw new Error(await errorMessage(res));
        return res.blob();
    }

    // Generate `text`, play it into `audio`, drive `btn`'s busy state, and return
    // the blob (or null on error) so callers can retain it.
    async function generate(url, text, audio, btn, label) {
        const t0 = performance.now();
        startBusy(btn, label);
        try {
            const blob = await fetchBlob(url, text);
            playAudio(audio, blob);
            setStatus(els.status, `✓ ${label.replace('…', '')} done in ${elapsed(t0)}s.`, 'ok');
            return blob;
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

        // "include" checkbox — hidden until this chunk has been generated.
        const include = document.createElement('label');
        include.className = 'hidden cursor-pointer items-center gap-1.5 text-xs text-zinc-400';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = true;
        cb.className = 'accent-cyan-500';
        include.append(cb, document.createTextNode('include'));
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

        const audio = document.createElement('audio');
        audio.controls = true;
        audio.className = 'mt-3 hidden w-full';

        if (els.voice) {
            genBtn.addEventListener('click', async () => {
                const blob = await generate(urls.synthesize, chunk.text, audio, genBtn, 'Generating…');
                if (!blob) return;
                state.blob = blob;
                include.classList.remove('hidden');
                include.classList.add('inline-flex');
                refreshConcatBar();
            });
        } else {
            genBtn.disabled = true;
            genBtn.classList.add('opacity-40');
        }

        li.append(head, body, audio);
        return li;
    }

    // Stitch the selected, already-generated chunks through the production
    // trim + seam concatenation and play the result.
    async function concatSelected() {
        const chosen = chunkStates.filter((s) => s.blob && s.checkbox?.checked);
        if (!chosen.length) {
            setStatus(els.concatStatus, 'Tick at least one generated chunk first.', 'error');
            return;
        }
        const fd = new FormData();
        chosen.forEach((s) => {
            fd.append('files[]', s.blob, `chunk-${s.index + 1}.wav`);
            fd.append('breaks[]', s.breakAfter);
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

        chunkStates = data.chunks.map((c) => ({ index: c.index, breakAfter: c.breakAfter, blob: null, checkbox: null }));
        els.chunks.replaceChildren(...data.chunks.map((c, i) => chunkCard(c, chunkStates[i])));

        els.wholeAudio.classList.add('hidden');
        els.concatBar.classList.add('hidden');
        els.concatAudio.classList.add('hidden');
        setStatus(els.concatStatus, '');
        els.results.classList.remove('hidden');
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
                body: new URLSearchParams({ text }),
            });
            if (!res.ok) throw new Error(await errorMessage(res));
            renderPreview(await res.json());
            setStatus(els.status, '');
        } catch (err) {
            setStatus(els.status, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(els.previewBtn);
        }
    }

    els.previewBtn.addEventListener('click', preview);
    els.concatBtn.addEventListener('click', concatSelected);

    // Editing the text invalidates the breakdown — hide it until re-previewed.
    els.text.addEventListener('input', () => els.results.classList.add('hidden'));

    initTuningKnobs(root); // wire the single-shot Exaggeration / CFG-Pace sliders

    els.wholeBtn?.addEventListener('click', () =>
        generate(urls.synthesize, normalizedText, els.wholeAudio, els.wholeBtn, 'Generating whole…'));
    els.stitchBtn?.addEventListener('click', () =>
        generate(urls.stitch, normalizedText, els.wholeAudio, els.stitchBtn, 'Stitching…'));
}

initStudio();

// ---------------------------------------------------------------------------
// Studio "Advanced tuning" toggle (per-user, persisted) + A/B tuning bench.
// ---------------------------------------------------------------------------
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

function initStudioBench() {
    const bench = document.getElementById('studio-bench');
    const root = document.getElementById('studio');
    if (!bench || !root) return;

    const synthUrl = root.dataset.synthesizeUrl;
    const saveUrl = bench.dataset.voiceDefaultsUrl;
    const els = {
        text: document.getElementById('studio-text'),
        voice: document.getElementById('studio-voice'),
        rows: document.getElementById('studio-bench-rows'),
        addBtn: document.getElementById('studio-bench-add'),
        genBtn: document.getElementById('studio-bench-generate'),
        saveBtn: document.getElementById('studio-bench-save'),
        status: document.getElementById('studio-bench-status'),
    };

    const rows = [];

    const knob = (value, placeholder, min, max) => {
        const input = document.createElement('input');
        Object.assign(input, { type: 'number', step: '0.05', min, max, placeholder });
        if (value !== null && value !== '') input.value = value;
        input.className = 'w-20 rounded-lg border border-zinc-700 bg-zinc-950 px-2 py-1 text-sm';
        return input;
    };

    const field = (labelText, input) => {
        const wrap = document.createElement('label');
        wrap.className = 'flex items-center gap-1.5 text-xs text-zinc-500';
        wrap.append(document.createTextNode(labelText), input);
        return wrap;
    };

    // The bench body for one candidate row: the text above, the voice, and this
    // row's native knobs. Returns null when there's no text to synthesize.
    const rowBody = (state) => {
        const text = els.text.value.trim();
        if (!text) return null;
        const body = new URLSearchParams({ text });
        if (els.voice?.value) body.set('voice', els.voice.value);
        if (state.exagIn.value !== '') body.set('exaggeration', state.exagIn.value);
        if (state.cfgIn.value !== '') body.set('cfg_weight', state.cfgIn.value);
        return body;
    };

    const loadAudio = (audio, blob) => {
        audio.src = URL.createObjectURL(blob);
        audio.classList.remove('hidden');
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
            setStatus(els.status, `✓ Generated in ${elapsed(t0)}s.`, 'ok');
        } catch (err) {
            setStatus(els.status, `✗ ${err.message}`, 'error');
        } finally {
            endBusy(btn);
        }
    }

    function addRow(exaggeration, cfg) {
        const li = document.createElement('li');
        li.className = 'flex flex-wrap items-center gap-2 rounded-lg border border-zinc-800 bg-zinc-950/40 p-2';

        const pick = document.createElement('input');
        Object.assign(pick, { type: 'radio', name: 'studio-bench-pick', title: 'Pick this setting to save' });
        pick.className = 'accent-emerald-500';

        const exagIn = knob(exaggeration, '0.5', '0.25', '2');
        const cfgIn = knob(cfg, '0.5', '0.2', '1');

        const playBtn = document.createElement('button');
        playBtn.type = 'button';
        playBtn.className = 'rounded-lg border border-zinc-700 px-3 py-1.5 text-sm hover:bg-zinc-800';
        playBtn.textContent = '▶';

        const audio = document.createElement('audio');
        audio.controls = true;
        audio.className = 'hidden h-8 grow';

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'text-zinc-600 hover:text-zinc-300';
        remove.title = 'Remove';
        remove.textContent = '✕';

        li.append(pick, field('Exaggeration', exagIn), field('CFG/Pace', cfgIn), playBtn, audio, remove);

        const state = { exagIn, cfgIn, audio, pick };
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
    }

    async function generateAll() {
        if (!els.text.value.trim()) { setStatus(els.status, 'Paste some text first.', 'error'); return; }
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
        if (!els.voice?.value) { setStatus(els.status, 'Select a voice first.', 'error'); return; }
        const body = new URLSearchParams({ voice: els.voice.value });
        if (picked.exagIn.value !== '') body.set('exaggeration', picked.exagIn.value);
        if (picked.cfgIn.value !== '') body.set('cfg_weight', picked.cfgIn.value);
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

    els.addBtn.addEventListener('click', () => addRow(null, null));
    els.genBtn.addEventListener('click', generateAll);
    els.saveBtn.addEventListener('click', savePick);

    // --- Named presets (3b): apply adds a pre-filled row; ✕ deletes; save the
    // picked row's values as a new named preset. ---
    const presetsBar = document.getElementById('studio-presets');
    if (presetsBar) {
        const storeUrl = presetsBar.dataset.storeUrl;
        const emptyHint = document.getElementById('studio-preset-empty');
        const presetSaveBtn = document.getElementById('studio-preset-save');

        const refreshEmpty = () =>
            emptyHint?.classList.toggle('hidden', presetsBar.querySelectorAll('.studio-preset').length > 0);

        const wireChip = (chip) => {
            chip.querySelector('.preset-apply').addEventListener('click', () =>
                addRow(chip.dataset.exaggeration || null, chip.dataset.cfg || null));
            chip.querySelector('.preset-delete').addEventListener('click', async () => {
                if (!confirm(`Delete preset "${chip.querySelector('.preset-apply').textContent}"?`)) return;
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
            chip.className = 'studio-preset inline-flex items-center gap-1 rounded-full border border-zinc-700 bg-zinc-800 py-0.5 pl-2.5 pr-1.5 text-xs';
            chip.dataset.id = preset.id;
            chip.dataset.exaggeration = preset.exaggeration ?? '';
            chip.dataset.cfg = preset.cfg_weight ?? '';
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

        presetsBar.querySelectorAll('.studio-preset').forEach(wireChip);

        presetSaveBtn?.addEventListener('click', async () => {
            const picked = rows.find((s) => s.pick.checked);
            if (!picked) { setStatus(els.status, 'Pick a row to save as a preset.', 'error'); return; }
            const name = (window.prompt('Preset name?') || '').trim();
            if (!name) return;
            const body = new URLSearchParams({ name });
            if (picked.exagIn.value !== '') body.set('exaggeration', picked.exagIn.value);
            if (picked.cfgIn.value !== '') body.set('cfg_weight', picked.cfgIn.value);
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

    // Seed with the neutral default and a steadier/more-expressive example
    // (exaggeration 0.95, cfg/pace 0.80).
    addRow(0.5, 0.5);
    addRow(0.95, 0.8);
}
initStudioBench();

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

    // Cache-bust so a regenerated chunk / rebuilt final reloads in the player.
    const bust = (url) => url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();

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
        el.className = 'chunk-asr-badge inline-flex rounded-md border px-2 py-0.5 text-xs ' + (ASR_TONE[info.tone] || ASR_TONE.bad);
        el.textContent = info.text;
        if (info.title) el.title = info.title; else el.removeAttribute('title');
    };
    const setChunkStatus = (card, status) => {
        badge(card.querySelector('.chunk-status'), status, 'chunk-status ');
        // The verdict is for the current audio; clear it once the chunk is no
        // longer completed (edited / retuned / failed). It returns on regenerate.
        if (status !== 'completed') setChunkAsrBadge(card, null);
    };
    const setProjectStatus = (status) => badge(projectStatus, status);

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
            audio.classList.remove('hidden');
            audio.play().catch(() => {});
            endBusy(btn);
            card.querySelector('.chunk-generate').textContent = '▶ Regenerate';
            card.querySelector('.chunk-tune-keep')?.classList.add('hidden'); // this take replaces any pending preview
            renderTakes(card, data); // the new take joins the history (and becomes selected)
            refreshSeams(); // may reveal an inline seam preview next to a generated neighbor
        } catch (err) {
            setChunkStatus(card, 'failed');
            endBusy(btn);
            throw err;
        }
    }

    const generateChunk = (card) =>
        runGeneration(card, card.dataset.generateUrl, card.querySelector('.chunk-generate'), 'Generating…');

    async function generateAll() {
        const cards = [...root.querySelectorAll('.studio-chunk')]
            .filter((c) => c.querySelector('.chunk-status').textContent.trim() !== 'completed');
        if (!cards.length) {
            setStatus(finalStatus, 'Every chunk is already generated — rebuild to stitch.', 'ok');
            return;
        }
        startBusy(generateAllBtn, 'Generating…');
        // Space out the stream of predictions: generation is already sequential,
        // but a small gap between chunks makes a burst less likely to spin up cold
        // GPU replicas on Replicate (which can fail with transient CUDA asserts).
        const paceMs = Math.max(0, Number(root.dataset.generatePaceMs) || 0);
        let done = 0;
        let failed = 0;
        for (const [i, card] of cards.entries()) {
            try {
                await generateChunk(card);
                done++;
            } catch (_) {
                failed++;
            }
            setStatus(finalStatus, `Generated ${done}/${cards.length}${failed ? ` · ${failed} failed` : ''}…`);
            if (paceMs && i < cards.length - 1) {
                await sleep(paceMs);
            }
        }
        endBusy(generateAllBtn);
        setStatus(finalStatus, failed
            ? `✗ ${failed} chunk(s) failed — retry them, then rebuild.`
            : `✓ All ${done} chunk(s) generated — rebuild to stitch.`, failed ? 'error' : 'ok');
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
            finalAudio.src = bust(finalUrl);
            finalAudio.classList.remove('hidden');
            finalAudio.play().catch(() => {});
            downloadLink.classList.remove('hidden');
            setProjectStatus('ready');
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

    // Show the inline "Preview stitch" connector only between two generated chunks.
    function refreshSeams() {
        root.querySelectorAll('.chunk-seam').forEach((seam) => {
            const prev = root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.prev}"]`);
            const next = root.querySelector(`.studio-chunk[data-chunk-id="${seam.dataset.next}"]`);
            seam.classList.toggle('hidden', !(isChunkCompleted(prev) && isChunkCompleted(next)));
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

        const audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'none';
        audio.className = 'h-8 w-56 max-w-full';
        audio.src = take.audio_url;

        const meta = document.createElement('div');
        meta.className = 'flex min-w-0 flex-col text-xs text-zinc-500';
        const line1 = document.createElement('span');
        line1.className = take.selected ? 'text-emerald-300' : 'text-zinc-400';
        line1.textContent = take.source + (take.selected ? ' · selected' : '');
        const line2 = document.createElement('span');
        line2.textContent = take.tuning_label + (take.created_human ? ' · ' + take.created_human : '');
        meta.append(line1, line2);
        if (take.asr_badge) {
            const b = document.createElement('span');
            b.className = 'mt-0.5 inline-flex w-fit rounded-md border px-1.5 py-0.5 text-[11px] '
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

        li.append(audio, meta, actions);
        return li;
    }

    function renderTakes(card, data) {
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
            audio.classList.remove('hidden');
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
        if (!window.confirm('Delete this take permanently? This cannot be undone.')) return;
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
        let previewExaggeration = '';
        let previewCfg = '';
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
        card.querySelector('.chunk-generate').addEventListener('click', () => generateChunk(card).catch(() => {}));

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

        // A/B preview: audition the typed native knobs (saved as a non-selected take).
        card.querySelector('.chunk-tune-preview')?.addEventListener('click', async () => {
            const btn = card.querySelector('.chunk-tune-preview');
            const exaggeration = card.querySelector('.chunk-exaggeration').value;
            const cfg = card.querySelector('.chunk-cfg').value;
            const body = new URLSearchParams();
            if (exaggeration !== '') body.set('exaggeration', exaggeration);
            if (cfg !== '') body.set('cfg_weight', cfg);
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
                previewExaggeration = exaggeration;
                previewCfg = cfg;
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
                if (previewExaggeration !== '') fd.append('exaggeration', previewExaggeration);
                if (previewCfg !== '') fd.append('cfg_weight', previewCfg);
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
                audio.classList.remove('hidden');
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
        card.querySelector('.chunk-tune-save')?.addEventListener('click', async () => {
            const btn = card.querySelector('.chunk-tune-save');
            const exaggeration = card.querySelector('.chunk-exaggeration').value;
            const cfg = card.querySelector('.chunk-cfg').value;
            startBusy(btn, 'Saving…');
            try {
                const res = await fetch(card.dataset.tuningUrl, {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        exaggeration: exaggeration === '' ? null : Number(exaggeration),
                        cfg_weight: cfg === '' ? null : Number(cfg),
                    }),
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
        card.querySelector('.chunk-exaggeration').addEventListener('input', invalidatePreview);
        card.querySelector('.chunk-cfg').addEventListener('input', invalidatePreview);
        card.querySelector('.chunk-text').addEventListener('input', invalidatePreview);
        card.querySelector('.chunk-voice').addEventListener('change', invalidatePreview);

        // Track dirty state as the user types; Revert restores the saved text.
        card.querySelector('.chunk-text').addEventListener('input', () => setDirty(card, isDirty(card)));
        card.querySelector('.chunk-revert').addEventListener('click', () => {
            const textarea = card.querySelector('.chunk-text');
            textarea.value = textarea.dataset.original;
            setDirty(card, false);
        });
    });

    initTuningKnobs(root); // wire every per-chunk Exaggeration / CFG-Pace slider

    generateAllBtn.addEventListener('click', generateAll);
    rebuildBtn.addEventListener('click', rebuild);

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
                && !confirm('You have unsaved chunk edits that will be lost when the list reloads. Insert anyway?')) {
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
    const heading = document.querySelector('h1');

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
            document.title = `${data.title} — Bespoken TTS`;
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
                    document.title = `${data.title} — Bespoken TTS`;
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

    refreshSeams();
}

initStudioProject();

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

// Back/forward bfcache can restore the frozen "running…" DOM. Reset it so the
// buttons aren't stuck mid-spin when the user navigates back to the page.
window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    document.querySelectorAll('[data-health-run]').forEach((b) => {
        if (b.dataset.originalText) b.textContent = b.dataset.originalText;
        b.classList.remove('pointer-events-none', 'opacity-50');
        b.removeAttribute('aria-disabled');
    });
    document.querySelector('[data-health-results]')?.classList.remove('opacity-40', 'pointer-events-none');
});

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
    const finalUrl = document.getElementById('gb-final-url');
    const manifestEl = document.getElementById('gb-manifest');
    const rerollsEl = document.getElementById('gb-rerolls');
    const chunksEl = document.getElementById('gb-chunks');

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
            finalAudio.classList.remove('hidden');
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
    const POLL_MS = 2500;
    const POLL_MAX_MS = 10 * 60 * 1000;
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    const poll = async (statusUrl, t0) => {
        for (;;) {
            if (Date.now() - t0 > POLL_MAX_MS) throw new Error('Timed out waiting for the run.');
            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error(await errorMessage(res));
            const body = await res.json();
            if (body.status === 'completed') return body.result || {};
            if (body.status === 'failed') throw new Error(body.error || 'The run failed.');
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
