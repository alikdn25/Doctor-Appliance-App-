<?php

use App\Support\PhoneNumber;

test('phone numbers are normalized to E.164', function (string $input, string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    'dashes' => ['604-555-0100', '+16045550100'],
    'brackets and spaces' => ['(604) 555 0100', '+16045550100'],
    'leading 1' => ['1 604 555 0100', '+16045550100'],
    'already E.164' => ['+1 604 555 0100', '+16045550100'],
    'international' => ['+44 20 7946 0958', '+442079460958'],
    'empty' => ['', ''],
]);
