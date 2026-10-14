<?php

/*
 * Backups of the database (every hour) and of uploaded files (every day) to external S3-compatible storage
 * (Backblaze B2, Wasabi, AWS S3, ...), encrypted on this server before upload. See docs/RESTORE.md.
 */
return [

    // Filesystem disk of the external storage (config/filesystems.php, "backups").
    'disk' => env('BACKUP_DISK', 'backups'),

    // Folder in the bucket, so several installations can share one bucket.
    'prefix' => trim((string) env('BACKUP_PREFIX', 'fieldservice'), '/'),

    // 32 random bytes, base64 ("base64:..."): php artisan backup:key. Without this key the backups cannot be read:
    // keep a copy outside the server (password manager).
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    'retention' => [
        'hourly_hours' => (int) env('BACKUP_KEEP_HOURLY_HOURS', 48),
        'daily_days' => (int) env('BACKUP_KEEP_DAILY_DAYS', 30),
        'monthly_months' => (int) env('BACKUP_KEEP_MONTHLY_MONTHS', 12),
        // Backups made before a deployment or a restore.
        'event_days' => (int) env('BACKUP_KEEP_EVENT_DAYS', 30),
        'files_days' => (int) env('BACKUP_KEEP_FILES_DAYS', 30),
    ],

    // Delete expired backups from the bucket. Turn off when the bucket's own lifecycle rules delete them and the
    // server's key has no delete permission (docs/RESTORE.md).
    'prune' => (bool) env('BACKUP_PRUNE', true),

    // backup:check alerts when the newest database backup is older than this (minutes) or files older than 26 hours.
    'max_age_minutes' => (int) env('BACKUP_MAX_AGE_MINUTES', 90),

    'binaries' => [
        'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('BACKUP_PG_RESTORE', 'pg_restore'),
        'psql' => env('BACKUP_PSQL', 'psql'),
        'tar' => env('BACKUP_TAR', 'tar'),
    ],

    // Scratch database for test restores and for restoring one company (never the live database).
    'restore_database' => env('BACKUP_RESTORE_DATABASE'),

    // Uploaded files: job photos, receipts, signatures, rating plates, logos, PDFs.
    'file_roots' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
    ],

    'alerts' => [
        // While email is not set up: a Telegram bot for server alerts (optional).
        'telegram_bot_token' => env('OPS_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('OPS_TELEGRAM_CHAT_ID'),
        'log_channel' => env('BACKUP_LOG_CHANNEL', 'stack'),
    ],

    // Daily watchdog (ops:watchdog): alert the Owner when records drop by more than this share or count in a day.
    'watchdog' => [
        'percent' => (float) env('WATCHDOG_DROP_PERCENT', 5),
        'count' => (int) env('WATCHDOG_DROP_COUNT', 10),
        'hour' => (int) env('WATCHDOG_HOUR', 7),
    ],
];
