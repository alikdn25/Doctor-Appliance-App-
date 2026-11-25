<?php

return [
    'estimate' => 'Estimate',
    'invoice' => 'Invoice',
    'number' => 'Number',
    'date' => 'Date',
    'due' => 'Due',
    'valid_until' => 'Valid until',
    'job' => 'Job',
    'bill_to' => 'Bill to',
    'terms' => 'Terms',
    'tax_number' => 'Tax registration: :number',
    'business_number' => 'Business number: :number',
    'pay_online_pdf' => 'Pay online:',

    'download_pdf' => 'PDF',
    'send' => 'Send by email',
    'send_title' => 'Send :kind :number',
    'send_description' => 'The customer gets the PDF and a link to view it online.',
    'to' => 'To',
    'message' => 'Message',
    'sent' => ':kind sent to :email.',
    'sent_on' => 'Sent to :email on :date',
    'viewed_on' => 'Viewed by the customer on :date',
    'no_email' => 'This customer has no email address yet. Type one here.',
    'copy_link' => 'Copy customer link',
    'link_copied' => 'Customer link copied.',
    'link_failed' => 'Could not make the link. Try again.',
    'send_menu' => 'Send',
    'send_email' => 'Send by email',
    'resend' => 'Send again',
    'resend_button' => 'Resend',
    'edited_after_send' => 'Changed after it was sent: the customer still has the old version.',

    'default_message' => [
        'estimate' => "Hi :name,\n\nThank you for choosing :brand. Please find estimate :number for :total attached. You can also view it online with the link below.\n\nKind regards,\n:brand",
        'invoice' => "Hi :name,\n\nThank you for choosing :brand. Please find invoice :number for :total attached.\n\n:pay_line\n\nKind regards,\n:brand",
        'invoice_paid' => "Hi :name,\n\nThank you for choosing :brand. Please find invoice :number for :total attached. It is paid in full — thank you!\n\nKind regards,\n:brand",
    ],

    'mail' => [
        'subject' => ':kind :number from :brand',
        'view' => 'View :kind',
        'link_hint' => 'If the button does not work, copy this link into your browser:',
        // The Google review block goes right before this line of the invoice message.
        'pay_line' => 'You can view it and pay online with the link below.',
        'review' => [
            'title' => 'Every review helps a local family business grow.',
            'text' => 'If I fixed it right, would you take 30 seconds to tell your neighbours on Google? It means more than you know. 🙏',
            'stars' => '⭐⭐⭐⭐⭐',
            'cta' => 'Leave a review →',
            'image_alt' => 'Leave us a Google review',
        ],
    ],

    'receipt' => [
        'subject' => 'Receipt from :brand · :number',
        'paid' => 'Paid',
        'invoice' => 'Invoice :number',
        'view' => 'View receipt online',
        'pdf' => 'Download PDF',
        'thanks' => 'Thank you for your business.',
        'reference' => 'Ref. :reference',
        'send' => 'Send receipt',
        'sent' => 'Receipt sent to :email.',
        'title' => 'Send receipt',
        'description' => 'A receipt with the amount paid, the items and the PDF goes to the customer by email.',
    ],

    'public' => [
        'download' => 'Download PDF',
        'pay' => 'Pay :amount online',
        'paid' => 'Paid in full. Thank you!',
        'void' => 'This invoice was cancelled.',
        'contact' => 'Questions? Contact :brand.',
        'pay_unavailable' => 'Online payment is not available right now. Please contact us.',
    ],
];
