<?php

return [
    'title' => 'Payments',
    'record' => 'Record payment',
    'empty' => 'No payments yet.',
    'recorded' => 'Payment of :amount (:method) recorded.',
    'voided' => 'Payment voided.',
    'void' => 'Void payment',
    'confirm_void' => 'Void this payment of :amount? It stays in the history.',
    'void_reason_prompt' => 'Why is this payment voided? (optional)',
    'voided_label' => 'Voided',
    'pay_full' => 'Full balance',
    'by' => 'by :name',
    'online' => 'Online (:provider)',

    'methods' => [
        'cash' => 'Cash',
        'check' => 'Check',
        'bank_transfer' => 'Bank transfer',
        'card_terminal' => 'Card (own terminal)',
        'other' => 'Other',
        'online' => 'Online',
    ],

    'fields' => [
        'amount' => 'Amount',
        'method' => 'Method',
        'reference' => 'Reference',
        'transaction_reference' => 'Transaction #',
        'check_number' => 'Check #',
        'transfer_reference' => 'Transfer reference (e-Transfer, Zelle, ACH …)',
        'note' => 'Note',
        'received_on' => 'Date received',
    ],

    'hints' => [
        'reference' => 'Optional reference number.',
        'card_terminal' => 'Transaction number from the terminal receipt.',
        'other' => 'How was it paid?',
    ],

    'errors' => [
        'invalid_method' => 'Choose a payment method.',
        'more_than_balance' => 'The amount is more than the balance due.',
        'amount_required' => 'Enter an amount above zero.',
        'provider_payment' => 'Online payments are refunded in the payment provider.',
        'currency_mismatch' => 'The payment is in :currency but the invoice is in :expected.',
    ],
];
