<?php

use App\Support\Billing\DocumentTotals;

test('totals add line items and taxes in cents', function () {
    $totals = DocumentTotals::calculate(
        [
            ['quantity' => '1', 'unit_price' => 12000, 'taxable' => true],
            ['quantity' => '2', 'unit_price' => 4550, 'taxable' => true],
        ],
        null,
        0,
        [['tax_rate_id' => 1, 'name' => 'GST', 'rate' => '5.0000'], ['tax_rate_id' => 2, 'name' => 'PST', 'rate' => '7.0000']],
    );

    expect($totals['item_totals'])->toBe([12000, 9100])
        ->and($totals['subtotal'])->toBe(21100)
        ->and($totals['discount_total'])->toBe(0)
        ->and($totals['taxes'])->toBe([
            ['tax_rate_id' => 1, 'name' => 'GST', 'rate' => '5', 'amount' => 1055],
            ['tax_rate_id' => 2, 'name' => 'PST', 'rate' => '7', 'amount' => 1477],
        ])
        ->and($totals['tax_total'])->toBe(2532)
        ->and($totals['total'])->toBe(23632);
});

test('fractional quantities round to the cent', function () {
    expect(DocumentTotals::lineTotal('1.5', 9999))->toBe(14999)
        ->and(DocumentTotals::lineTotal('0.33', 100))->toBe(33);
});

test('non-taxable lines are not taxed', function () {
    $totals = DocumentTotals::calculate(
        [
            ['quantity' => '1', 'unit_price' => 10000, 'taxable' => true],
            ['quantity' => '1', 'unit_price' => 5000, 'taxable' => false],
        ],
        null,
        null,
        [['tax_rate_id' => null, 'name' => 'GST', 'rate' => 5]],
    );

    expect($totals['tax_total'])->toBe(500)->and($totals['total'])->toBe(15500);
});

test('a percent discount is taken before taxes and shared by taxable lines', function () {
    $totals = DocumentTotals::calculate(
        [
            ['quantity' => '1', 'unit_price' => 10000, 'taxable' => true],
            ['quantity' => '1', 'unit_price' => 10000, 'taxable' => false],
        ],
        'percent',
        '10',
        [['tax_rate_id' => null, 'name' => 'GST', 'rate' => 5]],
    );

    // Discount 20.00; taxable share 10.00; tax 5% of 90.00.
    expect($totals['discount_total'])->toBe(2000)
        ->and($totals['tax_total'])->toBe(450)
        ->and($totals['total'])->toBe(18450);
});

test('a fixed discount never exceeds the subtotal', function () {
    $totals = DocumentTotals::calculate(
        [['quantity' => '1', 'unit_price' => 5000, 'taxable' => true]],
        'amount',
        '80.00',
        [['tax_rate_id' => null, 'name' => 'GST', 'rate' => 5]],
    );

    expect($totals['discount_total'])->toBe(5000)->and($totals['total'])->toBe(0);
});

test('credit lines lower the subtotal', function () {
    $totals = DocumentTotals::calculate(
        [
            ['quantity' => '1', 'unit_price' => 30000, 'taxable' => true],
            ['quantity' => '1', 'unit_price' => -8900, 'taxable' => true],
        ],
        null,
        0,
        [],
    );

    expect($totals['subtotal'])->toBe(21100)->and($totals['total'])->toBe(21100);
});
