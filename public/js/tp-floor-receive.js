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
            connectionError: null,
            lastFocusEl: null,
            libraryUrl: config.libraryUrl || '',
            confirmMethod: config.confirmMethod || 'confirmScanInput',
            cameraScanPace: config.cameraScanPace || 'balanced',
            cooldownMs: Number(config.cooldownMs) > 0 ? Number(config.cooldownMs) : 1000,
            fps: Number(config.fps) > 0 ? Number(config.fps) : 24,
            frameRateIdeal: Number(config.frameRateIdeal) > 0 ? Number(config.frameRateIdeal) : 24,
            frameRateMax: Number(config.frameRateMax) > 0 ? Number(config.frameRateMax) : 30,
            torchSupported: false,
            torchOn: false,

            init() {
                const syncOnline = () => {
                    this.connectionError = navigator.onLine ? null : 'Scan needs a connection';
                };
                syncOnline();
                window.addEventListener('online', syncOnline);
                window.addEventListener('offline', syncOnline);

                if (window.Livewire) {
                    window.Livewire.hook('request', ({ fail }) => {
                        fail(({ status }) => {
                            if (! navigator.onLine || status === 0) {
                                this.connectionError = 'Scan needs a connection';
                            }
                        });
                    });
                }
            },

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
                        if (this.resolveHtml5Qrcode()) {
                            resolve();

                            return;
                        }

                        existing.addEventListener('load', () => resolve());
                        existing.addEventListener('error', () => reject(new Error('Camera library failed to load')));

                        // Preloaded sync scripts may have finished before listeners attach.
                        if (this.resolveHtml5Qrcode()) {
                            resolve();
                        }

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

            applyPaceConfig(next = {}) {
                if (next.cameraScanPace) {
                    this.cameraScanPace = next.cameraScanPace;
                }
                if (Number(next.cooldownMs) > 0) {
                    this.cooldownMs = Number(next.cooldownMs);
                }
                if (Number(next.fps) > 0) {
                    this.fps = Number(next.fps);
                }
                if (Number(next.frameRateIdeal) > 0) {
                    this.frameRateIdeal = Number(next.frameRateIdeal);
                }
                if (Number(next.frameRateMax) > 0) {
                    this.frameRateMax = Number(next.frameRateMax);
                }
            },

            async setScanPace(pace) {
                if (! pace || pace === this.cameraScanPace || this.starting) {
                    return;
                }

                const previousFps = this.fps;
                const previousIdeal = this.frameRateIdeal;
                const previousMax = this.frameRateMax;

                try {
                    const next = await this.$wire.setFloorCameraScanPace(pace);
                    if (next && typeof next === 'object') {
                        this.applyPaceConfig(next);
                    } else {
                        this.cameraScanPace = pace;
                    }
                } catch (e) {
                    this.cameraError = 'Could not save scan pace — try again.';
                    return;
                }

                const fpsChanged = previousFps !== this.fps
                    || previousIdeal !== this.frameRateIdeal
                    || previousMax !== this.frameRateMax;

                if (this.cameraOn && fpsChanged) {
                    await this.stopCamera();
                    await this.toggleCamera();
                }
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

            pinAimWindowToTop(Html5Qrcode) {
                const proto = Html5Qrcode?.prototype;
                if (! proto || proto.__tpAimPinned || typeof proto.possiblyInsertShadingElement !== 'function') {
                    return;
                }

                const inset = 6;
                const originalShade = proto.possiblyInsertShadingElement;
                proto.possiblyInsertShadingElement = function (parent, viewW, viewH, qrbox) {
                    originalShade.call(this, parent, viewW, viewH, qrbox);

                    const shade = parent?.querySelector?.('#qr-shaded-region');
                    const boxW = Number(qrbox?.width) || 0;
                    const boxH = Number(qrbox?.height) || 0;
                    if (! shade || viewW < 1 || viewH < 1 || boxW < 1 || boxH < 1) {
                        return;
                    }

                    const y = Math.min(inset, Math.max(0, viewH - boxH));
                    const x = Math.max(0, Math.round((viewW - boxW) / 2));
                    shade.style.setProperty('border-top-width', `${y}px`, 'important');
                    shade.style.setProperty('border-bottom-width', `${Math.max(0, viewH - boxH - y)}px`, 'important');
                    shade.style.setProperty('border-left-width', `${x}px`, 'important');
                    shade.style.setProperty('border-right-width', `${Math.max(0, viewW - boxW - x)}px`, 'important');
                };

                const originalBounds = proto.getShadedRegionBounds;
                if (typeof originalBounds === 'function') {
                    proto.getShadedRegionBounds = function (viewW, viewH, qrbox) {
                        const bounds = originalBounds.call(this, viewW, viewH, qrbox);
                        bounds.y = Math.min(inset, Math.max(0, viewH - bounds.height));

                        return bounds;
                    };
                }

                proto.__tpAimPinned = true;
            },

            bindScanWindowResize() {
                if (this._onScanWindowResize) {
                    window.removeEventListener('resize', this._onScanWindowResize);
                }

                this._onScanWindowResize = () => this.placeScanWindowInTopHalf();
                window.addEventListener('resize', this._onScanWindowResize);
            },

            placeScanWindowInTopHalf() {
                const region = this.scanner?.qrRegion;
                const host = document.getElementById('tp-floor-qr-reader');
                const shade = document.getElementById('qr-shaded-region');
                if (! region || ! host || ! shade) {
                    return;
                }

                const viewW = host.clientWidth;
                const viewH = host.clientHeight;
                const boxW = region.width;
                const boxH = region.height;
                if (viewW < 1 || viewH < 1 || boxW < 1 || boxH < 1) {
                    return;
                }

                const bracketInset = 6;
                const y = Math.min(bracketInset, Math.max(0, viewH - boxH));
                const x = Math.max(0, Math.round((viewW - boxW) / 2));
                region.x = x;
                region.y = y;
                shade.style.setProperty('border-top-width', `${y}px`, 'important');
                shade.style.setProperty('border-bottom-width', `${Math.max(0, viewH - boxH - y)}px`, 'important');
                shade.style.setProperty('border-left-width', `${x}px`, 'important');
                shade.style.setProperty('border-right-width', `${Math.max(0, viewW - boxW - x)}px`, 'important');
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
                    this.pinAimWindowToTop(Html5Qrcode);
                    const elId = 'tp-floor-qr-reader';
                    const host = document.getElementById(elId);
                    if (! host) {
                        throw new Error('Camera view missing from page');
                    }

                    host.innerHTML = '';

                    // formatsToSupport is constructor-only in html5-qrcode — start() ignores it.
                    // Without this, ZXing still tries QR/EAN/UPC/Code39 every frame.
                    const formats = window.Html5QrcodeSupportedFormats
                        || window.__Html5QrcodeLibrary__?.Html5QrcodeSupportedFormats
                        || null;
                    const formatsToSupport = formats
                        ? [formats.DATA_MATRIX, formats.CODE_128].filter(Boolean)
                        : null;

                    const ctorConfig = {
                        verbose: false,
                        // Native BarcodeDetector when available (Chrome/Android) — much faster than ZXing.
                        useBarCodeDetectorIfSupported: true,
                    };
                    if (formatsToSupport?.length) {
                        ctorConfig.formatsToSupport = formatsToSupport;
                    }

                    this.scanner = new Html5Qrcode(elId, ctorConfig);

                    // Pharma floor: GS1 DataMatrix (units) + Code128/GS1-128 (SSCC).
                    // Wide ROI; keep camera open between labels (Close / toggle / Escape to stop).
                    const scanConfig = {
                        fps: this.fps,
                        disableFlip: true,
                        qrbox: (viewfinderWidth, viewfinderHeight) => {
                            const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                            const width = Math.floor(minEdge * 0.92);
                            const height = Math.floor(minEdge * 0.40);

                            return { width, height };
                        },
                        videoConstraints: {
                            facingMode: { ideal: 'environment' },
                            width: { ideal: 1280 },
                            height: { ideal: 720 },
                            frameRate: { ideal: this.frameRateIdeal, max: this.frameRateMax },
                        },
                    };

                    await this.scanner.start(
                        { facingMode: 'environment' },
                        scanConfig,
                        (decoded) => {
                            if (! decoded) {
                                return;
                            }

                            const now = performance.now();
                            if (this._confirming || (this._ignoreUntil && now < this._ignoreUntil)) {
                                return;
                            }

                            this._confirming = true;
                            this._ignoreUntil = now + this.cooldownMs;

                            Promise.resolve(this.$wire.call(this.confirmMethod, decoded))
                                .catch(() => {
                                    if (! navigator.onLine) {
                                        this.connectionError = 'Scan needs a connection';
                                    }
                                })
                                .finally(() => {
                                    this._confirming = false;
                                });
                        },
                        () => {},
                    );

                    this.placeScanWindowInTopHalf();
                    this.bindScanWindowResize();
                    this.detectTorch();
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
                if (this._onScanWindowResize) {
                    window.removeEventListener('resize', this._onScanWindowResize);
                    this._onScanWindowResize = null;
                }

                try {
                    if (this.torchOn) {
                        await this.setTorch(false);
                    }
                } catch (e) {
                    // ignore torch races
                }

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
                this._confirming = false;
                this._ignoreUntil = 0;
                this.torchSupported = false;
                this.torchOn = false;

                const host = document.getElementById('tp-floor-qr-reader');
                if (host) {
                    host.innerHTML = '';
                }

                this.$nextTick(() => {
                    (this.lastFocusEl || this.$refs.cameraBtn || this.$refs.scanInput)?.focus?.();
                    this.lastFocusEl = null;
                });
            },

            detectTorch() {
                try {
                    const caps = this.scanner?.getRunningTrackCameraCapabilities?.();
                    const torch = caps?.torchFeature?.();
                    this.torchSupported = Boolean(torch?.isSupported?.());
                } catch (e) {
                    this.torchSupported = false;
                }
            },

            async toggleTorch() {
                await this.setTorch(! this.torchOn);
            },

            async setTorch(on) {
                if (! this.scanner || ! this.torchSupported) {
                    return;
                }

                try {
                    const caps = this.scanner.getRunningTrackCameraCapabilities?.();
                    const torch = caps?.torchFeature?.();
                    if (torch?.isSupported?.()) {
                        await torch.apply(Boolean(on));
                        this.torchOn = Boolean(on);

                        return;
                    }
                } catch (e) {
                    // fall through to constraints
                }

                try {
                    await this.scanner.applyVideoConstraints?.({
                        advanced: [{ torch: Boolean(on) }],
                    });
                    this.torchOn = Boolean(on);
                } catch (e) {
                    this.torchSupported = false;
                    this.torchOn = false;
                }
            },

            focusScan() {
                if (this.cameraOn) {
                    return;
                }

                this.$refs.scanInput?.focus();
            },
        }));

        window.__tpFloorReceiveRegistered = true;

        if (! window.__tpFloorReceiveFocusHook && window.Livewire) {
            window.__tpFloorReceiveFocusHook = true;
            window.Livewire.hook('morph.updated', ({ el }) => {
                if (! el?.closest?.('.tp-floor-receive')) {
                    return;
                }

                const root = el.closest('.tp-floor-receive');
                const data = root?.__x?.$data;
                if (data?.cameraOn) {
                    return;
                }

                requestAnimationFrame(() => {
                    root.querySelector('#floor-scan-input, [x-ref=\"scanInput\"], .tp-floor-receive__scan-input')?.focus?.();
                });
            });
        }

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
