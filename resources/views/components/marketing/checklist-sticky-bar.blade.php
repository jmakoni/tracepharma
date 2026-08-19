@props([
    'scrollThreshold' => 0.3,
])

<div
    x-data="{
        visible: false,
        dismissed: sessionStorage.getItem('tracepharma_checklist_bar_dismissed') === '1',
        init() {
            if (this.dismissed) return;
            const onScroll = () => {
                const doc = document.documentElement;
                const scrollable = doc.scrollHeight - window.innerHeight;
                if (scrollable <= 0) return;
                this.visible = (window.scrollY / scrollable) >= {{ $scrollThreshold }};
            };
            onScroll();
            window.addEventListener('scroll', onScroll, { passive: true });
        },
        dismiss() {
            this.dismissed = true;
            this.visible = false;
            sessionStorage.setItem('tracepharma_checklist_bar_dismissed', '1');
        },
    }"
    x-show="visible && !dismissed"
    x-cloak
    x-transition
    class="fixed inset-x-0 bottom-0 z-40 border-t border-tp-accent-500/30 bg-tp-surface/95 px-4 py-3 backdrop-blur-md sm:px-6"
    role="region"
    aria-label="Provider checklist download"
>
    <div class="mx-auto flex max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-tp-muted">
            <span class="font-semibold text-tp-ink">Evaluating DSCSA providers?</span>
            Download our checklist of questions to ask before you sign.
        </p>
        <div class="flex shrink-0 flex-wrap items-center gap-3">
            <a href="{{ route('marketing.compare.checklist.pdf') }}" class="tp-btn-primary text-sm">Download PDF</a>
            <a href="{{ route('marketing.compare.checklist') }}" class="text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">View online</a>
            <button type="button" @click="dismiss()" class="rounded-sm px-2 py-1 text-sm text-tp-muted hover:text-tp-ink" aria-label="Dismiss">Dismiss</button>
        </div>
    </div>
</div>
