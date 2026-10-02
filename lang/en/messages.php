<?php

return [
    'title' => 'Messages',
    'empty' => 'No messages yet.',
    'settings_title' => 'Messaging',
    'settings_description' => 'How customers get texts and emails, message templates, and SMS registration.',

    'mode' => 'How customers get texts',
    'quiet_from' => 'Quiet hours from',
    'quiet_until' => 'until',
    'quiet_hint' => 'No automatic texts at night (company time zone); they go out when the quiet hours end.',
    'modes' => [
        'automatic' => 'Automatic (SMS sent by the app)',
        'technician_phone' => "From technician's phone",
        'off' => 'Off (email only)',
    ],
    'mode_hints' => [
        'automatic' => 'Reminders, "On my way", links and review requests go out as SMS from your company number.',
        'technician_phone' => 'Buttons open the messages app on the phone with the text ready. Reminders and review requests go by email.',
        'off' => 'Everything that would be a text goes by email.',
    ],

    'kinds' => [
        'visit_reminder' => 'Visit reminder (day before)',
        'on_my_way' => 'On my way',
        'estimate_link' => 'Estimate link',
        'invoice_link' => 'Invoice link',
        'review_request' => 'Review request',
        'general' => 'Text from the job',
        'reply' => 'Reply',
    ],

    // Default texts (English). Companies edit their own in Messaging settings. Placeholders in {braces}.
    'templates' => [
        'visit_reminder' => "Hi {customer_first_name}, this is a reminder of your {brand} appointment {visit_date}.\nArrival window: {arrival_window}\nReply to this message if you need to change it.",
        'on_my_way' => 'Hi {customer_first_name}, {tech_name} from {brand} is on the way. Expected arrival: {arrival_window}',
        'estimate_link' => 'Hi {customer_first_name}, here is your estimate {number} from {brand} for {amount}: {link}',
        'invoice_link' => 'Hi {customer_first_name}, here is your invoice {number} from {brand}. Balance due: {amount}. View and pay online: {link}',
        'review_request' => 'Hi {customer_first_name}, thank you for choosing {brand}! Would you take a moment to review us on Google? {review_link}',
        'general' => 'Hi {customer_first_name}, this is {tech_name} from {brand}. ',
    ],
    'placeholders' => 'Placeholders: {customer_first_name}, {customer_name}, {brand}, {company}, {tech_name}, {visit_date}, {arrival_window}, {number}, {amount}, {link}, {review_link}. Leave a text empty to use the default.',
    'templates_title' => 'Message templates',
    'templates_saved' => 'Messaging settings saved.',
    'review_template_hint' => 'Google and the FTC do not allow offering anything for a review, or asking only happy customers. The same request goes to every customer.',

    'email_subject' => [
        'visit_reminder' => 'Reminder: your appointment with :brand',
        'on_my_way' => ':brand is on the way',
        'estimate_link' => 'Estimate :number from :brand',
        'invoice_link' => 'Invoice :number from :brand',
        'review_request' => 'How did we do? — :brand',
        'general' => 'Message from :brand',
    ],

    'statuses' => [
        'scheduled' => 'Scheduled',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'blocked' => 'Not sent',
        'received' => 'Received',
        'opened' => "Opened on technician's phone",
    ],
    'channels' => [
        'sms' => 'SMS',
        'email' => 'Email',
        'technician_phone' => "SMS opened from technician's phone",
    ],

    'blocked' => [
        'opted_out' => 'The customer replied STOP to our texts.',
        'no_phone' => 'The customer has no mobile number.',
        'no_email' => 'The customer has no email address.',
        'no_account' => 'SMS is not set up for the company yet.',
        'registration' => 'US carriers require the A2P 10DLC registration before texting US numbers. It is not approved yet.',
        'provider' => 'The SMS provider refused the message: :error',
    ],

    'opted_out' => 'Unsubscribed from texts',
    'opted_out_on' => 'Replied STOP on :date — no texts are sent to this number.',

    'send_sms' => 'Send SMS',
    'send_by_sms' => 'Send by SMS',
    'sms_sent' => 'Text sent.',
    'sms_queued' => 'Text scheduled for :time (quiet hours).',
    'sms_blocked' => 'Text not sent: :reason',
    'emailed_instead' => 'Sent by email instead.',

    'account' => [
        'title' => 'Company SMS number',
        'none' => 'No SMS number yet. The app gets a number for your company in your country; you do not need a Twilio account.',
        'set_up' => 'Get an SMS number',
        'number' => 'Your SMS number: :number',
        'ready' => 'SMS number ready.',
        'not_configured' => 'SMS is not available on this installation yet.',
        'failed' => 'Could not get an SMS number: :error',
    ],

    'registration' => [
        'title' => 'US texting registration (A2P 10DLC)',
        'description' => 'US carriers only deliver business texts from registered companies. Fill in your business details once; texts to US numbers start when the registration is approved (usually a few days). Canada and other countries do not need this.',
        'status' => 'Status: :status',
        'statuses' => [
            'draft' => 'Not submitted',
            'submitted' => 'Submitted — waiting for carrier approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ],
        'rejected_reason' => 'Reason: :reason',
        'save' => 'Save details',
        'submit' => 'Submit for registration',
        'saved' => 'Registration details saved.',
        'submitted' => 'Registration submitted.',
        'fields' => [
            'legal_name' => 'Legal business name',
            'business_type' => 'Business type',
            'ein' => 'EIN (tax ID)',
            'website' => 'Website',
            'street' => 'Street address',
            'city' => 'City',
            'region' => 'State',
            'postal_code' => 'ZIP code',
            'contact_first_name' => 'Contact first name',
            'contact_last_name' => 'Contact last name',
            'contact_email' => 'Contact email',
            'contact_phone' => 'Contact phone',
            'use_case_description' => 'What you text customers about',
            'sample_message' => 'Sample message',
        ],
        'business_types' => [
            'sole_proprietor' => 'Sole proprietor',
            'llc' => 'LLC',
            'corporation' => 'Corporation',
            'partnership' => 'Partnership',
            'non_profit' => 'Non-profit',
        ],
        'default_use_case' => 'Appointment reminders, technician arrival notices, estimates and invoices with payment links, and review requests for customers who booked a service.',
    ],

    'opened_on_phone' => "SMS opened from technician's phone",
    'open_job' => 'Open job',
];
