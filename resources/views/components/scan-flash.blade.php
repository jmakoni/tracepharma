<div
    x-data="{
        flashTone: null,
        flashTimeout: null,
        vibrate(ms) {
            try {
                if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
                    navigator.vibrate(ms);
                }
            } catch (e) {
                // permission / unsupported
            }
        },
        showFlash(tone, ms = 320) {
            if (! tone) {
                return;
            }
            this.flashTone = tone;
            clearTimeout(this.flashTimeout);
            this.flashTimeout = setTimeout(() => { this.flashTone = null; }, ms);
        },
        onScanResult(detail) {
            const tone = detail?.tone;
            if (tone === 'receive' || tone === 'receive-ok') {
                this.vibrate(20);
                this.showFlash('success', 700);
            } else if (tone === 'ok' || tone === 'success') {
                this.vibrate(20);
                this.showFlash('success', 220);
            } else if (tone === 'error') {
                this.vibrate(80);
                this.showFlash('error', 320);
            }
        },
    }"
    x-on:scan-success.window="vibrate(20); showFlash('success', 220)"
    x-on:scan-error.window="vibrate(80); showFlash('error', 320)"
    x-on:scan-result.window="onScanResult($event.detail)"
    x-show="flashTone !== null"
    x-cloak
    x-transition.opacity.duration.150ms
    class="tp-scan-flash-host pointer-events-none fixed inset-0 z-[100]"
    aria-live="assertive"
>
    <div x-show="flashTone === 'success'" class="tp-scan-flash tp-scan-flash--success absolute inset-0"></div>
    <div x-show="flashTone === 'error'" class="tp-scan-flash tp-scan-flash--error absolute inset-0"></div>
</div>
