<?php

return [
    'title' => 'Google reviews',
    'description' => 'Your locations on Google and their review links. When asking for a review, the technician chooses the location.',
    'add' => 'Add location',
    'remove' => 'Remove location',
    'empty' => 'No locations yet. Add the "Ask for a review" link of each Google profile.',
    'saved' => 'Locations saved.',
    'link_hint' => 'In Google Business Profile: Ask for reviews → copy the link.',
    'fields' => [
        'label' => 'Location (e.g. Surrey)',
        'review_url' => 'Review link',
        'brand' => 'Brand',
        'default_profile' => 'Google profile for review requests',
    ],
    'any_brand' => 'Any brand',
    'none' => 'None',

    'settings' => 'Review requests',
    'manual_hint' => 'Technicians send the request at the end of the work, from the paid invoice, with the review link of whichever Google profile fits.',
    'rules' => 'Google and the FTC do not allow rewards or discounts for reviews, or asking only happy customers to post. Every customer gets the same request.',
    'fields_settings' => [
        'review_requests_default' => 'Ask for a review on new jobs',
        'review_request_delay_hours' => 'Send after full payment (hours)',
        'review_request_cooldown_days' => 'At most one request per customer every (days)',
    ],

    'ask_for_review' => 'Ask for a review',
    'ask_hint' => 'A Google review request goes out after the job is paid in full.',
    'choose_location' => 'Choose the location',
    'no_locations' => 'No locations yet. The company owner adds them in Company → Google reviews.',
    'send_again' => 'Send review request again',
    'no_contact' => 'The customer has no mobile number or email to send the request to.',
    'finish_title' => 'Ask for a Google review',
    'send_request' => 'Send review request',
    'statuses' => [
        'scheduled' => 'Review request scheduled for :date',
        'sent' => 'Review request sent :date',
        'skipped' => 'Review request not sent: :reason',
    ],
    'skipped' => [
        'turned_off' => '"Ask for a review" is off for this job.',
        'no_profile' => 'No Google profile is set up.',
        'recent' => 'The customer already got a review request in the last :days days.',
    ],
];
