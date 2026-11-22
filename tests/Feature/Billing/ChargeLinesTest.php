<?php

use App\Enums\UserRole;
use App\Mail\ReceiptMail;
use App\Models\Brand;
use App\Models\Charge;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Support\Reports\TaxReserve;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's task of November 22: lines on New charge, each with its own tax ticks (the company's taxes; only the first
 * ticked on a new line), With tax / Without tax for all lines, each tax rounded once on the total, empty lines left
 * out, an amount without a description is "Service", and the lines with their taxes on the invoice, the receipt and
 * the tax reserve.
 */
const LINES_SQUARE = 'https://connect.squareupsandbox.com';

beforeEach(function () {
    config(['services.square' => [
        'environment' => 'sandbox', 'application_id' => 'sandbox-sq0idb-app', 'application_secret' => 'sandbox-sq0csb-secret',
        'webhook_signature_key' => 'whsec-test-key', 'webhook_url' => 'https://app.example.test/webhooks/payments/square',
        'api_version' => '2025-10-16',
    ]]);
    Mail::fake();

    $this->company = Company::factory()->create([
        'name' => 'Doctor Appliance', 'timezone' => 'America/Vancouver', 'country' => 'CA', 'currency' => 'CAD',
        'prices_include_tax' => false, 'business_hours' => Company::defaultBusinessHours(),
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Smith']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->gst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5, 'is_default' => true])->id;
    $this->pst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'PST', 'rate' => 7, 'is_default' => true, 'sort_order' => 1])->id;
    inCompany($this->company, fn () => PaymentProviderConnection::create([
        'provider' => 'square', 'account_id' => 'MLR1', 'account_name' => 'Doctor Appliance',
        'location_id' => 'LOC1', 'location_name' => 'Surrey', 'currency' => 'CAD',
        'access_token' => 'EAAAl-access-1', 'refresh_token' => 'EQAAl-refresh-1', 'token_expires_at' => now()->addDays(30),
    ]));
    $this->company->update(['payment_provider' => 'square']);

    $this->actingAs($this->owner);
});

function linesPreview(array $lines, bool $withTax): array
{
    return test()->postJson(route('charges.preview'), ['lines' => $lines, 'amounts_include_tax' => $withTax])->assertOk()->json();
}

function taxAmounts(array $preview): array
{
    return collect($preview['taxes'])->mapWithKeys(fn (array $tax) => [$tax['name'] => $tax['amount']])->all();
}

test('the screen has the company taxes with ids; only the first is ticked on a new line', function () {
    $this->get(route('charges.create'))->assertInertia(fn (Assert $page) => $page
        ->component('charges/new')
        ->where('taxes', [['id' => $this->gst, 'name' => 'GST', 'rate' => '5'], ['id' => $this->pst, 'name' => 'PST', 'rate' => '7']])
        ->where('default_tax_ids', [$this->gst]));
});

test('one line with GST only, without tax: GST on top, no PST in the totals', function () {
    $preview = linesPreview([['description' => 'Dryer repair', 'amount' => '100', 'tax_rate_ids' => [$this->gst]]], false);

    expect($preview['subtotal'])->toBe('$100.00')
        ->and(taxAmounts($preview))->toBe(['GST' => '$5.00'])
        ->and($preview['total_minor'])->toBe(10500);
});

test('several lines with different ticks, without tax: each line taxed by its own ticks', function () {
    $preview = linesPreview([
        ['description' => 'Labour', 'amount' => '100', 'tax_rate_ids' => [$this->gst]],
        ['description' => 'Belt', 'amount' => '50', 'tax_rate_ids' => [$this->gst, $this->pst]],
        ['description' => 'Disposal', 'amount' => '30', 'tax_rate_ids' => []],
    ], false);

    expect($preview['subtotal'])->toBe('$180.00')
        ->and(taxAmounts($preview))->toBe(['GST' => '$7.50', 'PST' => '$3.50'])
        ->and($preview['total'])->toBe('$191.00')
        ->and($preview['total_minor'])->toBe(19100);
});

