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

    els.wholeBtn?.addEventListener('click', () =>
        generate(urls.synthesize, normalizedText, els.wholeAudio, els.wholeBtn, 'Generating whole…'));
    els.stitchBtn?.addEventListener('click', () =>
        generate(urls.stitch, normalizedText, els.wholeAudio, els.stitchBtn, 'Stitching…'));
}

initStudio();

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

    async function generateChunk(card) {
        const textarea = card.querySelector('.chunk-text');
        const genBtn = card.querySelector('.chunk-generate');
        startBusy(genBtn, 'Generating…');
        try {
            if (textarea.value !== textarea.dataset.original) {
                // Persist the edit first. If that split the chunk, the page is
                // reloading — don't generate the now-orphaned original chunk.
                if (await patchChunk(card)) return;
            }
            const res = await fetch(card.dataset.generateUrl, {
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
            endBusy(genBtn);
            genBtn.textContent = '▶ Regenerate';
            refreshSeams(); // may reveal an inline seam preview next to a generated neighbor
        } catch (err) {
            setChunkStatus(card, 'failed');
            endBusy(genBtn);
            throw err;
        }
    }

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
