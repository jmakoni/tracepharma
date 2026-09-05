(() => {
    const config = () => window.FilamentUiExtras || {};

    const prefersReducedMotion = () =>
        window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;

    /* -------------------------- Loading bar -------------------------- */
    let loadingCount = 0;
    let barEl = null;
    let finishTimer = null;

    const ensureBar = () => {
        if (barEl) {
            return barEl;
        }

        barEl = document.querySelector('.fi-uie-loading-bar');

        return barEl;
    };

    const startLoading = () => {
        if (!config().loadingBar) {
            return;
        }

        const bar = ensureBar();

        if (!bar) {
            return;
        }

        loadingCount += 1;
        clearTimeout(finishTimer);
        bar.classList.remove('is-finishing');
        bar.classList.add('is-active');
        bar.setAttribute('aria-hidden', 'false');
    };

    const stopLoading = () => {
        if (!config().loadingBar) {
            return;
        }

        loadingCount = Math.max(0, loadingCount - 1);

        if (loadingCount > 0) {
            return;
        }

        const bar = ensureBar();

        if (!bar) {
            return;
        }

        bar.classList.add('is-finishing');
        bar.classList.remove('is-active');

        finishTimer = setTimeout(() => {
            bar.classList.remove('is-finishing');
            bar.setAttribute('aria-hidden', 'true');
        }, 260);
    };

    /* ----------------------- Favicon spinner ------------------------- */
    let originalFaviconHref = null;
    let faviconLink = null;
    let faviconRequestCount = 0;

    const getPrimaryColor = () => {
        const styles = getComputedStyle(document.documentElement);
        return (
            styles.getPropertyValue('--primary-500').trim() ||
            styles.getPropertyValue('--color-primary-500').trim() ||
            '#51bc8f'
        );
    };

    const buildSpinnerDataUrl = (color) => {
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32"><circle cx="16" cy="16" r="12" fill="none" stroke="${color}" stroke-width="4" stroke-linecap="round" stroke-dasharray="60" stroke-dashoffset="20"><animateTransform attributeName="transform" type="rotate" from="0 16 16" to="360 16 16" dur="0.8s" repeatCount="indefinite"/></circle></svg>`;

        return `data:image/svg+xml,${encodeURIComponent(svg)}`;
    };

    const ensureFaviconLink = () => {
        if (faviconLink) {
            return faviconLink;
        }

        faviconLink =
            document.querySelector('link[rel="icon"]') ||
            document.querySelector('link[rel="shortcut icon"]');

        if (!faviconLink) {
            faviconLink = document.createElement('link');
            faviconLink.rel = 'icon';
            document.head.appendChild(faviconLink);
        }

        if (originalFaviconHref === null) {
            originalFaviconHref = faviconLink.getAttribute('href');
        }

        return faviconLink;
    };

    const startFaviconSpinner = () => {
        if (!config().faviconSpinner || prefersReducedMotion()) {
            return;
        }

        faviconRequestCount += 1;
        const link = ensureFaviconLink();
        link.setAttribute('href', buildSpinnerDataUrl(getPrimaryColor()));
    };

    const stopFaviconSpinner = () => {
        if (!config().faviconSpinner) {
            return;
        }

        faviconRequestCount = Math.max(0, faviconRequestCount - 1);

        if (faviconRequestCount > 0) {
            return;
        }

        const link = ensureFaviconLink();

        if (originalFaviconHref) {
            link.setAttribute('href', originalFaviconHref);
        }
    };

    const onRequestStart = () => {
        startLoading();
        startFaviconSpinner();
    };

    const onRequestEnd = () => {
        stopLoading();
        stopFaviconSpinner();
    };

    document.addEventListener('livewire:init', () => {
        Livewire.hook('commit', ({ respond }) => {
            onRequestStart();
            respond(() => onRequestEnd());
        });

        Livewire.hook('request', ({ respond }) => {
            onRequestStart();
            respond(() => onRequestEnd());
        });
    });

    document.addEventListener('livewire:navigating', onRequestStart);
    document.addEventListener('livewire:navigated', onRequestEnd);

    /* -------------------- Disabled button shake ---------------------- */
    document.addEventListener(
        'click',
        (event) => {
            if (prefersReducedMotion()) {
                return;
            }

            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const button = target.closest('button, .fi-btn, .fi-icon-btn, [role="button"]');

            if (!button) {
                return;
            }

            const isDisabled =
                button.hasAttribute('disabled') ||
                button.getAttribute('aria-disabled') === 'true' ||
                button.classList.contains('fi-disabled');

            if (!isDisabled) {
                return;
            }

            // Do not shake submit controls that are merely busy / wire:loading.
            if (button.getAttribute('type') === 'submit' && !button.hasAttribute('disabled')) {
                return;
            }

            button.classList.remove('fi-uie-disabled-shake');
            // Force reflow so repeated clicks re-trigger animation.
            void button.offsetWidth;
            button.classList.add('fi-uie-disabled-shake');

            window.setTimeout(() => {
                button.classList.remove('fi-uie-disabled-shake');
            }, 400);
        },
        true,
    );

    /* --------------- Sticky table horizontal drag -------------------- */
    const initStickyTableScroll = (root = document) => {
        if (!config().stickyTableActions) {
            return;
        }

        root.querySelectorAll('.fi-ta').forEach((tableRoot) => {
            tableRoot.classList.add('fi-uie-sticky-table-actions');

            const scroller =
                tableRoot.querySelector('.fi-ta-content') ||
                tableRoot.querySelector('.fi-ta-table-ctn');

            if (!scroller || scroller.dataset.uieScrollBound === '1') {
                return;
            }

            scroller.dataset.uieScrollBound = '1';

            let isDragging = false;
            let startX = 0;
            let scrollLeft = 0;

            scroller.addEventListener('wheel', (event) => {
                if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) {
                    return;
                }

                if (event.shiftKey || scroller.scrollWidth > scroller.clientWidth + 1) {
                    if (event.shiftKey) {
                        scroller.scrollLeft += event.deltaY;
                        event.preventDefault();
                    }
                }
            }, { passive: false });

            scroller.addEventListener('mousedown', (event) => {
                if (event.button !== 0) {
                    return;
                }

                if (event.target.closest('a, button, input, select, textarea, label, [role="button"]')) {
                    return;
                }

                isDragging = true;
                startX = event.pageX - scroller.offsetLeft;
                scrollLeft = scroller.scrollLeft;
                scroller.classList.add('is-dragging');
            });

            window.addEventListener('mouseup', () => {
                isDragging = false;
                scroller.classList.remove('is-dragging');
            });

            window.addEventListener('mousemove', (event) => {
                if (!isDragging) {
                    return;
                }

                const x = event.pageX - scroller.offsetLeft;
                scroller.scrollLeft = scrollLeft - (x - startX);
            });
        });
    };

    document.addEventListener('DOMContentLoaded', () => initStickyTableScroll());
    document.addEventListener('livewire:navigated', () => initStickyTableScroll());
    document.addEventListener('livewire:init', () => {
        Livewire.hook('morph.updated', () => initStickyTableScroll());
    });
})();
