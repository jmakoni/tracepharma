# Zebra Browser Print SDK (not bundled)

TracePharma loads Zebra Browser Print from `public/js/vendor/` at runtime. Zebra’s JavaScript SDK is **proprietary** and is **not** redistributed in this repository.

## Setup

1. Install [Zebra Browser Print](https://www.zebra.com/us/en/support-downloads/printer-software/by-product/browser-print.html) on each workstation that prints SSCC labels via the browser.
2. Download the Browser Print JavaScript files from Zebra (typically `BrowserPrint-*.min.js` and optionally `BrowserPrint-Zebra-*.min.js`).
3. Copy them into this directory, for example:
   - `public/js/vendor/BrowserPrint-3.1.250.min.js` (or whatever version Zebra ships)
   - Optionally symlink a stable name: `ln -s BrowserPrint-3.1.250.min.js BrowserPrint.min.js`

`public/js/tp-client-label-print.js` tries `BrowserPrint.min.js`, then known versioned builds, when a label printer uses the **Zebra Browser Print** protocol.

## QZ Tray

QZ Tray is loaded from the public jsDelivr CDN (`qz-tray@2.2.3`). Workstations must have the [QZ Tray](https://qz.io/download/) desktop agent installed when using the **QZ Tray** protocol.
