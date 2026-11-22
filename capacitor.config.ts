import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The Android app (owner's task of November 21): a Capacitor shell that loads the live site, so updates come from the
 * server as in the browser, plus the Square card reader plugin (android/app/src/main/java/…/SquareReaderPlugin.kt).
 * The site address is set when the APK is built (CAP_SERVER_URL in GitHub Actions); nothing country-specific here.
 */
const serverUrl = process.env.CAP_SERVER_URL ?? 'https://app.doctor-appliance.ca';

const config: CapacitorConfig = {
    appId: process.env.CAP_APP_ID ?? 'ca.doctorappliance.app',
    appName: process.env.CAP_APP_NAME ?? 'Doctor Appliance',
    // Shown only when the site cannot be reached at all (no signal on first start).
    webDir: 'mobile/www',
    server: {
        url: serverUrl,
        cleartext: false,
        // Only the app's own site opens inside the app; other links open in the browser.
        allowNavigation: [new URL(serverUrl).host],
        errorPath: 'offline.html',
    },
    android: {
        allowMixedContent: false,
    },
};

export default config;
