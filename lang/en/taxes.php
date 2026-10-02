<?php

return [
    'title' => 'Taxes',
    'description' => 'Tax rates used on estimates and invoices (e.g. GST 5%, PST 7%).',
    'add' => 'Add tax',
    'empty' => 'No taxes yet.',
    'form_description' => 'The rate is a percentage.',
    'default' => 'Default',
    'created' => 'Tax added.',
    'updated' => 'Tax saved.',
    'deleted' => 'Tax deleted.',
    'confirm_delete' => 'Delete tax ":name"?',

    'fields' => [
        'name' => 'Name',
        'rate' => 'Rate, %',
        'is_default' => 'Apply by default on new estimates and invoices',
        'is_active' => 'Active',
    ],
];
