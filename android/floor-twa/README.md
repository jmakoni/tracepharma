# TracePharma Floor TWA

Thin Android wrapper (`com.tracepharma.floor`) around the Laravel floor PWA. Chrome renders receive / ship / transfer / pack / verify / find. This module is **not** copied by `scripts/deploy/sync-tracepharma-app.sh`.

## Flavors

| Flavor | `startUrl` | Use |
|---|---|---|
| **qa** (sideload) | `https://floor.internal.vatengi.com/handheld` | Internal QA. Additional trusted origin: `demo2.internal.vatengi.com`. Never point this APK at live `*.tracepharma.io` customers. |
| **production** | `https://floor.tracepharma.io/handheld` | Play Store listing. Add each customer origin to `app/src/production/res/values/twa.xml` before they must stay fullscreen. |
| **enterprise** | `https://{enterpriseHost}/floor` | MDM / StageNow kiosk. Skip the host picker. Build with `-PenterpriseHost=customer.tracepharma.io`. |

The launcher is an in-app WebView so the activity stays on screen. Chrome TWA handoff finished the host activity whenever Custom Tabs exited (missing Chrome, stale TLS, or quality-enforcement crash).

## Prerequisites

- JDK 17
- Android SDK (compile SDK 35) or Android Studio
- Chrome / Android System WebView on the device (TWA uses Chrome Custom Tabs)

Optional: [Bubblewrap](https://github.com/GoogleChromeLabs/bubblewrap) (`npm i -g @bubblewrap/cli`) if you prefer regenerating from `twa-manifest.json` instead of this checked-in Gradle project. After regenerate, keep the `CAMERA` permission and product flavors.

## Signing (Play + sideload)

Do **not** commit keystores. Upload key lives in the org vault.

```bash
keytool -genkeypair -keystore android.keystore -alias upload \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -dname "CN=TracePharma Floor, O=TracePharma"
```

Copy `signing.properties.example` → `signing.properties` and fill passwords.

Play App Signing will have a **different** SHA-256 than the upload key. Put **both** colon-hex fingerprints in Laravel `HANDHELD_ANDROID_SHA256` so `/.well-known/assetlinks.json` verifies sideload and Play:

```bash
keytool -list -v -keystore android.keystore -alias upload | grep SHA256
```

After the first Play upload, copy the **App signing key certificate** SHA-256 from Play Console → App integrity.

## Build and sideload

From Android Studio: open `android/floor-twa`, generate a Gradle wrapper if prompted, then Run **qaDebug**.

CLI (after `gradle wrapper` exists):

```bash
cd android/floor-twa
./gradlew assembleQaDebug
adb install -r app/build/outputs/apk/qa/debug/app-qa-debug.apk
```

Release QA / Play:

```bash
./gradlew assembleQaRelease
./gradlew bundleProductionRelease
```

Enterprise MDM:

```bash
./gradlew assembleEnterpriseRelease -PenterpriseHost=acme.tracepharma.io
```

## Digital Asset Links

Every origin that must stay inside the TWA (chooser + tenant) must serve the same `assetlinks.json` package + fingerprints. Laravel already does this via `HandheldPwa::assetLinks()`.

Confirm before device QA:

```bash
curl -sS https://floor.internal.vatengi.com/.well-known/assetlinks.json
curl -sS https://demo2.internal.vatengi.com/.well-known/assetlinks.json
```

## Camera

The APK declares `CAMERA`. The site already uses `getUserMedia` on HTTPS. Grant the Android prompt, then the Chrome camera prompt, on first receive session.

Hardware wedge (Zebra / Honeywell / Newland) still types into `.tp-floor-receive__scan-input`. No DataWedge intent in v1.
