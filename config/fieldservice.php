<?php

return [

    /*
    | Disk used for uploaded media (brand logos now; photos and PDFs later).
    | "public" (local) for now; switch to "s3" via .env once object storage is chosen.
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    | Owners, Admins and super-admins must enable two-factor authentication
    | before they can use the app (SPEC §9).
    */
    'require_two_factor' => (bool) env('AUTH_REQUIRE_TWO_FACTOR', true),

    'currencies' => ['CAD', 'USD'],

    'default_timezone' => 'America/Vancouver',

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
