<?php

return [
    'title' => 'Google reviews',
    'description' => 'Google Business Profiles and their review links. Each brand picks its default profile.',
    'add' => 'Add profile',
    'remove' => 'Remove profile',
    'empty' => 'No Google profiles yet. Add the "Ask for a review" link of each profile.',
    'saved' => 'Google profiles saved.',
    'link_hint' => 'In Google Business Profile: Ask for reviews → copy the link.',
    'fields' => [
        'label' => 'Label (e.g. Surrey)',
        'review_url' => 'Review link',
        'brand' => 'Brand',
        'default_profile' => 'Google profile for review requests',
    ],
    'any_brand' => 'Any brand',
    'none' => 'None',

    'settings' => 'Review requests',
    'rules' => 'Google and the FTC do not allow rewards or discounts for reviews, or asking only happy customers to post. Every customer gets the same request.',
    'fields_settings' => [
        'review_requests_default' => 'Ask for a review on new jobs',
        'review_request_delay_hours' => 'Send after full payment (hours)',
        'review_request_cooldown_days' => 'At most one request per customer every (days)',
    ],

    'ask_for_review' => 'Ask for a review',
    'ask_hint' => 'A Google review request goes out after the job is paid in full.',
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
