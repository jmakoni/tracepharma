/**
 * Warehouse scan beeps for Livewire scan-result / scan-success / scan-error.
 * Success: short high beep. Error: two low notes. Warn and unknown: silent.
 */
(() => {
    if (window.__tpScanSounds) {
        return;
    }

    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    let ctx = null;

    function context() {
        if (! AudioCtx) {
            return null;
        }

        if (! ctx) {
            ctx = new AudioCtx();
        }

        return ctx;
    }

    function unlock() {
        const audio = context();
        if (! audio || audio.state !== 'suspended') {
            return;
        }

        audio.resume().catch(() => {});
    }

    function beep(frequency, durationMs, type, when) {
        const audio = context();
        if (! audio) {
            return;
        }

        const start = Math.max(audio.currentTime, when ?? audio.currentTime);
        const seconds = durationMs / 1000;
        const osc = audio.createOscillator();
        const gain = audio.createGain();

        osc.type = type;
        osc.frequency.setValueAtTime(frequency, start);
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.18, start + 0.008);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + seconds);
        osc.connect(gain);
        gain.connect(audio.destination);
        osc.start(start);
        osc.stop(start + seconds + 0.02);
    }

    function play(tone) {
        unlock();

        if (tone === 'ok' || tone === 'success') {
            beep(880, 70, 'sine');

            return;
        }

        if (tone !== 'error') {
            return;
        }

        const audio = context();
        const start = audio ? audio.currentTime : 0;
        beep(220, 90, 'square', start);
        beep(165, 90, 'square', start + 0.1);
    }

    function toneFromEvent(event) {
        if (event.type === 'scan-success') {
            return 'success';
        }

        if (event.type === 'scan-error') {
            return 'error';
        }

        const detail = event.detail;

        if (detail && typeof detail.tone === 'string') {
            return detail.tone;
        }

        if (Array.isArray(detail) && detail[0] && typeof detail[0].tone === 'string') {
            return detail[0].tone;
        }

        return null;
    }

    window.addEventListener('pointerdown', unlock, { passive: true });
    window.addEventListener('keydown', unlock, { passive: true });

    ['scan-result', 'scan-success', 'scan-error'].forEach((name) => {
        window.addEventListener(name, (event) => {
            play(toneFromEvent(event));
        });
    });

    window.__tpScanSounds = { play, unlock };
})();
