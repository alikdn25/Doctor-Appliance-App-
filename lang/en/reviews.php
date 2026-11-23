<?php

return [
    'test_link' => 'Test link',
    'label_example' => 'e.g. Surrey',
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
    'default_for' => 'Default for :brand (preselected in review requests)',
    'none' => 'None',
    'rules' => 'Google and the FTC do not allow rewards or discounts for reviews, or asking only happy customers to post. Every customer gets the same request.',

    'image' => [
        'title' => 'Picture in invoice emails',
        'description' => 'Invoice emails show a "Leave a review" block with your Google review link. Add a picture to it (optional), e.g. you with a thumbs up. Square works best; it is shown at 110×110 and saved as a small PNG.',
        'field' => 'Picture',
        'choose' => 'Choose picture',
        'replace' => 'Replace picture',
        'remove' => 'Remove picture',
        'none' => 'No picture: the block shows text only.',
        'no_link' => 'Add a review link above: without one, invoice emails have no review block.',
        'saved' => 'Picture saved.',
        'removed' => 'Picture removed.',
        'unusable' => 'This picture cannot be used. Try a simpler PNG or JPEG.',
    ],

    // The review request text (owner's task of November 23). Placeholders: {first_name}, {brand}, {signer} (the
    // owner's first name), {link}. Only the words between <b> and </b> are bold in the email.
    'request' => [
        'named' => '<b>Hey {first_name}, would you take 30 seconds</b> to help me grow my small business by leaving a Google review? {link}',
        'unnamed' => 'Thanks for choosing {brand}! <b>Would you take 30 seconds</b> to help me grow my small business by leaving a Google review? {link}',
        'sign_named' => '— {signer}, {brand}',
        'sign_unnamed' => '— {signer}',
        'sign_brand' => '— {brand}',
    ],

    'prompt' => [
        'title' => 'Send Google Review request?',
        'description' => 'The review link goes to the customer by email, with your review picture. You can change the words for this customer.',
        'yes' => 'Yes',
        'no' => 'No',
        'location' => 'Location',
        'choose_location' => 'Choose a location',
        'email' => 'Email',
        'send' => 'Send',
        'sent' => 'Review request sent to :email.',
        'dont_send' => 'Don\'t send',
        'text' => 'Message',
        'text_hint' => 'Only for this customer. The review link is added at the end if you remove it. Words between <b> and </b> are bold.',
        'no_locations' => 'No Google review locations yet. Add them in Company → Google reviews.',
        'sms_off' => 'Texting is off for the company (Company → Messaging), so a review request cannot be sent by SMS.',
        'invalid_phone' => 'Enter a valid phone number.',
    ],
];
