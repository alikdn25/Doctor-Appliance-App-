<?php

/*
| Country presets (SPEC §1.1). Any ISO 3166 country can be picked; the values below only
| override what is derived from ICU (currency, regional format) or describe formats ICU does not know.
|
| - timezone:      default time zone until the Owner's browser reports theirs (else the first zone of the country)
| - locale:        regional format (dates, times, numbers) — default "en-<country>"
| - address:       labels of the region and postal code fields, postal code pattern (validation),
|                  and the city line order: "city_region_postal" (US/CA/AU) or "postal_city" (most of Europe)
|                  or "city_postal" (UK/IE)
*/

return [

    'pinned' => ['US', 'CA'],

    'fallback' => [
        'timezone' => 'UTC',
        'address' => [
            'region' => 'region',
            'postal' => 'postal_code',
            'postal_pattern' => null,
            'order' => 'city_region_postal',
        ],
    ],

    'presets' => [
        'US' => [
            'timezone' => 'America/New_York',
            'address' => ['region' => 'state', 'postal' => 'zip_code', 'postal_pattern' => '/^\d{5}(-\d{4})?$/', 'order' => 'city_region_postal'],
        ],
        'CA' => [
            'timezone' => 'America/Toronto',
            'address' => ['region' => 'province', 'postal' => 'postal_code', 'postal_pattern' => '/^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$/', 'order' => 'city_region_postal'],
        ],
        'GB' => [
            'timezone' => 'Europe/London',
            'address' => ['region' => 'county', 'postal' => 'postcode', 'postal_pattern' => '/^[A-Za-z]{1,2}\d[A-Za-z\d]? ?\d[A-Za-z]{2}$/', 'order' => 'city_postal'],
        ],
        'IE' => [
            'timezone' => 'Europe/Dublin',
            'address' => ['region' => 'county', 'postal' => 'eircode', 'postal_pattern' => null, 'order' => 'city_postal'],
        ],
        'AU' => [
            'timezone' => 'Australia/Sydney',
            'address' => ['region' => 'state', 'postal' => 'postcode', 'postal_pattern' => '/^\d{4}$/', 'order' => 'city_region_postal'],
        ],
        'NZ' => [
            'timezone' => 'Pacific/Auckland',
            'address' => ['region' => 'region', 'postal' => 'postcode', 'postal_pattern' => '/^\d{4}$/', 'order' => 'city_postal'],
        ],
        'DE' => ['address' => ['region' => 'region', 'postal' => 'postal_code', 'postal_pattern' => '/^\d{5}$/', 'order' => 'postal_city']],
        'FR' => ['address' => ['region' => 'region', 'postal' => 'postal_code', 'postal_pattern' => '/^\d{5}$/', 'order' => 'postal_city']],
        'ES' => ['address' => ['region' => 'province', 'postal' => 'postal_code', 'postal_pattern' => '/^\d{5}$/', 'order' => 'postal_city']],
        'IT' => ['address' => ['region' => 'province', 'postal' => 'postal_code', 'postal_pattern' => '/^\d{5}$/', 'order' => 'postal_city']],
        'NL' => ['address' => ['region' => 'region', 'postal' => 'postal_code', 'postal_pattern' => null, 'order' => 'postal_city']],
        'MX' => [
            'timezone' => 'America/Mexico_City',
            'address' => ['region' => 'state', 'postal' => 'postal_code', 'postal_pattern' => '/^\d{5}$/', 'order' => 'postal_city'],
        ],
        'BR' => ['timezone' => 'America/Sao_Paulo'],
        'RU' => ['timezone' => 'Europe/Moscow'],
    ],

];
