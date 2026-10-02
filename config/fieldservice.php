<?php

return [

    /*
    | Disk for media that may be public (brand logos shown on customer documents).
    | "public" (local) for now; switch to "s3" via .env once object storage is chosen.
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    | Disk for private media: job photos, signatures, rating plate photos.
    | Never served by URL; files go out only through routes that check access.
    | "local" (storage/app/private) for now; a private S3 bucket later.
    */
    'private_media_disk' => env('PRIVATE_MEDIA_DISK', 'local'),

    /*
    | Owners, Admins and super-admins must enable two-factor authentication
    | before they can use the app (SPEC §9).
    */
    'require_two_factor' => (bool) env('AUTH_REQUIRE_TWO_FACTOR', true),

    'currencies' => ['CAD', 'USD'],

    'default_timezone' => 'America/Vancouver',

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