test('with tax: the ticked taxes are taken out of each line and the total is what was typed', function () {
    $preview = linesPreview([
        ['description' => 'Labour', 'amount' => '105', 'tax_rate_ids' => [$this->gst]],
        ['description' => 'Belt', 'amount' => '112', 'tax_rate_ids' => [$this->gst, $this->pst]],
        ['description' => 'Disposal', 'amount' => '20', 'tax_rate_ids' => []],
    ], true);

    expect($preview['subtotal'])->toBe('$220.00')
        ->and(taxAmounts($preview))->toBe(['GST' => '$10.00', 'PST' => '$7.00'])
        ->and($preview['total_minor'])->toBe(23700);

    // Odd amounts with tax still come out at exactly what was typed.
    $odd = linesPreview([
        ['description' => 'A', 'amount' => '99.99', 'tax_rate_ids' => [$this->gst]],
        ['description' => 'B', 'amount' => '45.37', 'tax_rate_ids' => [$this->gst, $this->pst]],
        ['description' => 'C', 'amount' => '0.03', 'tax_rate_ids' => [$this->pst]],
    ], true);
    expect($odd['total_minor'])->toBe(14539);
});

test('each tax is rounded once on the total, not line by line', function () {
    // GST on each line is half a cent: rounded per line it would be 2 cents, on the total it is 1.
    $preview = linesPreview([
        ['description' => 'A', 'amount' => '0.10', 'tax_rate_ids' => [$this->gst]],
        ['description' => 'B', 'amount' => '0.10', 'tax_rate_ids' => [$this->gst, $this->pst]],
    ], false);

    expect(taxAmounts($preview))->toBe(['GST' => '$0.01', 'PST' => '$0.01'])
        ->and($preview['total_minor'])->toBe(22);
});

test('empty lines are left out; an amount without a description is "Service"', function () {
    $this->postJson(route('charges.store'), ['method' => 'cash', 'draft' => [
        'lines' => [
            ['description' => '', 'amount' => '', 'tax_rate_ids' => [$this->gst]],
            ['description' => null, 'amount' => '50', 'tax_rate_ids' => [$this->gst]],
            ['description' => '  ', 'amount' => null, 'tax_rate_ids' => [$this->gst, $this->pst]],
        ],
        'amounts_include_tax' => false,
        'customer' => null,
    ]])->assertOk()->assertJsonPath('status', 'paid');

    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    expect($invoice->items->pluck('description')->all())->toBe(['Service'])
        ->and($invoice->items->first()->tax_rate_ids)->toBe([$this->gst])
        ->and(array_column($invoice->taxes, 'name'))->toBe(['GST'])
        ->and($invoice->total)->toBe(5250);

    // A description needs an amount; nothing at all is refused.
    $this->postJson(route('charges.preview'), ['lines' => [['description' => 'Repair', 'amount' => '']], 'amounts_include_tax' => false])
        ->assertStatus(422);
    $this->postJson(route('charges.store'), ['method' => 'cash', 'draft' => [
        'lines' => [['description' => '', 'amount' => '']], 'amounts_include_tax' => false, 'customer' => null,
    ]])->assertStatus(422);
});

