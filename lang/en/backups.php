<?php

return [
    'never' => 'never',
    'kinds' => [
        'database' => 'database',
        'files' => 'files',
    ],
    'problems' => [
        'storage' => 'The backup storage cannot be read: :error',
        'database_old' => 'The newest database backup is from :time.',
        'files_old' => 'The newest files backup is from :time.',
    ],
    'alerts' => [
        'failed_title' => 'Backup failed (:kind)',
        'failed_body' => ':app: :error',
        'check_title' => 'Backups need attention',
    ],
];
