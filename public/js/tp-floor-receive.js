(() => {
    const register = () => {
        if (typeof window.Alpine === 'undefined' || typeof window.Alpine.data !== 'function') {
            return false;
        }

        if (window.__tpFloorReceiveRegistered) {
            return true;
        }

        window.Alpine.data('tpFloorReceive', (config = {}) => ({
            cartOpen: false,
            cameraOn: false,
            starting: false,
            scanner: null,
            cameraError: null,
            lastFocusEl: null,
            libraryUrl: config.libraryUrl || '',

            resolveHtml5Qrcode() {
                return window.Html5Qrcode
                    || window.__Html5QrcodeLibrary__?.Html5Qrcode
                    || null;
            },

            async ensureLibrary() {
                if (this.resolveHtml5Qrcode()) {
                    return this.resolveHtml5Qrcode();
                }

                await new Promise((resolve, reject) => {
                    const existing = document.querySelector('script[data-tp-html5-qrcode]');
                    if (existing) {
                        existing.addEventListener('load', () => resolve());
                        existing.addEventListener('error', () => reject(new Error('Camera library failed to load')));
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = this.libraryUrl;
                    script.async = true;
                    script.dataset.tpHtml5Qrcode = '1';
                    script.onload = () => resolve();
                    script.onerror = () => reject(new Error('Camera library failed to load'));
                    document.head.appendChild(script);
                });

                const ctor = this.resolveHtml5Qrcode();
                if (! ctor) {
                    throw new Error('Camera library loaded but Html5Qrcode is missing');
                }

                return ctor;
            },

            focusables(root) {
                if (! root) {
                    return [];
                }

                const selector = [
                    'button:not([disabled])',
                    'a[href]',
                    'input:not([disabled])',
                    'select:not([disabled])',
                    'textarea:not([disabled])',
                ].join(', ');

                return [...root.querySelectorAll(selector)]
                    .filter((el) => el.offsetParent !== null || el === document.activeElement);
            },

            trapTab(e, root) {
                if (e.key !== 'Tab') {
                    return;
                }

                const list = this.focusables(root);
                if (list.length === 0) {
                    e.preventDefault();
                    return;
                }

                const first = list[0];
                const last = list[list.length - 1];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (! e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            },

            openCart() {
                this.lastFocusEl = document.activeElement;
                this.cartOpen = true;
                this.$nextTick(() => this.$refs.sheetClose?.focus());
            },

            closeCart() {
                this.cartOpen = false;
                this.$nextTick(() => {
                    (this.lastFocusEl || this.$refs.cartFab || this.$refs.scanInput)?.focus?.();
                    this.lastFocusEl = null;
                });
            },

            async toggleCamera() {
                if (this.cameraOn || this.starting) {
                    await this.stopCamera();
                    return;
                }

                this.cameraError = null;
                this.starting = true;
                this.lastFocusEl = document.activeElement;
                this.cameraOn = true;

                try {
                    await this.$nextTick();
                    this.$refs.cameraClose?.focus();
                    await new Promise((r) => requestAnimationFrame(() => r()));

                    const Html5Qrcode = await this.ensureLibrary();
                    const elId = 'tp-floor-qr-reader';
                    const host = document.getElementById(elId);
                    if (! host) {
                        throw new Error('Camera view missing from page');
                    }

                    host.innerHTML = '';
                    this.scanner = new Html5Qrcode(elId);

                    const formats = window.Html5QrcodeSupportedFormats
                        || window.__Html5QrcodeLibrary__?.Html5QrcodeSupportedFormats
                        || null;

                    const config = {
                        fps: 10,
                        qrbox: (viewfinderWidth, viewfinderHeight) => {
                            const edge = Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.72);
                            return { width: edge, height: edge };
                        },
                        aspectRatio: 1.333,
                    };

                    if (formats) {
                        config.formatsToSupport = [
                            formats.QR_CODE,
                            formats.DATA_MATRIX,
                            formats.CODE_128,
                            formats.CODE_39,
                            formats.EAN_13,
                            formats.EAN_8,
                            formats.UPC_A,
                            formats.UPC_E,
                        ].filter(Boolean);
                    }

                    await this.scanner.start(
                        { facingMode: 'environment' },
                        config,
                        (decoded) => {
                            if (! decoded) {
                                return;
                            }

                            this.$wire.call('stageScan', decoded);
                            this.stopCamera();
                        },
                        () => {},
                    );
                } catch (e) {
                    const raw = String(e?.message || '');
                    if (/NotAllowedError|Permission|denied/i.test(raw)) {
                        this.cameraError = 'Camera permission blocked — allow camera in browser settings, or use the wedge scanner.';
                    } else if (/secure|https|getUserMedia/i.test(raw)) {
                        this.cameraError = 'Camera needs a secure connection (HTTPS) — use the wedge scanner instead.';
                    } else {
                        this.cameraError = 'Camera unavailable — use the wedge scanner, or try again.';
                    }
                    await this.stopCamera();
                } finally {
                    this.starting = false;
                }
            },

            async stopCamera() {
                try {
                    if (this.scanner) {
                        const state = this.scanner.getState?.();
                        if (state === undefined || state === 2 || state === 'SCANNING') {
                            await this.scanner.stop();
                        }
                        await this.scanner.clear();
                    }
                } catch (e) {
                    // ignore stop races
                }

                this.scanner = null;
                this.cameraOn = false;
                this.starting = false;

                const host = document.getElementById('tp-floor-qr-reader');
                if (host) {
                    host.innerHTML = '';
                }

                this.$nextTick(() => {
                    (this.lastFocusEl || this.$refs.cameraBtn || this.$refs.scanInput)?.focus?.();
                    this.lastFocusEl = null;
                });
            },

            focusScan() {
                this.$refs.scanInput?.focus();
            },
        }));

        window.__tpFloorReceiveRegistered = true;
        return true;
    };

    document.addEventListener('alpine:init', () => {
        register();
    });

    // Filament may have already fired alpine:init before this script loads.
    if (window.Alpine) {
        register();
    }
})();
