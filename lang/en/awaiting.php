<?php

// "Awaiting payment" (owner's task of November 25): the orange chip of the top strip and its list.
return [
    'chip' => 'Awaiting payment',
    'title' => 'Awaiting payment',
    'summary' => '{0} No jobs awaiting payment|{1} 1 job awaiting payment|[2,*] :count jobs awaiting payment',
    'empty' => 'Nothing is awaiting payment.',
    'days' => '{0} Sent today|{1} 1 day|[2,*] :count days',
    'states' => [
        'sent' => 'Sent',
        'opened' => 'Opened :time',
        'paid' => 'Paid',
    ],
    'due' => 'Due :amount',
];
