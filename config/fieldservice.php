<?php

return [

    /*
    | Disk for media that may be public (brand logos shown on customer documents).
    | "public" (local) for now; switch to "s3" via .env once object storage is chosen.
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    | The one Google review reminder goes this many minutes after the job was paid in full, when the customer has
    | not clicked a review link (owner's task of November 25). 24 hours; shorter for a test.
    */
    'review_reminder_minutes' => (int) env('REVIEW_REMINDER_DELAY_MINUTES', 1440),

    /*
    | Disk for private media: job photos, signatures, rating plate photos.
    | Never served by URL; files go out only through routes that check access.
    | "local" (storage/app/private) for now; a private S3 bucket later.
    */
    'private_media_disk' => env('PRIVATE_MEDIA_DISK', 'local'),

    /*
    | Country preselected when the super-admin creates a company (SPEC §1.1). Currency, regional format,
    | time zone and address format follow the company's country (config/countries.php).
    */
    'default_country' => env('DEFAULT_COMPANY_COUNTRY', 'US'),

    /*
    | Time zone database check in the super-admin panel. PHP built with the
    | system tzdata reports version "0.system"; the version is then read from
    | the zoneinfo file below.
    */
    'tzdata' => [
        'max_age_months' => 6,
        'zoneinfo_file' => env('TZDATA_ZONEINFO_FILE', '/usr/share/zoneinfo/tzdata.zi'),
    ],

    /*
    | Common appliance manufacturers suggested when entering an appliance
    | (merged with the ones a company has already used).
    */
    'appliance_manufacturers' => [
        'Amana', 'Asko', 'Bertazzoni', 'Blomberg', 'Bosch', 'Café', 'Dacor', 'Electrolux', 'Equator',
        'Fisher & Paykel', 'Frigidaire', 'Fulgor Milano', 'GE', 'GE Profile', 'Haier', 'Hisense',
        'Jenn-Air', 'Kenmore', 'KitchenAid', 'LG', 'Maytag', 'Midea', 'Miele', 'Monogram', 'Panasonic',
        'Samsung', 'Sharp', 'Speed Queen', 'Sub-Zero', 'Thermador', 'Whirlpool', 'Wolf',
    ],

];
