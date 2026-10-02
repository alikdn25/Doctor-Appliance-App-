<?php

return [
    'add' => 'Add property',
    'edit' => 'Edit property',
    'empty' => 'No properties yet.',
    'created' => 'Property added.',
    'updated' => 'Property saved.',
    'deleted' => 'Property deleted.',
    'delete' => 'Delete property',
    'confirm_delete' => 'Delete this property with all its appliances?',
    'has_jobs' => 'This property has jobs and cannot be deleted.',
    'primary' => 'Primary',
    'is_primary' => 'Primary property',
    'open_in_maps' => 'Open in Maps',
    'unit_prefix' => 'Unit :unit',
    'gate_code' => 'Gate / buzzer: :code',
    'site_contact' => 'On-site contact',
    'call_site_contact' => 'Call on-site contact',
    'address_hint' => 'Enter the address manually. Address autocomplete comes later.',

    'fields' => [
        'label' => 'Label (e.g. Home, Rental)',
        'line1' => 'Street address',
        'line2' => 'Address line 2',
        'unit' => 'Unit / suite',
        'city' => 'City',
        'region' => 'State / province / region',
        'postal_code' => 'ZIP / postal code',
        'country' => 'Country',
        'access_notes' => 'Access notes',
        'gate_code' => 'Gate / buzzer code',
        'site_contact_name' => 'On-site contact name',
        'site_contact_phone' => 'On-site contact phone',
    ],

    // Labels of the region and postal code fields per country (config/countries.php).
    'regions' => [
        'state' => 'State',
        'province' => 'Province',
        'county' => 'County',
        'region' => 'Region',
    ],
    'postals' => [
        'zip_code' => 'ZIP code',
        'postal_code' => 'Postal code',
        'postcode' => 'Postcode',
        'eircode' => 'Eircode',
    ],
    'invalid_postal' => 'Enter a valid :label.',

    'site_contact_hint' => 'For example a tenant, when the landlord or property manager pays.',
];
