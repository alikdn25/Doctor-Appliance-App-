# Android app (Capacitor + Square card reader)

Owner's tasks of November 21–22 (SPEC §7.18, §7.19, §7.21). The app is a Capacitor shell that loads the live site
(https://app.doctor-appliance.ca): the same sign-in, screens and design, and every update of the site reaches the app
at once. Native code is only where the web view cannot do it: the Square card reader, push, voice input, the back
button and the app version.

## What is where

| Part                          | Path                                                                                                                                         |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Capacitor config (server.url) | `capacitor.config.ts` (`CAP_SERVER_URL`, default `https://app.doctor-appliance.ca`)                                                          |
| Android project               | `android/` (Capacitor's standard folder; package `ca.doctorappliance.app`, "Doctor Appliance", the PWA's icon and splash colour)             |
| Card reader plugin (Kotlin)   | `android/app/src/main/java/ca/doctorappliance/app/SquareReaderPlugin.kt`                                                                     |
| Voice input plugin            | `…/SpeechInputPlugin.kt` (Android speech recognition; web side `resources/js/lib/native-speech.ts`)                                          |
| Version / open outside        | `…/AppShellPlugin.kt` (web side `resources/js/lib/native-shell.ts`)                                                                          |
| Back button                   | `…/MainActivity.kt` → `window.__daBack` (`native-shell.ts`): closes a sheet, else the ← arrow                                                |
| Push                          | `…/AppMessagingService.kt`, `PushActionReceiver.kt`, `resources/js/lib/native-push.ts`                                                       |
| Web side of the card reader   | `resources/js/lib/card-reader.ts`, `resources/js/components/payments/take-payment.tsx`                                                       |
| Offline fallback page         | `mobile/www/`                                                                                                                                |
| Build workflow                | `.github/workflows/android.yml`                                                                                                              |
| On the server                 | `storage/app/android/android.apk` + `android.json` → page `/android` (Owner), file `/download/android.apk`, version `/download/android.json` |

Square Mobile Payments SDK 2.7.0 (`com.squareup.sdk:mobile-payments-sdk`, repository
`https://sdk.squareup.com/public/android/`). **minSdk 28 (Android 9) — the SDK's minimum**, compileSdk 36 (the SDK's AAR metadata asks for 37 / AGP 9.1; that check is skipped in `android/app/build.gradle` until the runner has android-37),
targetSdk 36, Kotlin 2.4.0, AGP 8.13, Java 21.

## What works in the app

- Everything the site does (same pages). Camera and photo picking (rating plate, receipts, screenshots) use the
  web page's file picker with the camera; Call (`tel:`) opens the dialer; Navigate opens Google Maps; On my way asks
  for location — all with Android's own permission prompts.
- Push (Firebase Cloud Messaging): the server sends each alert to the browser / PWA and to the app; a tap opens the
  right screen.
- Voice input (quick booking, assistant, close by voice, booking edit): Android's speech recognition, English or
  Russian as chosen on the button, going on through pauses until the second tap.
- Back button = the ← arrow on the screen (an open sheet closes first); on a main screen the app goes to the
  background.
- "Update available · Download" at the start when the server has a newer APK (the download opens in the phone's
  browser, which installs it).

## How a card payment works

