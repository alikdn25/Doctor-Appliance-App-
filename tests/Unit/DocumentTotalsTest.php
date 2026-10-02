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
            ['tax_rate_id' => 1, 'name' => 'GST', 'rate' => '5', 'compound' => false, 'amount' => 1055],
            ['tax_rate_id' => 2, 'name' => 'PST', 'rate' => '7', 'compound' => false, 'amount' => 1477],
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

test('a compound tax is charged on the amount plus the taxes before it', function () {
    $totals = DocumentTotals::calculate(
        [['quantity' => '1', 'unit_price' => 10000, 'taxable' => true]],
        null,
        0,
        [
            ['tax_rate_id' => 1, 'name' => 'GST', 'rate' => '5'],
            ['tax_rate_id' => 2, 'name' => 'QST', 'rate' => '10', 'compound' => true],
        ],
    );

    // GST 5.00 on 100.00; QST 10% of 105.00.
    expect(array_column($totals['taxes'], 'amount'))->toBe([500, 1050])
        ->and($totals['taxes'][1]['compound'])->toBeTrue()
        ->and($totals['total'])->toBe(11550);
});

test('with tax-inclusive prices the taxes are taken out of the total', function () {
    $totals = DocumentTotals::calculate(
        [['quantity' => '1', 'unit_price' => 12000, 'taxable' => true]],
        null,
        0,
        [['tax_rate_id' => 1, 'name' => 'VAT', 'rate' => '20']],
        pricesIncludeTax: true,
    );

    // £120.00 including 20% VAT = £100.00 + £20.00 VAT.
    expect($totals['tax_total'])->toBe(2000)
        ->and($totals['subtotal'])->toBe(12000)
        ->and($totals['total'])->toBe(12000);
});

test('tax-inclusive taxes always add up to the gross amount', function () {
    $totals = DocumentTotals::calculate(
        [['quantity' => '3', 'unit_price' => 3333, 'taxable' => true], ['quantity' => '1', 'unit_price' => 500, 'taxable' => false]],
        'percent',
        '7',
        [['tax_rate_id' => 1, 'name' => 'GST', 'rate' => '5'], ['tax_rate_id' => 2, 'name' => 'PST', 'rate' => '7']],
        pricesIncludeTax: true,
    );

    expect($totals['total'])->toBe($totals['subtotal'] - $totals['discount_total'])
        ->and($totals['tax_total'])->toBe(array_sum(array_column($totals['taxes'], 'amount')));
});

test('a fixed discount uses the minor units of the currency', function () {
    // JPY has no minor unit: a discount of 500 yen is 500.
    $totals = DocumentTotals::calculate(
        [['quantity' => '1', 'unit_price' => 12000, 'taxable' => false]],
        'amount',
        '500',
        [],
        minorFactor: 1,
    );

    expect($totals['discount_total'])->toBe(500)->and($totals['total'])->toBe(11500);
});

test('optional lines that are not picked keep their line total but are not counted', function () {
    $totals = DocumentTotals::calculate(
        [
            ['quantity' => '1', 'unit_price' => 10000, 'taxable' => true],
            ['quantity' => '1', 'unit_price' => 5000, 'taxable' => true, 'included' => false],
            ['quantity' => '2', 'unit_price' => 1000, 'taxable' => false, 'included' => true],
        ],
        'percent',
        10,
        [['tax_rate_id' => 1, 'name' => 'Tax', 'rate' => '10']],
    );

    expect($totals['item_totals'])->toBe([10000, 5000, 2000])
        ->and($totals['subtotal'])->toBe(12000)
        // 10% of 12000; the taxable line carries 10000/12000 of it.
        ->and($totals['discount_total'])->toBe(1200)
        ->and($totals['tax_total'])->toBe(900)
        ->and($totals['total'])->toBe(11700);
});


test('each line chooses its own taxes and shares discounts with exempt and excluded lines', function () {
    $totals = DocumentTotals::calculate([
        ['quantity' => 1, 'unit_price' => 10000, 'taxable' => true, 'tax_rate_ids' => [1]],
        ['quantity' => 1, 'unit_price' => 20000, 'taxable' => true, 'tax_rate_ids' => [1, 2]],
        ['quantity' => 1, 'unit_price' => 10000, 'taxable' => true, 'tax_rate_ids' => []],
        ['quantity' => 1, 'unit_price' => 99999, 'taxable' => true, 'tax_rate_ids' => [2], 'included' => false],
    ], 'percent', 10, [
        ['tax_rate_id' => 1, 'name' => 'Federal', 'rate' => 5],
        ['tax_rate_id' => 2, 'name' => 'Regional', 'rate' => 7],
    ]);
    expect($totals['subtotal'])->toBe(40000)->and($totals['discount_total'])->toBe(4000)
        ->and(array_column($totals['taxes'], 'amount'))->toBe([1350, 1260])->and($totals['total'])->toBe(38610);
});

test('a compound tax uses only the other taxes selected on the same lines', function () {
    $totals = DocumentTotals::calculate([
        ['quantity' => 1, 'unit_price' => 10000, 'taxable' => true, 'tax_rate_ids' => [1, 2]],
        ['quantity' => 1, 'unit_price' => 10000, 'taxable' => true, 'tax_rate_ids' => [2]],
        ['quantity' => 1, 'unit_price' => 10000, 'taxable' => false, 'tax_rate_ids' => [1, 2]],
    ], null, 0, [
        ['tax_rate_id' => 1, 'name' => 'Base tax', 'rate' => 5],
        ['tax_rate_id' => 2, 'name' => 'Compound', 'rate' => 10, 'compound' => true],
    ]);
    expect(array_column($totals['taxes'], 'amount'))->toBe([500, 2050])->and($totals['total'])->toBe(32550);
});

test('inclusive prices extract each line combination independently', function () {
    $totals = DocumentTotals::calculate([
        ['quantity' => 1, 'unit_price' => 11550, 'taxable' => true, 'tax_rate_ids' => [1, 2]],
        ['quantity' => 1, 'unit_price' => 11000, 'taxable' => true, 'tax_rate_ids' => [2]],
        ['quantity' => 1, 'unit_price' => 1000, 'taxable' => true, 'tax_rate_ids' => []],
    ], null, 0, [
        ['tax_rate_id' => 1, 'name' => 'Base tax', 'rate' => 5],
        ['tax_rate_id' => 2, 'name' => 'Compound', 'rate' => 10, 'compound' => true],
    ], pricesIncludeTax: true);
    expect(array_column($totals['taxes'], 'amount'))->toBe([500, 2050])->and($totals['total'])->toBe(23550);
});

test('matching selections aggregate before rounding and null inherits document taxes', function () {
    $totals = DocumentTotals::calculate([
        ['quantity' => 1, 'unit_price' => 5, 'taxable' => true, 'tax_rate_ids' => [1]],
        ['quantity' => 1, 'unit_price' => 5, 'taxable' => true, 'tax_rate_ids' => null],
    ], null, 0, [['tax_rate_id' => 1, 'name' => 'Tax', 'rate' => 5]], minorFactor: 1);
    expect($totals['tax_total'])->toBe(1)->and($totals['total'])->toBe(11);
});
