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
            zoomSupported: false,
            zoomMin: 1,
            zoomMax: 1,
            zoomValue: 1,
            lockOnEngine: null,
            /** @type {'single'|'continuous'} device-persisted */
            scanMode: 'continuous',
            /** @type {'careful'|'balanced'|'rapid'} device-persisted */
            confidence: 'balanced',
            scanSettingsOpen: false,

            init() {
                this.loadCameraSettings();
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

            cameraSettingsStorageKey() {
                return 'tp.floor.camera.settings';
            },

            loadCameraSettings() {
                try {
                    const raw = window.localStorage?.getItem?.(this.cameraSettingsStorageKey());
                    if (! raw) {
                        return;
                    }

                    const parsed = JSON.parse(raw);
                    if (parsed?.scanMode === 'single' || parsed?.scanMode === 'continuous') {
                        this.scanMode = parsed.scanMode;
                    }
                    if (parsed?.confidence === 'careful'
                        || parsed?.confidence === 'balanced'
                        || parsed?.confidence === 'rapid') {
                        this.confidence = parsed.confidence;
                    }
                } catch (e) {
                    // ignore corrupt storage
                }
            },

            persistCameraSettings() {
                try {
                    window.localStorage?.setItem?.(this.cameraSettingsStorageKey(), JSON.stringify({
                        scanMode: this.scanMode,
                        confidence: this.confidence,
                    }));
                } catch (e) {
                    // private mode / quota
                }
            },

            confidenceHitsRequired() {
                if (this.confidence === 'careful') {
                    return 3;
                }
                if (this.confidence === 'rapid') {
                    return 1;
                }

                return 2;
            },

            confidenceHitGapMs() {
                return this.confidence === 'careful' ? 450 : 0;
            },

            acceptCooldownMs() {
                return this.confidence === 'rapid' ? 400 : 1200;
            },

            setScanMode(mode) {
                if (mode !== 'single' && mode !== 'continuous') {
                    return;
                }

                this.scanMode = mode;
                this.persistCameraSettings();
            },

            setConfidence(level) {
                if (level !== 'careful' && level !== 'balanced' && level !== 'rapid') {
                    return;
                }

                this.confidence = level;
                this.persistCameraSettings();
            },

            /**
             * Count identical ranked rawValues toward accept (confidence gates).
             */
            noteRankedRaw(raw) {
                if (! raw) {
                    this._pendingRaw = null;
                    this._pendingHits = 0;
                    this._pendingHitAt = 0;

                    return;
                }

                const now = performance.now();
                const gap = this.confidenceHitGapMs();

                if (this._pendingRaw !== raw) {
                    this._pendingRaw = raw;
                    this._pendingHits = 1;
                    this._pendingHitAt = now;
                } else if (gap <= 0 || (now - (this._pendingHitAt || 0)) >= gap) {
                    this._pendingHits += 1;
                    this._pendingHitAt = now;
                }

                if (this._pendingHits >= this.confidenceHitsRequired()) {
                    const accepted = this._pendingRaw;
                    this._pendingRaw = null;
                    this._pendingHits = 0;
                    this._pendingHitAt = 0;
                    this.confirmDecoded(accepted);
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

                if (this.cameraOn && fpsChanged && this.lockOnEngine === 'html5') {
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

            aimWindow(viewW, viewH) {
                // Wide enough for SSCC-18 linear; operator aims the hole at the intended symbol.
                const width = Math.max(200, Math.floor(Math.min(viewW * 0.94, viewH * 1.15)));
                const height = Math.max(72, Math.floor(Math.min(viewW, viewH) * 0.32));
                const x = Math.max(0, Math.round((viewW - width) / 2));
                const y = Math.max(0, Math.round((viewH - height) / 2));

                return { x, y, width, height };
            },

            stripSymbologyId(raw) {
                return String(raw || '').replace(/^\][A-Za-z0-9]{2}/, '');
            },

            hasAi00(raw) {
                const s = String(raw || '');
                if (! s) {
                    return false;
                }

                if (/\(00\)\s*\d{18}/.test(s)) {
                    return true;
                }

                const body = this.stripSymbologyId(s);
                if (/(?:^|[\x1d])00\d{18}/.test(body)) {
                    return true;
                }

                const flat = body.replace(/[\x1d\x1e]/g, '');

                return /^00\d{18}/.test(flat);
            },

            hasAi01And21(raw) {
                const s = String(raw || '');
                if (! s) {
                    return false;
                }

                if (/\(01\)\s*\d{14}[\s\S]*\(21\)/.test(s) || /\(21\)[\s\S]*\(01\)\s*\d{14}/.test(s)) {
                    return true;
                }

                const body = this.stripSymbologyId(s);
                const has01 = /(?:^|[\x1d])01\d{14}/.test(body);
                const has21 = /(?:^|[\x1d])21./.test(body);
                if (has01 && has21) {
                    return true;
                }

                const flat = body.replace(/[\x1d\x1e]/g, '');

                return /01\d{14}21./.test(flat);
            },

            isAcceptableFloorSymbol(raw) {
                return this.hasAi00(raw) || this.hasAi01And21(raw);
            },

            normalizeBarcodeFormat(format) {
                const f = String(format || '').toLowerCase().replace(/[\s-]+/g, '_');
                if (f === 'datamatrix') {
                    return 'data_matrix';
                }

                return f;
            },

            pickFloorSymbol(mapped) {
                const inHole = (mapped || []).filter((b) => b.inHole);
                const byArea = (a, b) => b.area - a.area;
                const linearOrDm = (b) => b.format === 'data_matrix' || b.format === 'code_128';

                // 1. GS1 AI (00) SSCC — linear or Data Matrix
                const sscc = inHole.filter((b) => linearOrDm(b) && this.hasAi00(b.rawValue)).sort(byArea);
                if (sscc[0]) {
                    return { symbol: sscc[0], rank: 'sscc_00' };
                }

                // 2. Data Matrix with AI (01)+(21)
                const dm = inHole
                    .filter((b) => b.format === 'data_matrix' && this.hasAi01And21(b.rawValue))
                    .sort(byArea);
                if (dm[0]) {
                    return { symbol: dm[0], rank: 'dm_01_21' };
                }

                // 3. GS1-128 with (01)+(21)
                const c128 = inHole
                    .filter((b) => b.format === 'code_128' && this.hasAi01And21(b.rawValue))
                    .sort(byArea);
                if (c128[0]) {
                    return { symbol: c128[0], rank: 'c128_01_21' };
                }

                return { symbol: null, rank: null };
            },

            confirmDecoded(decoded) {
                if (! decoded) {
                    return;
                }

                // Never (17)+(10)-only; allow SSCC (00) or SGTIN (01)+(21). SOP gates after decode.
                if (! this.isAcceptableFloorSymbol(decoded)) {
                    return;
                }

                const now = performance.now();
                if (this._confirming || (this._ignoreUntil && now < this._ignoreUntil)) {
                    return;
                }

                this._confirming = true;
                this._ignoreUntil = now + this.acceptCooldownMs();

                Promise.resolve(this.$wire.call(this.confirmMethod, decoded))
                    .then(() => {
                        if (this.scanMode === 'single' && this.cameraOn) {
                            return this.stopCamera();
                        }

                        return null;
                    })
                    .catch(() => {
                        if (! navigator.onLine) {
                            this.connectionError = 'Scan needs a connection';
                        }
                    })
                    .finally(() => {
                        this._confirming = false;
                    });
            },

            supportsNativeBarcodeDetector() {
                return typeof window.BarcodeDetector === 'function';
            },

            resetLockOnState() {
                if (this._rafId) {
                    cancelAnimationFrame(this._rafId);
                    this._rafId = 0;
                }
                if (this._mediaStream) {
                    this._mediaStream.getTracks().forEach((t) => {
                        try { t.stop(); } catch (e) { /* ignore */ }
                    });
                    this._mediaStream = null;
                }
                this._video = null;
                this._overlay = null;
                this._scaleEl = null;
                this._detector = null;
                this._track = null;
                this._pendingRaw = null;
                this._pendingHits = 0;
                this._pendingHitAt = 0;
                this.lockOnEngine = null;
                this.zoomSupported = false;
                this.zoomMin = 1;
                this.zoomMax = 1;
                this.zoomValue = 1;
            },

            async requestContinuousAutofocus(track) {
                if (! track || typeof track.getCapabilities !== 'function') {
                    return false;
                }

                try {
                    const caps = track.getCapabilities() || {};
                    const modes = Array.isArray(caps.focusMode) ? caps.focusMode : [];
                    if (! modes.includes('continuous')) {
                        return false;
                    }

                    await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] });

                    return true;
                } catch (e) {
                    try {
                        await track.applyConstraints({ focusMode: 'continuous' });

                        return true;
                    } catch (e2) {
                        return false;
                    }
                }
            },

            bindNativeTrackFeatures(track) {
                this._track = track || null;
                this.torchSupported = false;
                this.zoomSupported = false;
                if (! track || typeof track.getCapabilities !== 'function') {
                    return;
                }

                try {
                    const caps = track.getCapabilities() || {};
                    this.torchSupported = Boolean(caps.torch);
                    if (caps.zoom) {
                        this.zoomSupported = true;
                        this.zoomMin = Number(caps.zoom.min) || 1;
                        this.zoomMax = Number(caps.zoom.max) || this.zoomMin;
                        this.zoomValue = Number(track.getSettings?.()?.zoom) || this.zoomMin;
                    }
                } catch (e) {
                    this.torchSupported = false;
                    this.zoomSupported = false;
                }
            },

            async startNativeLockOn(host) {
                const formats = ['data_matrix', 'code_128', 'qr_code', 'aztec'];
                let detector;
                try {
                    detector = new window.BarcodeDetector({ formats });
                } catch (e) {
                    throw new Error('BarcodeDetector unavailable');
                }

                const stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        frameRate: { ideal: this.frameRateIdeal, max: this.frameRateMax },
                    },
                });

                host.innerHTML = '';
                const root = document.createElement('div');
                root.className = 'tp-floor-lockon';
                const scaleEl = document.createElement('div');
                scaleEl.className = 'tp-floor-lockon__scale';
                const video = document.createElement('video');
                video.className = 'tp-floor-lockon__video';
                video.setAttribute('playsinline', '');
                video.setAttribute('muted', '');
                video.muted = true;
                video.autoplay = true;
                const overlay = document.createElement('canvas');
                overlay.className = 'tp-floor-lockon__overlay';
                scaleEl.appendChild(video);
                scaleEl.appendChild(overlay);
                root.appendChild(scaleEl);
                host.appendChild(root);

                video.srcObject = stream;
                await video.play();

                this._mediaStream = stream;
                this._video = video;
                this._overlay = overlay;
                this._scaleEl = scaleEl;
                this._detector = detector;
                this.lockOnEngine = 'native';
                const track = stream.getVideoTracks()[0] || null;
                this.bindNativeTrackFeatures(track);
                await this.requestContinuousAutofocus(track);
                this.scheduleNativeFrame();
            },

            scheduleNativeFrame() {
                if (! this.cameraOn || this.lockOnEngine !== 'native') {
                    return;
                }

                this._rafId = requestAnimationFrame(() => this.nativeDetectFrame());
            },

            mapBarcodeBox(bb, video, viewW, viewH) {
                const vidW = video.videoWidth || viewW;
                const vidH = video.videoHeight || viewH;
                // object-fit: cover — uniform scale + crop offsets
                const scale = Math.max(viewW / vidW, viewH / vidH);
                const dispW = vidW * scale;
                const dispH = vidH * scale;
                const offsetX = (viewW - dispW) / 2;
                const offsetY = (viewH - dispH) / 2;

                return {
                    x: Number(bb.x || 0) * scale + offsetX,
                    y: Number(bb.y || 0) * scale + offsetY,
                    width: Number(bb.width || 0) * scale,
                    height: Number(bb.height || 0) * scale,
                };
            },

            async nativeDetectFrame() {
                if (! this.cameraOn || this.lockOnEngine !== 'native') {
                    return;
                }

                const video = this._video;
                const overlay = this._overlay;
                const detector = this._detector;
                if (! video || ! overlay || ! detector || video.readyState < 2) {
                    this.scheduleNativeFrame();

                    return;
                }

                const viewW = video.clientWidth || video.videoWidth;
                const viewH = video.clientHeight || video.videoHeight;
                if (viewW < 1 || viewH < 1) {
                    this.scheduleNativeFrame();

                    return;
                }

                if (overlay.width !== viewW || overlay.height !== viewH) {
                    overlay.width = viewW;
                    overlay.height = viewH;
                }

                const hole = this.aimWindow(viewW, viewH);
                const ctx = overlay.getContext('2d');
                ctx.clearRect(0, 0, viewW, viewH);
                ctx.fillStyle = 'rgba(0, 0, 0, 0.48)';
                ctx.fillRect(0, 0, viewW, viewH);
                ctx.clearRect(hole.x, hole.y, hole.width, hole.height);
                ctx.strokeStyle = 'rgba(255, 255, 255, 0.85)';
                ctx.lineWidth = 2;
                ctx.strokeRect(hole.x + 1, hole.y + 1, hole.width - 2, hole.height - 2);

                let barcodes = [];
                try {
                    barcodes = await detector.detect(video);
                } catch (e) {
                    barcodes = [];
                }

                const intersectsHole = (box) => {
                    const right = box.x + box.width;
                    const bottom = box.y + box.height;

                    return box.x < hole.x + hole.width
                        && right > hole.x
                        && box.y < hole.y + hole.height
                        && bottom > hole.y;
                };

                const mapped = (barcodes || []).map((b) => {
                    const box = this.mapBarcodeBox(b.boundingBox || {}, video, viewW, viewH);

                    return {
                        rawValue: String(b.rawValue || ''),
                        format: this.normalizeBarcodeFormat(b.format),
                        box,
                        area: Math.max(0, box.width) * Math.max(0, box.height),
                        inHole: intersectsHole(box),
                    };
                }).filter((b) => b.rawValue !== '' && b.area > 0);

                const picked = this.pickFloorSymbol(mapped);
                const best = picked.symbol;

                if (best) {
                    ctx.strokeStyle = '#22d3ee';
                    ctx.lineWidth = 3;
                    ctx.strokeRect(best.box.x, best.box.y, best.box.width, best.box.height);
                    this.noteRankedRaw(best.rawValue);
                } else {
                    this.noteRankedRaw(null);
                }

                this.scheduleNativeFrame();
            },

            pinAimWindowCentered(Html5Qrcode) {
                const proto = Html5Qrcode?.prototype;
                if (! proto || proto.__tpAimPinned || typeof proto.possiblyInsertShadingElement !== 'function') {
                    return;
                }

                const originalShade = proto.possiblyInsertShadingElement;
                proto.possiblyInsertShadingElement = function (parent, viewW, viewH, qrbox) {
                    originalShade.call(this, parent, viewW, viewH, qrbox);

                    const shade = parent?.querySelector?.('#qr-shaded-region');
                    const boxW = Number(qrbox?.width) || 0;
                    const boxH = Number(qrbox?.height) || 0;
                    if (! shade || viewW < 1 || viewH < 1 || boxW < 1 || boxH < 1) {
                        return;
                    }

                    const x = Math.max(0, Math.round((viewW - boxW) / 2));
                    const y = Math.max(0, Math.round((viewH - boxH) / 2));
                    shade.style.setProperty('border-top-width', `${y}px`, 'important');
                    shade.style.setProperty('border-bottom-width', `${Math.max(0, viewH - boxH - y)}px`, 'important');
                    shade.style.setProperty('border-left-width', `${x}px`, 'important');
                    shade.style.setProperty('border-right-width', `${Math.max(0, viewW - boxW - x)}px`, 'important');
                };

                const originalBounds = proto.getShadedRegionBounds;
                if (typeof originalBounds === 'function') {
                    proto.getShadedRegionBounds = function (viewW, viewH, qrbox) {
                        const bounds = originalBounds.call(this, viewW, viewH, qrbox);
                        bounds.x = Math.max(0, Math.round((viewW - bounds.width) / 2));
                        bounds.y = Math.max(0, Math.round((viewH - bounds.height) / 2));

                        return bounds;
                    };
                }

                proto.__tpAimPinned = true;
            },

            bindScanWindowResize() {
                if (this._onScanWindowResize) {
                    window.removeEventListener('resize', this._onScanWindowResize);
                }

                this._onScanWindowResize = () => this.placeScanWindowCentered();
                window.addEventListener('resize', this._onScanWindowResize);
            },

            placeScanWindowCentered() {
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

                const x = Math.max(0, Math.round((viewW - boxW) / 2));
                const y = Math.max(0, Math.round((viewH - boxH) / 2));
                region.x = x;
                region.y = y;
                shade.style.setProperty('border-top-width', `${y}px`, 'important');
                shade.style.setProperty('border-bottom-width', `${Math.max(0, viewH - boxH - y)}px`, 'important');
                shade.style.setProperty('border-left-width', `${x}px`, 'important');
                shade.style.setProperty('border-right-width', `${Math.max(0, viewW - boxW - x)}px`, 'important');
            },

            async startHtml5Fallback(host) {
                const Html5Qrcode = await this.ensureLibrary();
                this.pinAimWindowCentered(Html5Qrcode);
                host.innerHTML = '';

                const formats = window.Html5QrcodeSupportedFormats
                    || window.__Html5QrcodeLibrary__?.Html5QrcodeSupportedFormats
                    || null;
                const formatsToSupport = formats
                    ? [formats.DATA_MATRIX, formats.CODE_128, formats.QR_CODE, formats.AZTEC].filter(Boolean)
                    : null;

                const ctorConfig = {
                    verbose: false,
                    useBarCodeDetectorIfSupported: false,
                };
                if (formatsToSupport?.length) {
                    ctorConfig.formatsToSupport = formatsToSupport;
                }

                this.scanner = new Html5Qrcode(host.id, ctorConfig);
                this.lockOnEngine = 'html5';

                const scanConfig = {
                    fps: this.fps,
                    disableFlip: true,
                    qrbox: (viewfinderWidth, viewfinderHeight) => {
                        const hole = this.aimWindow(viewfinderWidth, viewfinderHeight);

                        return { width: hole.width, height: hole.height };
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
                    (decoded, decodedResult) => {
                        const format = this.normalizeBarcodeFormat(
                            decodedResult?.result?.format?.formatName
                            || decodedResult?.format?.formatName
                            || '',
                        );
                        if (format && format !== 'data_matrix' && format !== 'code_128') {
                            return;
                        }

                        this.noteRankedRaw(decoded);
                    },
                    () => {},
                );

                this.placeScanWindowCentered();
                this.bindScanWindowResize();
                this.detectHtml5TrackFeatures();
                try {
                    const vt = host.querySelector('video')?.srcObject?.getVideoTracks?.()?.[0];
                    if (vt) {
                        await this.requestContinuousAutofocus(vt);
                    }
                } catch (e) { /* ignore */ }
            },
            detectHtml5TrackFeatures() {
                this.torchSupported = false;
                this.zoomSupported = false;

                try {
                    const caps = this.scanner?.getRunningTrackCameraCapabilities?.();
                    const torch = caps?.torchFeature?.();
                    this.torchSupported = Boolean(torch?.isSupported?.());
                    const zoom = caps?.zoomFeature?.();
                    if (zoom?.isSupported?.()) {
                        this.zoomSupported = true;
                        this.zoomMin = Number(zoom.min?.() ?? 1) || 1;
                        this.zoomMax = Number(zoom.max?.() ?? this.zoomMin) || this.zoomMin;
                        this.zoomValue = Number(zoom.value?.() ?? this.zoomMin) || this.zoomMin;
                    }
                } catch (e) {
                    // fall through
                }

                try {
                    const track = this.scanner?.getRunningTrack?.()
                        || document.querySelector('#tp-floor-qr-reader video')?.srcObject?.getVideoTracks?.()?.[0];
                    if (track && typeof track.getCapabilities === 'function') {
                        const caps = track.getCapabilities() || {};
                        if (! this.torchSupported) {
                            this.torchSupported = Boolean(caps.torch);
                        }
                        if (! this.zoomSupported && caps.zoom) {
                            this.zoomSupported = true;
                            this.zoomMin = Number(caps.zoom.min) || 1;
                            this.zoomMax = Number(caps.zoom.max) || this.zoomMin;
                            this.zoomValue = Number(track.getSettings?.()?.zoom) || this.zoomMin;
                        }
                        this._track = track;
                    }
                } catch (e) {
                    // ignore
                }
            },

            async setZoom(value) {
                if (! this.zoomSupported) {
                    return;
                }

                const next = Math.min(this.zoomMax, Math.max(this.zoomMin, Number(value) || this.zoomMin));
                this.zoomValue = next;

                if (this.lockOnEngine === 'html5') {
                    try {
                        const caps = this.scanner?.getRunningTrackCameraCapabilities?.();
                        const zoom = caps?.zoomFeature?.();
                        if (zoom?.isSupported?.()) {
                            await zoom.apply(next);

                            return;
                        }
                    } catch (e) {
                        // fall through
                    }
                }

                const track = this._track
                    || this._mediaStream?.getVideoTracks?.()?.[0]
                    || null;
                if (! track) {
                    return;
                }

                try {
                    await track.applyConstraints({ advanced: [{ zoom: next }] });
                } catch (e) {
                    this.zoomSupported = false;
                }
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

                    const host = document.getElementById('tp-floor-qr-reader');
                    if (! host) {
                        throw new Error('Camera view missing from page');
                    }

                    if (this.supportsNativeBarcodeDetector()) {
                        try {
                            await this.startNativeLockOn(host);
                        } catch (e) {
                            this.resetLockOnState();
                            await this.startHtml5Fallback(host);
                        }
                    } else {
                        await this.startHtml5Fallback(host);
                    }
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
                this.resetLockOnState();
                this.cameraOn = false;
                this.starting = false;
                this._confirming = false;
                this._ignoreUntil = 0;
                this.torchSupported = false;
                this.torchOn = false;
                this.scanSettingsOpen = false;

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
                this.detectHtml5TrackFeatures();
            },

            async toggleTorch() {
                await this.setTorch(! this.torchOn);
            },

            async setTorch(on) {
                if (! this.torchSupported) {
                    return;
                }

                if (this.lockOnEngine === 'html5' && this.scanner) {
                    try {
                        const caps = this.scanner.getRunningTrackCameraCapabilities?.();
                        const torch = caps?.torchFeature?.();
                        if (torch?.isSupported?.()) {
                            await torch.apply(Boolean(on));
                            this.torchOn = Boolean(on);

                            return;
                        }
                    } catch (e) {
                        // fall through
                    }

                    try {
                        await this.scanner.applyVideoConstraints?.({
                            advanced: [{ torch: Boolean(on) }],
                        });
                        this.torchOn = Boolean(on);

                        return;
                    } catch (e) {
                        // fall through to track
                    }
                }

                const track = this._track
                    || this._mediaStream?.getVideoTracks?.()?.[0]
                    || null;
                if (! track) {
                    this.torchSupported = false;
                    this.torchOn = false;

                    return;
                }

                try {
                    await track.applyConstraints({ advanced: [{ torch: Boolean(on) }] });
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
