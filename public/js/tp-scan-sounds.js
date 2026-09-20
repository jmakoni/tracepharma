/**
 * Zebra/Honeywell-style piezo buzzer tones (Web Audio API).
 * Square wave only — never sine. No audio files / <audio> / musical chimes.
 *
 * Exact defaults (tweak against a real handheld):
 *   goodRead  — square 2700 Hz, 100 ms, peak 0.28, attack 4 ms
 *   error     — square 250 Hz double razz: 80 ms / 60 ms gap / 80 ms, peak 0.26
 *   warning   — square 1800 Hz × 2, 70 ms each, 70 ms gap, peak 0.22
 *   powerUp   — square 1200→1800→2400 Hz, 70 ms each, 50 ms gap, peak 0.2
 */
(() => {
    if (window.__tpScanSounds) {
        return;
    }

    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    let ctx = null;
    /** @type {{ osc: OscillatorNode, gain: GainNode }[]} */
    let active = [];

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

    function stopActive() {
        const now = ctx ? ctx.currentTime : 0;

        for (const node of active) {
            try {
                node.gain.gain.cancelScheduledValues(now);
                node.gain.gain.setValueAtTime(Math.max(node.gain.gain.value, 0.0001), now);
                node.gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.008);
                node.osc.stop(now + 0.02);
            } catch (_) {
                // already stopped
            }

            try {
                node.osc.disconnect();
                node.gain.disconnect();
            } catch (_) {
                // already disconnected
            }
        }

        active = [];
    }

    /**
     * Schedule one square piezo beep. Attack 2–5 ms, soft exponential edges (no click).
     */
    function scheduleBeep(frequency, durationMs, when, peakGain) {
        const audio = context();
        if (! audio) {
            return;
        }

        const start = Math.max(audio.currentTime, when);
        const seconds = durationMs / 1000;
        const attack = 0.004;
        const osc = audio.createOscillator();
        const gain = audio.createGain();

        osc.type = 'square';
        osc.frequency.setValueAtTime(frequency, start);
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(peakGain, start + attack);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + seconds);
        osc.connect(gain);
        gain.connect(audio.destination);
        osc.start(start);
        osc.stop(start + seconds + 0.02);

        const entry = { osc, gain };
        active.push(entry);
        osc.onended = () => {
            active = active.filter((n) => n !== entry);
            try {
                osc.disconnect();
                gain.disconnect();
            } catch (_) {
                // ignore
            }
        };
    }

    function playGoodRead() {
        unlock();
        stopActive();
        const audio = context();
        if (! audio) {
            return;
        }

        scheduleBeep(2700, 100, audio.currentTime, 0.28);
    }

    function playError() {
        unlock();
        stopActive();
        const audio = context();
        if (! audio) {
            return;
        }

        const start = audio.currentTime;
        scheduleBeep(250, 80, start, 0.26);
        scheduleBeep(250, 80, start + 0.14, 0.26); // 80 ms + 60 ms gap
    }

    function playWarning() {
        unlock();
        stopActive();
        const audio = context();
        if (! audio) {
            return;
        }

        const start = audio.currentTime;
        scheduleBeep(1800, 70, start, 0.22);
        scheduleBeep(1800, 70, start + 0.14, 0.22); // 70 ms + 70 ms gap
    }

    function playPowerUp() {
        unlock();
        stopActive();
        const audio = context();
        if (! audio) {
            return;
        }

        const start = audio.currentTime;
        scheduleBeep(1200, 70, start, 0.2);
        scheduleBeep(1800, 70, start + 0.12, 0.2); // 70 + 50 ms gap
        scheduleBeep(2400, 70, start + 0.24, 0.2);
    }

    function play(tone) {
        if (tone === 'ok' || tone === 'success' || tone === 'receive') {
            playGoodRead();

            return;
        }

        if (tone === 'warn') {
            playWarning();

            return;
        }

        if (tone === 'error') {
            playError();
        }
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

    window.__tpScanSounds = {
        play,
        unlock,
        playGoodRead,
        playError,
        playWarning,
        playPowerUp,
    };

    window.tpScannerTones = {
        goodRead: playGoodRead,
        error: playError,
        warning: playWarning,
        powerUp: playPowerUp,
    };
})();
