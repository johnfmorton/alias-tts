// Lightweight dashboard interactions — no framework.

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
    }
});
