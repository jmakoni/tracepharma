<div
    x-data="{
        flashTone: null,
        flashTimeout: null,
        showFlash(tone) {
            this.flashTone = tone;
            clearTimeout(this.flashTimeout);
            this.flashTimeout = setTimeout(() => { this.flashTone = null; }, 320);
        },
    }"
    x-on:scan-success.window="showFlash('success')"
    x-on:scan-error.window="showFlash('error')"
    x-on:scan-result.window="showFlash($event.detail?.tone === 'ok' || $event.detail?.tone === 'success' ? 'success' : ($event.detail?.tone === 'error' ? 'error' : null))"
    x-show="flashTone !== null"
    x-cloak
    x-transition.opacity.duration.150ms
    class="pointer-events-none fixed inset-0 z-[100]"
    aria-live="assertive"
>
    <div x-show="flashTone === 'success'" class="tp-scan-flash tp-scan-flash--success absolute inset-0"></div>
    <div x-show="flashTone === 'error'" class="tp-scan-flash tp-scan-flash--error absolute inset-0"></div>
</div>
