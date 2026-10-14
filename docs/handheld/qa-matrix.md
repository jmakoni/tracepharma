# Floor TWA device QA matrix

Sign off on a **phone-width emulator** and one **physical Android handheld** (Zebra / Honeywell / Newland or a Pixel) before any Play track.

**QA APK flavor:** `qa` → `https://floor.internal.vatengi.com/handheld`  
**Tenant:** `demo2.internal.vatengi.com` only (never a live `*.tracepharma.io` customer).

## Pre-flight (server)

- [ ] Nginx `server_name` and the TLS certificate SAN include `floor.internal.vatengi.com` (same app root as other central hosts). DNS already can point at the platform; a missing SAN blocks the QA TWA start URL.
- [ ] `GET https://floor.internal.vatengi.com/manifest.webmanifest` → `start_url` ends with `/handheld`, `display: standalone`
- [ ] `GET https://demo2.internal.vatengi.com/manifest.webmanifest` → `start_url` ends with `/floor`
- [ ] Both hosts serve `/.well-known/assetlinks.json` with `com.tracepharma.floor` and the sideload **and** Play SHA-256 fingerprints (`HANDHELD_ANDROID_SHA256`)
- [ ] Chooser rejects `evil.com` and accepts `demo2.internal.vatengi.com`

## Install and session

1. Install the QA APK → chooser → enter `demo2.internal.vatengi.com` → **Open floor**.
2. Login (password or TOTP). First handheld rollout: prefer password/TOTP if the tenant IdP redirects off listed origins.
3. Land on `/floor` **fullscreen** (no Chrome URL bar). If a URL bar appears, Digital Asset Links or `additional_trusted_origins` is wrong.
4. Kill the TWA process from Recents, relaunch: session cookie still authenticates (or login is shown, not a white screen).
5. Logout → login again.

## Floor tiles (tenant profile must show the tile)

For each launcher tile, open the list and one session (or the empty state). Stay in the floor shell — no desktop chrome, no unstyled white screen.

| Tile | Route family | Pass |
|---|---|---|
| EPCIS Receive | `/receiving-sessions/.../floor` | |
| Scan first | scan-first floor | |
| Transfer ship | transfer ship floor | |
| Transfer receive | transfer receive floor | |
| Ship | shipping floor | |
| Pack | pack floor | |
| Unpack | unpack floor | |
| Break and pack | break-pack floor | |
| Verify (VRS) | verify floor | |
| Find | `/find` | |

Open-work resume: start a receive, copy `?session=`, relaunch that URL inside the TWA.

## Scanning

4. **Wedge:** hardware/HID scan into the receive scan field + Enter confirms a line.
5. **Camera:** grant Android + Chrome camera permission; decode a GS1 DataMatrix; torch and pace control still work (`tp-floor-receive.js`).

## Navigation and safety

6. Livewire `wire:navigate` back from a session to `/floor`.
7. Open a desktop-only URL (for example a resource index the floor map does not twin). [`RedirectUnmappedFloorShell`](../../app/Http/Middleware/RedirectUnmappedFloorShell.php) must send the operator to a mapped floor page — not a white screen.
8. OIDC tenant on `*.internal.vatengi.com` (if one exists): complete the redirect without a second browser task. If the IdP host is not in Digital Asset Links, document password/TOTP for that tenant.

## Out of scope this pass

- Returns, Destroy, Commission / disposition (no floor twins)
- BrowserPrint / Zebra agent / SSCC PDF
- Offline scan queue
- iOS Add to Home Screen
- DataWedge intent plugin

## Sign-off

| Device | Build (`versionName` / `versionCode`) | Date | Operator | Result |
|---|---|---|---|---|
| Emulator (phone width) | | | | |
| Physical handheld | | | | |