1. Take payment → **Card** (first and main button; only in the app — a browser shows "Card reader works in the
   Android app" linking to `/android`). The same screen opens from an invoice, a job and New charge → Charge.
2. The server makes a pending charge (`POST /charges`). Square gets `reference_id` = the invoice number (a charge
   without a booking takes the next invoice number at Card; Try again keeps it) and `note` = the job description.
   No customer email or phone is passed, so Square sends no receipt.
3. The page asks for Bluetooth and location (first time only), authorizes the SDK with the company's Square token
   and location (`GET /payments/reader/session`, signed-in people of the company who take payments only), and
   starts the payment: "Tap, insert or swipe card", Cancel. Online only — without internet Card shows "No internet —
   card payment needs connection".
4. The phone sends the Square payment ID (`POST /charges/{reference}/complete`). The server reads the payment from
   Square (Payments API) and records it only when it is COMPLETED, ours, and for exactly the Total; one payment ID
   pays once. It stores the payment ID, card brand and last 4 digits; the invoice / job become paid (method Card).
   The `payment.created/updated` webhook does the same if the phone loses signal (whichever comes first).
5. The Paid screen: receipt by email from the app and the optional review request with a location.
6. Declined card or reader error: a plain message and **Try again**; the invoice stays unpaid.

In the Square sandbox the SDK shows the mock reader after authorization: tap the floating button →
"Add Contactless & Chip Reader" → pay.

## Owner: what to do, step by step

### 1. Square Developer Dashboard (https://developer.squareup.com/apps)

1. Open the application already used for "Connect Square" (or create one).
2. **Credentials**: copy the **Application ID** and **Application secret** (Sandbox for testing, Production for real
   money — use one environment everywhere).
3. **OAuth**: the Redirect URL must be `https://app.doctor-appliance.ca/payment-providers/square/callback`.
4. **Mobile Payments SDK**: on the application's page open "Mobile Payments SDK" and accept its terms if asked
   (production: Square may ask to review the app).
5. Permissions the app asks for when connecting (nothing to tick in the dashboard; they are requested by the app):
   `MERCHANT_PROFILE_READ`, `PAYMENTS_READ`, `PAYMENTS_WRITE`, `PAYMENTS_WRITE_IN_PERSON`, `ORDERS_READ`,
   `ORDERS_WRITE`.
6. **Webhooks** → subscription to `https://app.doctor-appliance.ca/webhooks/payments/square` with the events
   `payment.created`, `payment.updated`, `refund.created`, `refund.updated`; copy its **Signature key**.

### 2. Server `.env` (Grok Bot)

```
SQUARE_ENVIRONMENT=production            # or sandbox
SQUARE_APPLICATION_ID=sq0idp-…           # Application ID
SQUARE_APPLICATION_SECRET=sq0csp-…       # Application secret
SQUARE_WEBHOOK_SIGNATURE_KEY=…           # webhook signature key
SQUARE_WEBHOOK_URL=https://app.doctor-appliance.ca/webhooks/payments/square
FCM_SERVICE_ACCOUNT_BASE64=…             # push, see below
```

then `php artisan config:cache`.

### 3. In the app: Settings → Payments

- If you see **Reconnect Square**: press it and allow (an older connection lacks the in-person permission).
- Choose the **Square location** for card payments.
- **Card reader** (in the Android app): Pair reader → press the button on the reader → it shows Connected and its
  battery; **Forget reader** removes it.

### 4. GitHub (repository → Settings → Secrets and variables → Actions)

**Secrets**

| Name                                                                                  | Value                                             |
| ------------------------------------------------------------------------------------- | ------------------------------------------------- |
| `ANDROID_KEYSTORE_BASE64`                                                             | the signing key, base64 (one line)                |
| `ANDROID_KEYSTORE_PASSWORD`                                                           | the key store password                            |
| `ANDROID_KEY_ALIAS`                                                                   | `doctor-appliance`                                |
| `ANDROID_KEY_PASSWORD`                                                                | the key password (the same as the store password) |
| `GOOGLE_SERVICES_JSON_BASE64`                                                         | Firebase `google-services.json`, base64 (push)    |
| `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `SSH_HOST`, `SSH_USER`, `APP_PATH` (`SSH_PORT`) | already there for the deploy                      |

**Variables**

| Name                        | Value                                                 |
| --------------------------- | ----------------------------------------------------- |
| `SQUARE_APPLICATION_ID`     | the same Application ID as on the server              |
| `ANDROID_PUBLISH_TO_SERVER` | `true` (copies each APK to the server for `/android`) |
| `CAP_SERVER_URL`            | optional, default `https://app.doctor-appliance.ca`   |

### 5. The signing key — keep it safe

The key signs every APK. Android installs an update only when it is signed with the **same** key: losing it means
everyone uninstalls and installs the app again. The key for this app was generated once (file `release.jks` and the
text file with the four secrets, sent with the task). Do this once:

1. Put the four `ANDROID_*` secrets from the text file into GitHub (above).
2. Save `release.jks` and the text file in a password manager (e.g. as attachments) **and** on a USB stick kept at
   home. Never commit them, never email them.
3. Delete the copies from the computer's Downloads folder.

To make a new key (only if there was none or it was never used for an installed app):

```bash
keytool -genkeypair -v -keystore release.jks -storetype PKCS12 -alias doctor-appliance -keyalg RSA -keysize 4096 -validity 10000
base64 -w0 release.jks   # → ANDROID_KEYSTORE_BASE64
```

### 6. Install the APK on a phone

1. On the Android phone, sign in at https://app.doctor-appliance.ca/android (Owner) → **Download**.
2. Open the downloaded file; allow "Install unknown apps" for the browser when Android asks.
3. Open **Doctor Appliance**, sign in, allow notifications, microphone, camera and location when asked.
4. Later versions: the app shows "Update available" → Download → install over the old one (sign-in stays).

Technicians install the same APK (the Owner sends them the `/download/android.apk` link; the page itself is Owner
only).

## Building

GitHub Actions → **android**: every push to `main` builds the signed APK (version `1.0.<run number>`, version code =
run number) and publishes it as the workflow artifact `android-apk`, the release `android-latest`, and (with
`ANDROID_PUBLISH_TO_SERVER`) `storage/app/android/android.apk` + `android.json` on the server. Pull requests that
touch the app build an unsigned debug APK only (compile check).

Local build (Android Studio or the SDK installed): `npm ci && npx cap sync android && cd android && ./gradlew assembleDebug`.

## Push notifications (Firebase Cloud Messaging)

Setup, once:

1. https://console.firebase.google.com → Add project (e.g. "Doctor Appliance"), Google Analytics off.
2. Project overview → Add app → Android. Package name `ca.doctorappliance.app`, nickname "Doctor Appliance" →
   Register app → Download `google-services.json` (skip the remaining SDK steps: the project already has them).
3. `base64 -w0 google-services.json` → GitHub secret `GOOGLE_SERVICES_JSON_BASE64`.
4. Project settings (gear) → Cloud Messaging: "Firebase Cloud Messaging API (V1)" must be Enabled.
5. Project settings → Service accounts → Generate new private key → a JSON file. On the server:
   `base64 -w0 key.json` → `.env` `FCM_SERVICE_ACCOUNT_BASE64=…`, then `php artisan config:cache`. Keep the file
   private (it can send pushes); delete the local copy after.
6. Run the **android** workflow; install the new APK. On first start Android asks to allow notifications.

An APK built without `GOOGLE_SERVICES_JSON_BASE64` has no push: the app reports `push: false` (AppShell.info) and
the web app never calls `PushNotifications.register()`, which would otherwise crash it on start.

Buttons on a notification run with the app's session (the same request as the browser's service worker).

## Limits

- The app shows the live site: without signal the offline page shows until the site can be reached.
- Card payments are online only (no offline card payments): the server confirms every payment with Square.
- A charge without a booking that is never finished by card leaves its reserved invoice number unused (a gap).
