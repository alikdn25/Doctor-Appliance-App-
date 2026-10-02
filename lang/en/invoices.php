<?php

return [
    'title' => 'Invoices',
    'invoice' => 'Invoice',
    'number' => 'Invoice :number',
    'add' => 'New invoice',
    'edit' => 'Edit invoice',
    'created' => 'Invoice :number created.',
    'updated' => 'Invoice saved.',
    'voided' => 'Invoice voided.',
    'void' => 'Void invoice',
    'void_reason' => 'Reason (optional)',
    'void_description' => 'A void invoice stays in the history but asks for no money. Void its payments first.',
    'voided_by' => 'Voided :date by :name',
    'paid' => 'Paid',
    'balance' => 'Balance due',
    'due_date' => 'Due :date',
    'from_estimate' => 'From estimate :number',
    'search' => 'Invoice #, customer, job #, phone or address',
    'outstanding' => 'Outstanding',
    'outstanding_total' => 'Outstanding: :amount',
    'all_statuses' => 'All invoices',
    'empty' => 'No invoices match your filters.',
    'customer' => 'Customer',
    'paid_in_full' => 'Paid in full',

    'statuses' => [
        'unpaid' => 'Unpaid',
        'partially_paid' => 'Partially paid',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
        'void' => 'Void',
    ],

    'terms_hint' => 'Customer terms: :terms',

    'terms' => [
        'due_on_receipt' => 'Due on receipt',
        'net_7' => 'Net 7',
        'net_15' => 'Net 15',
        'net_30' => 'Net 30',
    ],

    'fields' => [
        'due_on' => 'Due date',
    ],

    'errors' => [
        'void' => 'This invoice is void.',
        'has_payments' => 'Void the payments of this invoice first.',
        'total_below_paid' => 'The total cannot be less than what has already been paid.',
    ],
];
