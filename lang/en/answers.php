<?php

// Prices and answers, clarifying questions, the sticker with the model number (owner's task of November 19).
return [
    'sticker' => [
        'ask' => 'Could you send a photo of the sticker with the model number (no need to type it)? :where',
        'any' => 'Could you send a photo of the sticker with the model number (no need to type it)?',
        'where' => [
            'fridge' => 'On a fridge it is inside, on a side wall.',
            'washer' => 'On a washer it is on the rim of the door opening.',
            'dryer' => 'On a dryer it is on the door frame.',
            'dishwasher' => 'On a dishwasher it is on the edge of the door.',
            'range' => 'On a range it is behind the oven door or in the bottom drawer.',
        ],
    ],
    'debt_line' => 'The amount is :amount, you can pay here: :link',
    'auto_hello' => 'Thanks for your message!',

    'settings' => [
        'title' => 'Prices and answers',
        'description' => 'What the AI may say in reply drafts. It never names a price that is not here.',
        'diagnostic_fee' => 'Diagnostic fee',
        'service_areas' => 'Service areas',
        'service_areas_hint' => 'e.g. Vancouver, Burnaby, Surrey, Richmond',
        'hours_text' => 'Business hours',
        'hours_hint' => 'e.g. Mon–Fri 8–5, Sat 9–2',
        'notes' => 'Notes for answers',
        'notes_hint' => 'Anything the AI should know: warranty, brands you do not service, payment methods…',
        'ai_title' => 'AI replies',
        'translate' => 'Russian in the reply box becomes English before sending',
        'translate_hint' => 'You check the English text and press Send. English text is left as it is.',
        'auto_clarify' => 'Send clarifying questions automatically',
        'auto_clarify_hint' => 'Off: they are in the reply draft. On: sent by themselves in business hours, at most once per 24 hours to one number.',
        'reminder_title' => 'Soft payment reminder',
        'reminder_hint' => 'Placeholders: {first_name}, {my_name}, {company}, {appliance}, {month}. No amount and no link.',
        'outside_title' => 'Outside technicians',
        'outside_hint' => 'People you hand jobs to who are not in the app.',
        'name' => 'Name',
        'phone' => 'Phone',
        'add' => 'Add technician',
        'remove' => 'Remove',
        'bad_phone' => 'This phone number does not look right.',
        'saved' => 'Prices and answers saved.',
    ],

    'translate' => [
        'button' => 'To English',
        'done' => 'Translated to English — check it and press Send.',
        'failed' => 'Could not translate now. Edit the text or try again.',
    ],
];
