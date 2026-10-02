<?php

use App\Support\Billing\Money;
use App\Support\Locale\AddressFormatter;
use App\Support\Locale\Countries;
use App\Support\Locale\Currencies;
use App\Support\PhoneNumber;

test('money is formatted in its currency and the regional format, never with a hard-coded symbol', function (int $minor, string $currency, string $locale, string $expected) {
    expect(Money::format($minor, $currency, $locale))->toBe($expected);
})->with([
    'USD in the US' => [12345, 'USD', 'en-US', '$123.45'],
    'CAD in Canada' => [12345, 'CAD', 'en-CA', '$123.45'],
    'CAD shown to a US company' => [12345, 'CAD', 'en-US', 'CA$123.45'],
    'GBP' => [12345, 'GBP', 'en-GB', '£123.45'],
    'JPY has no decimals' => [12345, 'JPY', 'en-US', '¥12,345'],
]);

test('currencies know their minor units', function () {
    expect(Currencies::decimals('USD'))->toBe(2)
        ->and(Currencies::decimals('JPY'))->toBe(0)
        ->and(Currencies::decimals('KWD'))->toBe(3)
        ->and(Currencies::toMinor('12.5', 'USD'))->toBe(1250)
        ->and(Currencies::toMinor('1200', 'JPY'))->toBe(1200)
        ->and(Currencies::exists('EUR'))->toBeTrue()
        ->and(Currencies::exists('XYZ'))->toBeFalse();
});

test('a country gives its defaults', function () {
    expect(Countries::currency('US'))->toBe('USD')
        ->and(Countries::currency('CA'))->toBe('CAD')
        ->and(Countries::currency('DE'))->toBe('EUR')
        ->and(Countries::locale('GB'))->toBe('en-GB')
        ->and(Countries::timezone('US'))->toBe('America/New_York')
        ->and(Countries::exists('FR'))->toBeTrue()
        ->and(array_slice(Countries::codes(), 0, 2))->toBe(['US', 'CA']);
});

test('phone numbers are stored in E.164 using the country for national numbers', function () {
    expect(PhoneNumber::normalize('(512) 555-0142', 'US'))->toBe('+15125550142')
        ->and(PhoneNumber::normalize('020 7946 0958', 'GB'))->toBe('+442079460958')
        ->and(PhoneNumber::normalize('+44 20 7946 0958', 'US'))->toBe('+442079460958')
        ->and(PhoneNumber::normalize('0412 345 678', 'AU'))->toBe('+61412345678')
        ->and(PhoneNumber::isPossible('12', 'US'))->toBeFalse()
        ->and(PhoneNumber::searchDigits('020 7946'))->toBe('207946');
});

test('addresses are written in the order of their country', function () {
    expect(AddressFormatter::oneLine('123 Main St', 'Austin', 'TX', '78701', 'US'))->toBe('123 Main St, Austin, TX 78701')
        ->and(AddressFormatter::oneLine('10 Downing St', 'London', null, 'SW1A 2AA', 'GB'))->toBe('10 Downing St, London, SW1A 2AA')
        ->and(AddressFormatter::oneLine('Hauptstr. 5', 'Berlin', null, '10115', 'DE'))->toBe('Hauptstr. 5, 10115 Berlin');
});

test('phones are displayed in the national format of the company country', function () {
    expect(PhoneNumber::display('+16045550142', 'CA'))->toBe('(604) 555-0142')
        ->and(PhoneNumber::display('+15125550142', 'CA'))->toBe('(512) 555-0142')
        ->and(PhoneNumber::display('+442079460958', 'US'))->toBe('+44 20 7946 0958')
        ->and(PhoneNumber::display('+442079460958', 'GB'))->toBe('020 7946 0958');
});
