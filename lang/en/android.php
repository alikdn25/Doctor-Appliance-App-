<?php

return [
    /*
    | The Android app: its download page (Owner only) and "Update available" inside the app (owner's task of
    | November 22).
    */
    'title' => 'Android app',
    'subtitle' => 'Doctor Appliance for Android phones: the same app, plus the Square card reader.',
    'download' => 'Download',
    'version' => 'Version :version',
    'built' => 'Built :date',
    'size' => ':size MB',
    'none' => 'No APK on the server yet. It appears after the next build of main (GitHub Actions → android).',
    'install_title' => 'Install on a phone',
    'install_steps' => [
        'step_1' => 'Open this page on the Android phone and press Download.',
        'step_2' => 'Open the downloaded file. Allow "Install unknown apps" for your browser if Android asks.',
        'step_3' => 'Open Doctor Appliance and sign in as usual.',
        'step_4' => 'Allow notifications, the microphone, the camera and location when the app asks.',
        'step_5' => 'Card payments: Settings → Payments → Card reader → Pair reader.',
    ],
    'update_available' => 'Update available',
    'later' => 'Later',
];
