# Android app (Capacitor + Square card reader)

Owner's task of November 21 (SPEC §7.18). The app is a Capacitor shell that loads the live site, so every update
of the site reaches the app at once. Only the card reader needs native code.

## What is where

| Part                          | Path                                                                                       |
| ----------------------------- | ------------------------------------------------------------------------------------------ |
| Capacitor config (server.url) | `capacitor.config.ts` (`CAP_SERVER_URL`, default `https://app.doctor-appliance.ca`)        |
| Android project               | `android/` (package `ca.doctorappliance.app`, name "Doctor Appliance", same icon)          |
| Card reader plugin (Kotlin)   | `android/app/src/main/java/ca/doctorappliance/app/SquareReaderPlugin.kt`                   |
| SDK start                     | `android/app/src/main/java/ca/doctorappliance/app/MainApplication.kt`                      |
| Web side of the plugin        | `resources/js/lib/card-reader.ts`                                                          |
| Offline fallback page         | `mobile/www/`                                                                              |
| Build workflow                | `.github/workflows/android.yml`                                                            |
| Download on the server        | `storage/app/android/android.apk` → `https://app.doctor-appliance.ca/download/android.apk` |

Square Mobile Payments SDK 2.7.0 (`com.squareup.sdk:mobile-payments-sdk`, repository
`https://sdk.squareup.com/public/android/`), minSdk 28, compileSdk 37, Kotlin 2.2.21, AGP 8.13, Java 17+.

## How a card payment works

1. Take payment → Charge reader: the server makes a pending charge (`POST /charges`) with a reference (UUID).
2. The page asks for Bluetooth and location (first time only), authorizes the SDK with the company's Square access
   token and location (`GET /payments/reader/session`), and starts the SDK payment screen with the amount, the
   reference as `referenceId`, a note, online only. No buyer email or phone is passed.
3. The customer taps, inserts (chip + PIN) or uses Interac. The phone sends the Square payment ID to the server
   (`POST /charges/{reference}/complete`); the server reads the payment from Square and records it only when it is
   COMPLETED, has our reference and the same amount and currency. The `payment.created/updated` webhook does the same
   (whichever comes first; never twice).
4. The Paid screen opens.

In the Square sandbox the SDK shows the mock reader (`MockReaderUI`) after authorization: tap the floating button →
"Add Contactless & Chip Reader" → pay.

## Building

GitHub Actions → **android** (runs on pushes to `main` that touch the Android files, or by hand). Repository settings:

- Secrets: `ANDROID_KEYSTORE_BASE64` (base64 of the release keystore), `ANDROID_KEYSTORE_PASSWORD`,
  `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`.
- Variables: `SQUARE_APPLICATION_ID` (the same Square application ID as the server, sandbox or production),
  `CAP_SERVER_URL` (optional), `ANDROID_PUBLISH_TO_SERVER` = `true` to copy the APK to the server with the deploy
  SSH secrets (environment `production`).

Make the keystore once and keep a copy offline (losing it means a new app install for everyone):

```bash
keytool -genkeypair -v -keystore release.jks -alias doctor-appliance -keyalg RSA -keysize 4096 -validity 10000
base64 -w0 release.jks   # → ANDROID_KEYSTORE_BASE64
```

The APK is the workflow artifact `android-apk`, the asset of the release `android-latest`, and (with
`ANDROID_PUBLISH_TO_SERVER`) `storage/app/android/android.apk` on the server.

Local build (Android Studio or the SDK installed): `npm ci && npx cap sync android && cd android && ./gradlew assembleDebug`.

## Limits

- The app shows the live site: without signal the offline page shows until the site can be reached.
- Web push notifications do not arrive inside the Android WebView; keep notifications on in the browser / PWA on
  the phone for "Invoice paid" and the other pushes.
- Payments are online only (no offline card payments): the server must confirm every payment.
