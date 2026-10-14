# Play listing and MDM

The Play listing is the **client**. Floor behavior ships with Laravel; bump `versionCode` / `versionName` in `android/floor-twa/app/build.gradle` independently of `APP_VERSION`.

Do not publish a QA APK that opens live `*.tracepharma.io` customer tenants. Production listing uses the **production** flavor (`https://floor.tracepharma.io/handheld`).

## Store listing

| Field | Copy |
|---|---|
| Name | TracePharma Floor |
| Short description | Warehouse receive, ship, transfer, pack, and verify for DSCSA operations. |
| Full description | TracePharma Floor is the handheld workspace for TracePharma. Operators install once, pick their organization host, and work the same receive, scan-first, transfer, ship, pack, unpack, break-and-pack, VRS verify, and find screens they already use in Chrome. The app stays in fullscreen and uses the device camera or a hardware scanner wedge. You need a TracePharma account. The server is the product — this listing updates the wrapper only. |
| Category | Business |
| Privacy policy | Existing public Privacy Policy URL (same document linked from tenant login). |
| Screenshots | `/floor` tile grid; receive HUD with scan field; one pack or verify HUD. Capture on a phone-width device. |

## Play Console rollout

1. Create application **TracePharma Floor**, package `com.tracepharma.floor`.
2. Enable **Play App Signing**. Store the upload keystore in the org vault (never the Laravel repo).
3. Copy **both** SHA-256 fingerprints (upload key + Play signing cert) into every environment’s `HANDHELD_ANDROID_SHA256`. Confirm `/.well-known/assetlinks.json` on `floor.tracepharma.io` and each customer host.
4. Upload `bundleProductionRelease` (AAB) to **Internal testing**.
5. Add internal testers; install from the Play internal track; run [qa-matrix.md](qa-matrix.md) against an `*.internal.vatengi.com` tenant **or** a named pilot customer you are explicitly authorized to use.
6. Promote to **Closed testing**, then **Production** after device QA sign-off.

Sideload (`assembleQaRelease`) is for `*.internal.vatengi.com` only.

## Customer hosts inside the TWA

Fullscreen TWA requires Digital Asset Links **and** the origin in the APK `additional_trusted_origins` array (`app/src/production/res/values/twa.xml`). Hosts not listed still open in Custom Tabs (operator can work; Chrome chrome is visible). Bump `versionCode` when adding a host.

## MDM (SOTI, Zebra StageNow)

- Install the Play package or a signed enterprise APK.
- Lock kiosk / assigned-access to `com.tracepharma.floor`.
- Optional **enterprise** flavor skips the picker and opens `https://{host}/floor` directly:

  `./gradlew assembleEnterpriseRelease -PenterpriseHost=acme.tracepharma.io`

- Keep the same package name so `assetlinks.json` still matches.
- Hardware scanners: configure the device as a keyboard wedge that sends Enter. DataWedge intent is not in v1.
- Do not enable BrowserPrint on the device; floor already works without it.

## OIDC / passkeys

SSO redirects must stay on origins listed in Digital Asset Links (or they open Custom Tabs). WebAuthn in a TWA needs those same asset links. If a tenant IdP breaks out of the TWA, use password or TOTP on first handheld rollout.
