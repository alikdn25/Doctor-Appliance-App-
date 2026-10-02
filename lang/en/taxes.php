<?php

return [
    'title' => 'Taxes',
    'description' => 'Tax rates used on estimates and invoices, e.g. GST 5% + PST 7%, a sales tax of 8.25%, or VAT 20%. Whether prices include tax is set in Company settings.',
    'add' => 'Add tax',
    'empty' => 'No taxes yet.',
    'form_description' => 'The rate is a percentage.',
    'default' => 'Default',
    'compound' => 'Compound',
    'compound_hint' => 'Charged on the price plus the other taxes (applied after them).',
    'name_placeholder' => 'e.g. Sales tax',
    'created' => 'Tax added.',
    'updated' => 'Tax saved.',
    'deleted' => 'Tax deleted.',
    'confirm_delete' => 'Delete tax ":name"?',

    'fields' => [
        'name' => 'Name',
        'rate' => 'Rate, %',
        'is_compound' => 'Compound tax',
        'is_default' => 'Apply by default on new estimates and invoices',
        'is_active' => 'Active',
    ],
];
