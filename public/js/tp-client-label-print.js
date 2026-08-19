/**
 * TracePharma client-side SSCC label printing (QZ Tray + Zebra Browser Print).
 * Network TCP printing stays on the Laravel queue worker.
 *
 * Bump SCRIPT_VERSION whenever print logic changes so Livewire navigations
 * pick up the new handlers (old scripts set a one-shot register flag).
 *
 * Entry points:
 * - Livewire: $this->dispatch('tp-client-print', ...) — handled via Livewire.on only.
 * - Manual JS: window.tpClientLabelPrint.run({ bridge, jobs }) — do NOT dispatch a native
 *   CustomEvent('tp-client-print'); that path was removed to avoid double-firing with Livewire.
 */
(function () {
  const SCRIPT_VERSION = 8;
  const PENDING_QUEUE_MAX = 8;

  const csrf = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  async function startJob(jobId, token) {
    const url = `/label-print/jobs/${jobId}/start`;
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf(),
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ token: token || null }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(body.message || `Failed to start print job (${res.status})`);
    }
    if (!body.token) {
      throw new Error('Print start did not return an ownership token.');
    }
    return body.token;
  }

  async function assertJob(jobId, token) {
    const url = `/label-print/jobs/${jobId}/assert`;
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf(),
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ token }),
    });
    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      throw new Error(body.message || `Print job is no longer printable (${res.status})`);
    }
  }

  async function completeJob(jobId, status, error, token) {
    const url = `/label-print/jobs/${jobId}/complete`;
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf(),
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ status, error: error || null, token: token || null }),
    });
    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      throw new Error(body.message || `Failed to record print result (${res.status})`);
    }
  }

  async function completeJobWithRetries(jobId, status, error, token, maxAttempts = 3) {
    let lastErr;
    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
      try {
        await completeJob(jobId, status, error, token);
        return;
      } catch (e) {
        lastErr = e;
        if (attempt < maxAttempts - 1) {
          await new Promise((r) => setTimeout(r, 300 * (attempt + 1)));
        }
      }
    }
    throw lastErr;
  }

  async function pollBrowserPrintGlobal(maxMs = 2000) {
    const deadline = Date.now() + maxMs;
    while (Date.now() < deadline) {
      const api = resolveBrowserPrintGlobal();
      if (api) {
        return api;
      }
      await new Promise((r) => setTimeout(r, 50));
    }
    return resolveBrowserPrintGlobal();
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const absolute = new URL(src, window.location.origin).href;
      const existing = Array.from(document.scripts).find((el) => {
        try {
          return el.src && new URL(el.src).pathname === new URL(absolute).pathname;
        } catch (_e) {
          return el.getAttribute('src') === src;
        }
      });
      if (existing) {
        if (existing.getAttribute('data-tp-loaded') === '1') {
          resolve();
          return;
        }
        const markLoaded = () => {
          existing.setAttribute('data-tp-loaded', '1');
          resolve();
        };
        if (existing.readyState === 'complete' || existing.readyState === 'loaded') {
          markLoaded();
          return;
        }
        existing.addEventListener('load', markLoaded, { once: true });
        existing.addEventListener('error', () => reject(new Error(`Failed to load ${src}`)), {
          once: true,
        });
        return;
      }
      const s = document.createElement('script');
      s.src = absolute;
      s.async = true;
      s.onload = () => {
        s.setAttribute('data-tp-loaded', '1');
        resolve();
      };
      s.onerror = () => reject(new Error(`Failed to load ${src}`));
      document.head.appendChild(s);
    });
  }

  function resolveBrowserPrintGlobal() {
    if (typeof window.BrowserPrint !== 'undefined' && window.BrowserPrint) {
      return window.BrowserPrint;
    }
    // Some builds attach as a bare global via `var BrowserPrint = ...`
    if (typeof BrowserPrint !== 'undefined' && BrowserPrint) {
      window.BrowserPrint = BrowserPrint;
      return BrowserPrint;
    }
    return null;
  }

  let qzSigningWarned = false;

  async function ensureQz() {
    if (window.qz?.websocket) {
      return window.qz;
    }
    await loadScript('https://cdn.jsdelivr.net/npm/qz-tray@2.2.3/qz-tray.js');
    if (!window.qz) {
      throw new Error('QZ Tray JS failed to load. Check network access to the CDN.');
    }
    return window.qz;
  }

  async function printWithQz(job) {
    const qz = await ensureQz();
    if (typeof qz.security?.setCertificatePromise !== 'function' && !qzSigningWarned) {
      qzSigningWarned = true;
      console.warn(
        'QZ Tray is running without application signing — expect permission prompts. Configure QZ certificates for production.',
      );
    }
    if (!qz.websocket.isActive()) {
      await qz.websocket.connect();
    }
    const config = qz.configs.create(job.printer_name);
    await qz.print(config, [
      {
        type: 'raw',
        format: 'command',
        flavor: 'plain',
        data: job.zpl,
      },
    ]);
  }

  async function ensureBrowserPrint() {
    const existing = resolveBrowserPrintGlobal();
    if (existing) {
      return existing;
    }

    const candidates = [
      '/js/vendor/BrowserPrint.min.js',
      '/js/vendor/BrowserPrint-3.1.250.min.js',
      '/js/vendor/BrowserPrint-3.0.216.min.js',
      '/js/vendor/BrowserPrint-Zebra-1.0.216.min.js',
    ];

    const failures = [];
    for (const src of candidates) {
      try {
        await loadScript(src);
        let api = resolveBrowserPrintGlobal();
        if (!api) {
          api = await pollBrowserPrintGlobal();
        }
        if (api) {
          return api;
        }
        failures.push(`${src} loaded but BrowserPrint global missing`);
      } catch (e) {
        failures.push(e?.message || String(e));
      }
    }

    throw new Error(
      'Zebra Browser Print JS SDK not loaded. Confirm /js/vendor/BrowserPrint.min.js is reachable, then hard-refresh this page. Details: ' +
        failures.join('; '),
    );
  }

  function browserPrintSend(device, zpl) {
    return new Promise((resolve, reject) => {
      device.send(
        zpl,
        () => resolve(),
        (err) => reject(new Error(err || 'Zebra Browser Print send failed')),
      );
    });
  }

  function getLocalDevices(BrowserPrintApi) {
    return new Promise((resolve, reject) => {
      BrowserPrintApi.getLocalDevices(
        (devices) => resolve(devices || []),
        (err) =>
          reject(
            new Error(
              err ||
                'Unable to list Zebra Browser Print devices. Is the Browser Print app running on this PC? (HTTPS sites need the agent’s SSL port.)',
            ),
          ),
        'printer',
      );
    });
  }

  async function printWithZebra(job) {
    const BrowserPrintApi = await ensureBrowserPrint();
    const devices = await getLocalDevices(BrowserPrintApi);
    if (!devices.length) {
      throw new Error(
        'No Zebra Browser Print printers found. Open the Browser Print app on this PC and confirm it lists the printer (e.g. 10.1.30.72).',
      );
    }
    const wanted = (job.printer_name || '').toLowerCase();
    const device =
      devices.find((d) => (d.name || '').toLowerCase() === wanted) ||
      devices.find((d) => (d.name || '').toLowerCase().includes(wanted));
    if (!device) {
      const available = devices.map((d) => d.name || '(unnamed)').join(', ');
      throw new Error(
        `Zebra Browser Print printer "${job.printer_name || ''}" not found. Available: ${available}`,
      );
    }
    await browserPrintSend(device, job.zpl);
  }

  async function printJob(bridge, job) {
    if (bridge === 'qz_tray') {
      await printWithQz(job);
      return;
    }
    if (bridge === 'zebra_browser_print') {
      await printWithZebra(job);
      return;
    }
    throw new Error(`Unsupported client print bridge: ${bridge}`);
  }

  function normalizeLivewirePayload(payload) {
    let detail = payload;
    if (Array.isArray(payload)) {
      detail = payload[0];
    }
    if (detail && typeof detail === 'object' && !detail.bridge && detail[0]) {
      detail = detail[0];
    }
    return detail || {};
  }

  function dispatchLivewireDone(detail) {
    if (typeof Livewire !== 'undefined') {
      Livewire.dispatch('client-print-done', detail);
    }
  }

  function dispatchLivewireError(message) {
    if (typeof Livewire !== 'undefined') {
      Livewire.dispatch('client-print-error', { message });
    }
  }

  /** @type {Promise<void> | null} */
  let activeRun = null;
  /** @type {object[]} FIFO of pending print payloads (never drop intermediate dispatches). */
  let pendingQueue = [];

  async function executeClientPrint(detail) {
    const bridge = detail.bridge;
    const jobs = detail.jobs || [];
    if (!jobs.length) {
      return;
    }
    let printed = 0;
    let failed = 0;
    for (const job of jobs) {
      let token = null;
      try {
        token = await startJob(job.print_job_id, job.client_print_token || null);
        job.client_print_token = token;
        // Assert immediately before send to shrink supersede TOCTOU window.
        await assertJob(job.print_job_id, token);
        await printJob(bridge, job);
        try {
          await completeJobWithRetries(job.print_job_id, 'printed', null, token);
          printed += 1;
        } catch (ackErr) {
          failed += 1;
          const message = `Label ${job.sscc_18} printed on the workstation but TracePharma could not record it (job #${job.print_job_id}). Refresh and check status before reprinting.`;
          console.error('Client label print ack failed after successful print', ackErr);
          window.dispatchEvent(
            new CustomEvent('tp-client-print-error', { detail: { message, job } }),
          );
          dispatchLivewireError(message);
        }
      } catch (e) {
        failed += 1;
        const message = e?.message || String(e);
        // Never call complete(failed) without a token — that would allow a 409
        // start (other session owns Printing) to kill the owner's in-flight job.
        if (token) {
          try {
            await completeJobWithRetries(job.print_job_id, 'failed', message, token);
          } catch (_ackErr) {
            /* ignore ack failure for print errors */
          }
        }
        console.error('Client label print failed', e);
      }
    }
    const doneDetail = { printed, failed, bridge };
    window.dispatchEvent(new CustomEvent('tp-client-print-done', { detail: doneDetail }));
    dispatchLivewireDone(doneDetail);
  }

  async function runClientPrint(detail) {
    if (activeRun) {
      if (pendingQueue.length >= PENDING_QUEUE_MAX) {
        pendingQueue.shift();
        console.warn('Client print pending queue full; dropped oldest queued reprint.');
      }
      pendingQueue.push(detail);
      return activeRun;
    }
    activeRun = (async () => {
      try {
        let current = detail;
        while (current) {
          await executeClientPrint(current);
          current = pendingQueue.shift() || null;
        }
      } finally {
        activeRun = null;
      }
    })();
    return activeRun;
  }

  // Always refresh the callable entrypoints (even if an older script already registered listeners).
  window.__tpRunClientPrint = runClientPrint;
  window.tpClientLabelPrint = {
    version: SCRIPT_VERSION,
    run: runClientPrint,
    setBridge: async (bridge) => {
      const res = await fetch('/label-print/bridge', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ bridge }),
      });
      if (!res.ok) {
        throw new Error('Unable to save print bridge preference');
      }
      return res.json();
    },
  };

  if (window.__tpClientLabelPrintVersion === SCRIPT_VERSION) {
    return;
  }
  window.__tpClientLabelPrintVersion = SCRIPT_VERSION;

  const bindLivewire = () => {
    if (window.__tpClientLabelPrintLwBound || typeof Livewire === 'undefined') {
      return;
    }
    window.__tpClientLabelPrintLwBound = true;
    Livewire.on('tp-client-print', (payload) => {
      window.__tpRunClientPrint?.(normalizeLivewirePayload(payload));
    });
  };

  document.addEventListener('livewire:init', bindLivewire);
  bindLivewire();
})();
