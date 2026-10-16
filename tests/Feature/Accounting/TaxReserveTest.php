<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxReserveTransfer;
use App\Notifications\AppAlert;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * UX-REDESIGN §17: how much to move to the tax account each month.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2030-10-03 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver', 'currency' => 'CAD', 'tax_reserve_enabled' => true]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->gst = inCompany($this->company, fn () => $this->company->taxRates()->create(['name' => 'GST', 'rate' => 5, 'is_active' => true, 'is_recoverable' => true]));
    $this->job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);
});

function reserveInvoice(string $issuedOn = '2030-09-10'): Invoice
{
    test()->post(route('invoices.store', test()->job), documentPayload([
        'issued_on' => $issuedOn,
        'tax_rate_ids' => [test()->gst->id],
        'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '200.00', 'taxable' => true, 'tax_rate_ids' => [test()->gst->id]]],
    ]))->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => Invoice::query()->latest('id')->first());
}

test('the month shows the tax on invoices paid in it, the income tax on the profit and the total', function () {
    $invoice = reserveInvoice();
    expect($invoice->total)->toBe(21000);
    // Paid in September; the job closed in September (profit $200 before tax).
    $this->post(route('payments.store', $invoice), ['amount' => '210.00', 'method' => 'cash', 'received_on' => '2030-09-12'])->assertSessionHasNoErrors();
    inCompany($this->company, fn () => $this->job->forceFill(['closed_at' => CarbonImmutable::parse('2030-09-12 12:00', 'America/Vancouver')])->save());

    $this->get(route('tax-reserve.index', ['month' => '2030-09']))->assertInertia(fn (Assert $page) => $page
        ->component('expenses/tax-reserve')
        ->where('reserve.taxes', [['name' => 'GST', 'collected' => 1000, 'deducted' => 0, 'amount' => 1000]])
        ->where('reserve.profit', 20000)
        ->where('reserve.income_tax', 4200)
        ->where('reserve.total', 5200)
        ->where('reserve.left', 5200));

    // Nothing was paid in August.
    $this->get(route('tax-reserve.index', ['month' => '2030-08']))->assertInertia(fn (Assert $page) => $page
        ->where('reserve.taxes', [])->where('reserve.total', 0));
});

test('a refund takes its tax back; void payments do not count', function () {
    $invoice = reserveInvoice();
    $this->post(route('payments.store', $invoice), ['amount' => '210.00', 'method' => 'cash', 'received_on' => '2030-09-12'])->assertSessionHasNoErrors();
    $this->post(route('invoices.refund', $invoice), ['amount' => '105.00', 'reason' => 'Part returned'])->assertSessionHasNoErrors();

    $this->get(route('tax-reserve.index', ['month' => now('America/Vancouver')->format('Y-m')]))->assertInertia(fn (Assert $page) => $page
        ->where('reserve.taxes.0.amount', -500));
    $this->get(route('tax-reserve.index', ['month' => '2030-09']))->assertInertia(fn (Assert $page) => $page
        ->where('reserve.taxes.0.amount', 1000));
});

test('"issued" counts invoices issued in the month and expense tax can be taken off', function () {
    reserveInvoice('2030-09-10');
    $category = inCompany($this->company, fn () => BusinessExpenseCategory::query()->create(['name' => 'Tools']));
    inCompany($this->company, fn () => BusinessExpense::query()->forceCreate([
        'company_id' => $this->company->id, 'category_id' => $category->id, 'spent_on' => '2030-09-15', 'description' => 'Drill',
        'amount' => 4000, 'tax_amount' => 200, 'currency' => 'CAD', 'created_by' => $this->owner->id,
        'taxes' => [['tax_rate_id' => $this->gst->id, 'name' => 'GST', 'rate' => '5', 'amount' => 200]],
    ]));

    $this->put(route('tax-reserve.settings'), ['enabled' => true, 'rate' => 21, 'deduct_expense_tax' => true, 'basis' => 'issued', 'reminder_day' => 5])
        ->assertSessionHasNoErrors();

    $this->get(route('tax-reserve.index', ['month' => '2030-09']))->assertInertia(fn (Assert $page) => $page
        ->where('reserve.taxes', [['name' => 'GST', 'collected' => 1000, 'deducted' => 200, 'amount' => 800]]));
});

test('marking a transfer leaves what is still to move; the CSV lists the months', function () {
    $invoice = reserveInvoice();
    $this->post(route('payments.store', $invoice), ['amount' => '210.00', 'method' => 'cash', 'received_on' => '2030-09-12']);

    $this->post(route('tax-reserve.transfers.store'), ['month' => '2030-09', 'amount' => '6.00', 'transferred_on' => '2030-10-02'])
        ->assertSessionHasNoErrors();

    $this->get(route('tax-reserve.index', ['month' => '2030-09']))->assertInertia(fn (Assert $page) => $page
        ->where('reserve.transferred', 600)
        ->where('reserve.left', 400)
        ->has('transfers', 1));
    // October looks back: September is still short.
    $this->get(route('tax-reserve.index', ['month' => '2030-10']))->assertInertia(fn (Assert $page) => $page
        ->where('behind', [['month' => '2030-09-01', 'left' => 400]]));

    $csv = $this->get(route('tax-reserve.export', ['year' => 2030]))->assertOk()->streamedContent();
    expect($csv)->toContain('2030-09,"GST: 10.00",10.00,0.00,21.00,0.00,10.00,6.00,4.00,CAD');
});

test('only the Owner sees the tax reserve; another company\'s transfers are never shown', function () {
    $admin = memberOf($this->company, UserRole::Admin);
    $this->actingAs($admin)->get(route('tax-reserve.index'))->assertForbidden();
    $this->actingAs($admin)->post(route('tax-reserve.transfers.store'), ['month' => '2030-09', 'amount' => '1', 'transferred_on' => '2030-10-01'])->assertForbidden();

    app(CurrentCompany::class)->forget();
    $other = Company::factory()->create(['tax_reserve_enabled' => true]);
    $otherOwner = memberOf($other, UserRole::Owner);
    inCompany($other, fn () => TaxReserveTransfer::query()->create(['month' => '2030-09-01', 'amount' => 999, 'currency' => 'CAD', 'transferred_on' => '2030-10-01']));
    app(CurrentCompany::class)->forget();

    $this->actingAs($this->owner)->get(route('tax-reserve.index', ['month' => '2030-09']))->assertInertia(fn (Assert $page) => $page
        ->has('transfers', 0)->where('reserve.transferred', 0));
});

test('on the chosen day the Owner is reminded once how much to move for last month', function () {
    Notification::fake();
    $invoice = reserveInvoice();
    $this->post(route('payments.store', $invoice), ['amount' => '210.00', 'method' => 'cash', 'received_on' => '2030-09-12']);
    $this->company->update(['tax_reserve_reminder_day' => 3]);
    app(CurrentCompany::class)->forget();

    $this->artisan('tax-reserve:remind')->assertSuccessful();
    $this->artisan('tax-reserve:remind')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, AppAlert::class, 1);
    Notification::assertSentTo($this->owner, AppAlert::class, fn (AppAlert $alert) => $alert->kind === 'tax_reserve'
        && str_contains($alert->body, '$10.00') && str_contains($alert->body, 'September'));
});
