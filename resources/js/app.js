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
// Mirrors App\Services\Tts\ReplicateChatterboxProvider's settings mapping — keep
// in sync: cfg_weight = clamp(stability, 0.2, 1.0); exaggeration = clamp(0.5 +
// style*1.5, 0.25, 2.0). Blank knobs fall back to the EL defaults (0.5 / 0.0).
function chatterboxMapping(stability, style) {
    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
    const s = stability === '' || stability == null ? 0.5 : Number(stability);
    const st = style === '' || style == null ? 0.0 : Number(style);
    if (Number.isNaN(s) || Number.isNaN(st)) return '';
    return `→ cfg_weight ${clamp(s, 0.2, 1).toFixed(2)} · exaggeration ${clamp(0.5 + st * 1.5, 0.25, 2).toFixed(2)}`;
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
        seed: document.getElementById('studio-seed'),
        stability: document.getElementById('studio-stability'),
        style: document.getElementById('studio-style'),
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
        if (els.seed?.value !== '') body.seed = els.seed.value;
        if (els.stability?.value !== '') body.stability = els.stability.value;
        if (els.style?.value !== '') body.style = els.style.value;
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

    function seamBadge(breakAfter) {
        const badge = document.createElement('span');
        const para = breakAfter === 'paragraph';
        badge.className = 'inline-flex rounded-md border px-2 py-0.5 text-xs ' +
            (para ? 'border-amber-500/30 bg-amber-500/10 text-amber-300'
                  : 'border-zinc-700 bg-zinc-800 text-zinc-400');
        badge.textContent = para ? 'paragraph seam' : 'sentence seam';
        return badge;
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
        meta.append(num, count, seamBadge(chunk.breakAfter));

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

    // Live Chatterbox mapping readout for the single knobs (3a).
    const mappingEl = document.getElementById('studio-mapping');
    const renderMapping = () => {
        if (mappingEl) mappingEl.textContent = chatterboxMapping(els.stability?.value, els.style?.value);
    };
    els.stability?.addEventListener('input', renderMapping);
    els.style?.addEventListener('input', renderMapping);
    renderMapping();

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

    const knob = (value, placeholder) => {
        const input = document.createElement('input');
        Object.assign(input, { type: 'number', step: '0.05', min: '0', max: '1', placeholder });
        if (value !== null) input.value = value;
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
    // row's stability/style. Returns null when there's no text to synthesize.
    const rowBody = (state) => {
        const text = els.text.value.trim();
        if (!text) return null;
        const body = new URLSearchParams({ text });
        if (els.voice?.value) body.set('voice', els.voice.value);
        if (state.stabIn.value !== '') body.set('stability', state.stabIn.value);
        if (state.styleIn.value !== '') body.set('style', state.styleIn.value);
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

    function addRow(stability, style) {
        const li = document.createElement('li');
        li.className = 'flex flex-wrap items-center gap-2 rounded-lg border border-zinc-800 bg-zinc-950/40 p-2';

        const pick = document.createElement('input');
        Object.assign(pick, { type: 'radio', name: 'studio-bench-pick', title: 'Pick this setting to save' });
        pick.className = 'accent-emerald-500';

        const stabIn = knob(stability, '0.5');
        const styleIn = knob(style, '0.0');

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

        const mapping = document.createElement('span');
        mapping.className = 'font-mono text-xs text-zinc-500';
        const renderMapping = () => { mapping.textContent = chatterboxMapping(stabIn.value, styleIn.value); };
        stabIn.addEventListener('input', renderMapping);
        styleIn.addEventListener('input', renderMapping);
        renderMapping();

        li.append(pick, field('Stability', stabIn), field('Style', styleIn), mapping, playBtn, audio, remove);

        const state = { stabIn, styleIn, audio, pick };
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
        if (picked.stabIn.value !== '') body.set('stability', picked.stabIn.value);
        if (picked.styleIn.value !== '') body.set('style', picked.styleIn.value);
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
                addRow(chip.dataset.stability || null, chip.dataset.style || null));
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
            chip.dataset.stability = preset.stability ?? '';
            chip.dataset.style = preset.style ?? '';
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
            if (picked.stabIn.value !== '') body.set('stability', picked.stabIn.value);
            if (picked.styleIn.value !== '') body.set('style', picked.styleIn.value);
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

    // Seed with the current default and the README's steadier/more-expressive example.
    addRow(0.5, 0.0);
    addRow(0.8, 0.3);
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
    const setChunkStatus = (card, status) => badge(card.querySelector('.chunk-status'), status, 'chunk-status ');
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
            setProjectStatus(data.project_status);
            const audio = card.querySelector('.chunk-audio');
            audio.src = bust(card.dataset.audioUrl);
            audio.classList.remove('hidden');
            audio.play().catch(() => {});
            endBusy(btn);
            card.querySelector('.chunk-generate').textContent = '▶ Regenerate';
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
        let done = 0;
        let failed = 0;
        for (const card of cards) {
            try {
                await generateChunk(card);
                done++;
            } catch (_) {
                failed++;
            }
            setStatus(finalStatus, `Generated ${done}/${cards.length}${failed ? ` · ${failed} failed` : ''}…`);
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

    root.querySelectorAll('.studio-chunk').forEach((card) => {
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

        // A/B preview (3c): audition the typed settings without persisting.
        card.querySelector('.chunk-tune-preview')?.addEventListener('click', async () => {
            const btn = card.querySelector('.chunk-tune-preview');
            const stability = card.querySelector('.chunk-stability').value;
            const style = card.querySelector('.chunk-style').value;
            const body = new URLSearchParams();
            if (stability !== '') body.set('stability', stability);
            if (style !== '') body.set('style', style);
            startBusy(btn, 'Previewing…');
            try {
                const res = await fetch(card.dataset.previewTuningUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'audio/*' },
                    body,
                });
                if (!res.ok) throw new Error(await errorMessage(res));
                playAudio(card.querySelector('.chunk-tune-audio'), await res.blob());
                setStatus(finalStatus, '');
            } catch (err) {
                setStatus(finalStatus, `✗ ${err.message}`, 'error');
            } finally {
                endBusy(btn);
            }
        });

        // Save this chunk's stability/style override; the server marks it stale.
        card.querySelector('.chunk-tune-save')?.addEventListener('click', async () => {
            const btn = card.querySelector('.chunk-tune-save');
            const stability = card.querySelector('.chunk-stability').value;
            const style = card.querySelector('.chunk-style').value;
            startBusy(btn, 'Saving…');
            try {
                const res = await fetch(card.dataset.tuningUrl, {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        stability: stability === '' ? null : Number(stability),
                        style: style === '' ? null : Number(style),
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

        // Track dirty state as the user types; Revert restores the saved text.
        card.querySelector('.chunk-text').addEventListener('input', () => setDirty(card, isDirty(card)));
        card.querySelector('.chunk-revert').addEventListener('click', () => {
            const textarea = card.querySelector('.chunk-text');
            textarea.value = textarea.dataset.original;
            setDirty(card, false);
        });
    });

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
