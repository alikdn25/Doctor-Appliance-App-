<?php

return [
    'title' => 'Google reviews',
    'description' => 'Your locations and their Google review links. Name each location yourself (e.g. Surrey, Burnaby); you pick one when you send a review request after an invoice.',
    'add' => 'Add location',
    'remove' => 'Remove location',
    'empty' => 'No locations yet. Add each Google Business Profile\'s "Ask for reviews" link.',
    'saved' => 'Google review locations saved.',
    'link_hint' => 'In Google Business Profile: Ask for reviews → copy the link.',
    'fields' => [
        'label' => 'Location name (e.g. Surrey)',
        'review_url' => 'Review link',
        'brand' => 'Brand',
        'default_profile' => 'Default Google review location',
    ],
    'any_brand' => 'Any brand',
    'none' => 'None',
    'rules' => 'Google and the FTC do not allow rewards or discounts for reviews, or asking only happy customers to post. Every customer gets the same request.',

    'prompt' => [
        'title' => 'Send Google Review request?',
        'description' => 'The review link goes to the customer as a separate text.',
        'yes' => 'Yes',
        'no' => 'No',
        'location' => 'Location',
        'choose_location' => 'Choose a location',
        'phone' => 'Phone',
        'send' => 'Send',
        'no_locations' => 'No Google review locations yet. Add them in Company → Google reviews.',
        'sms_off' => 'Texting is off for the company (Company → Messaging), so a review request cannot be sent by SMS.',
        'invalid_phone' => 'Enter a valid phone number.',
    ],
];
