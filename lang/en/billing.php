<?php

return [
    'section' => 'Estimates & invoices',
    'empty' => 'No estimates or invoices yet.',
    'new_estimate' => 'New estimate',
    'new_invoice' => 'New invoice',
    'items' => 'Items',
    'add_item' => 'Add line',
    'remove_item' => 'Remove line',
    'taxable' => 'Taxable',
    'not_taxable' => 'No tax',
    'subtotal' => 'Subtotal',
    'discount' => 'Discount',
    'discount_percent' => 'Discount (:value%)',
    'total' => 'Total',
    'taxes' => 'Taxes',
    'no_taxes' => 'No taxes set up. Add them under Company → Taxes.',
    'tax_line' => ':name :rate%',
    'notes_hint' => 'Shown on the document (warranty on the repair, terms, etc.).',
    'job' => 'Job #:number',
    'issued' => 'Issued :date',
    'created_by' => 'Created by :name',
    'qty_times_price' => ':quantity × :price',

    'discount_types' => [
        'none' => 'No discount',
        'amount' => '$ amount',
        'percent' => '% of subtotal',
    ],

    'fields' => [
        'description' => 'Description',
        'quantity' => 'Qty',
        'unit_price' => 'Price',
        'line_total' => 'Amount',
        'discount' => 'Discount',
        'issued_on' => 'Date',
        'notes' => 'Notes',
    ],

    'errors' => [
        'negative_total' => 'The total cannot be below zero.',
    ],
];