test('the total on the Charge button is the amount sent to Square and recorded', function () {
    $lines = [
        ['description' => 'Labour', 'amount' => '89', 'tax_rate_ids' => [$this->gst]],
        ['description' => 'Element', 'amount' => '64.50', 'tax_rate_ids' => [$this->gst, $this->pst]],
    ];
    $button = linesPreview($lines, false)['total_minor'];
    expect($button)->toBe(16570);

    $this->postJson(route('charges.store'), ['method' => 'card_reader', 'draft' => ['lines' => $lines, 'amounts_include_tax' => false, 'customer' => null]])
        ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('amount', $button);
    $charge = inCompany($this->company, fn () => Charge::query()->latest('id')->firstOrFail());

    Http::swap(new HttpFactory);
    Http::fake([LINES_SQUARE.'/v2/payments/*' => Http::response(['payment' => [
        'id' => 'PAY1', 'status' => 'COMPLETED', 'location_id' => 'LOC1', 'reference_id' => $charge->reference,
        'amount_money' => ['amount' => $button, 'currency' => 'CAD'], 'total_money' => ['amount' => $button, 'currency' => 'CAD'],
        'card_details' => ['status' => 'CAPTURED', 'card' => ['card_brand' => 'VISA', 'last_4' => '1111']],
        'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
    ]])]);
    $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAY1'])->assertOk()->assertJsonPath('status', 'paid');

    inCompany($this->company, function () use ($button) {
        $payment = Payment::query()->sole();
        $invoice = $payment->invoice()->with('items')->firstOrFail();
        expect($payment->amount)->toBe($button)
            ->and($invoice->total)->toBe($button)
            ->and($invoice->items->pluck('unit_price')->all())->toBe([8900, 6450])
            ->and($invoice->items->pluck('tax_rate_ids')->all())->toBe([[$this->gst], [$this->gst, $this->pst]])
            ->and(collect($invoice->taxes)->pluck('amount', 'name')->all())->toBe(['GST' => 768, 'PST' => 452]);
    });
});

test('the receipt lists every line, then Subtotal, GST, PST and Total; the tax reserve counts them', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00', 'America/Vancouver'));
    $this->postJson(route('charges.store'), ['method' => 'cash', 'draft' => [
        'lines' => [
            ['description' => 'Dishwasher repair', 'amount' => '100', 'tax_rate_ids' => [$this->gst]],
            ['description' => 'Drain pump', 'amount' => '80', 'tax_rate_ids' => [$this->gst, $this->pst]],
        ],
        'amounts_include_tax' => false,
        'customer' => null,
    ]])->assertOk();
    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());

    $html = inCompany($this->company, fn () => (new ReceiptMail($invoice))->render());
    expect($html)->toContain('Dishwasher repair')->toContain('$100.00')->toContain('Drain pump')->toContain('$80.00')
        ->toContain('Subtotal')->toContain('$180.00')->toContain('GST')->toContain('$9.00')
        ->toContain('PST')->toContain('$5.60')->toContain('$194.60');

    $reserve = inCompany($this->company, fn () => TaxReserve::month(CarbonImmutable::parse('2026-11-01', 'America/Vancouver'), $this->owner));
    expect(collect($reserve['taxes'])->pluck('collected', 'name')->all())->toBe(['GST' => 900, 'PST' => 560]);
});

test('a job\'s quick invoice keeps each line\'s ticks', function () {
    $customer = Customer::factory()->for($this->company)->create();
    $property = Property::factory()->for($customer)->create();
    $job = ServiceJob::factory()->for($property)->create(['brand_id' => $this->brand->id, 'status' => 'in_progress']);

    $this->post(route('jobs.quick-invoice', $job), [
        'lines' => [
            ['description' => 'Diagnostic', 'amount' => '89', 'tax_rate_ids' => [$this->gst]],
            ['description' => 'Belt', 'amount' => '40', 'tax_rate_ids' => [$this->pst]],
        ],
        'amounts_include_tax' => false,
    ])->assertRedirect();

    $invoice = inCompany($this->company, fn () => Invoice::query()->where('service_job_id', $job->id)->with('items')->sole());
    expect($invoice->subtotal)->toBe(12900)
        ->and(collect($invoice->taxes)->pluck('amount', 'name')->all())->toBe(['GST' => 445, 'PST' => 280])
        ->and($invoice->total)->toBe(13625);
});

test('another company\'s tax ids on a line are ignored', function () {
    $other = Company::factory()->create();
    $foreign = TaxRate::factory()->create(['company_id' => $other->id, 'name' => 'VAT', 'rate' => 20, 'is_default' => true])->id;

    $preview = linesPreview([['description' => 'Repair', 'amount' => '100', 'tax_rate_ids' => [$foreign, $this->gst]]], false);

    expect(taxAmounts($preview))->toBe(['GST' => '$5.00'])->and($preview['total_minor'])->toBe(10500);
});
