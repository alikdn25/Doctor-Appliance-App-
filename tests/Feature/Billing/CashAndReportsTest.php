<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\PrivateMedia;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Cash on hand (collected by technicians, handed to the office, reversals; turning cash off) and the reports.
 */
beforeEach(function () {
    Storage::fake(PrivateMedia::diskName());
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Olga Owner']);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tim Tech']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);
    $this->post(route('invoices.store', $this->job), documentPayload());
    $this->invoice = inCompany($this->company, fn () => Invoice::query()->sole());
});

describe('cash on hand', function () {
    test('a technician records cash with a receipt photo; it is on hand until handed in', function () {
        $this->actingAs($this->tech)->post(route('payments.store', $this->invoice), [
            'amount' => '100.00', 'method' => 'cash', 'received_on' => '2026-10-14',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertSessionHasNoErrors();

        $payment = inCompany($this->company, fn () => Payment::query()->sole());
        Storage::disk(PrivateMedia::diskName())->assertExists($payment->receipt_path);
        $this->get(route('payments.receipt', $payment))->assertOk();
        $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
            ->where('cashOnHand.'.$this->company->currency, 10000));

        // The office records the cash handed in.
        $this->actingAs($this->owner);
        $this->get(route('cash.index'))->assertInertia(fn (Assert $page) => $page
            ->where('people', fn ($people) => collect($people)->firstWhere('name', 'Tim Tech')['balances'][$this->company->currency] === 10000));
        $this->post(route('cash.deposit'), ['user_id' => $this->tech->id, 'amount' => '60.00', 'date' => '2026-10-14'])->assertSessionHasNoErrors();

        expect(inCompany($this->company, fn () => (int) CashMovement::query()->where('user_id', $this->tech->id)->sum('amount')))->toBe(4000);
    });

    test('wrong entries are reversed with a reason, never deleted; a voided cash payment is reversed too', function () {
        $this->post(route('payments.store', $this->invoice), ['amount' => '50.00', 'method' => 'cash', 'received_on' => '2026-10-14']);
        $this->post(route('cash.deposit'), ['user_id' => $this->owner->id, 'amount' => '50.00', 'date' => '2026-10-14']);
        $deposit = inCompany($this->company, fn () => CashMovement::query()->where('type', 'deposit')->sole());

        $this->post(route('cash.reverse', $deposit), [])->assertSessionHasErrors('reason');
        $this->post(route('cash.reverse', $deposit), ['reason' => 'Typed the wrong amount'])->assertSessionHasNoErrors();
        $this->post(route('cash.reverse', $deposit), ['reason' => 'Again'])->assertSessionHasErrors('reason');

        $payment = inCompany($this->company, fn () => Payment::query()->sole());
        $this->post(route('payments.void', $payment), ['reason' => 'Entered twice'])->assertSessionHasNoErrors();

        $movements = inCompany($this->company, fn () => CashMovement::query()->orderBy('id')->get());
        expect($movements->pluck('type')->all())->toBe(['collected', 'deposit', 'reversal', 'reversal'])
            ->and((int) $movements->sum('amount'))->toBe(0);
    });

    test('a company that does not take cash refuses it and does not offer it', function () {
        $this->company->update(['accepts_cash' => false]);

        $this->get(route('invoices.show', $this->invoice))->assertInertia(fn (Assert $page) => $page
            ->where('paymentMethods', fn ($methods) => ! collect($methods)->contains('value', 'cash')));
        $this->actingAs($this->tech)->post(route('payments.store', $this->invoice), ['amount' => '10.00', 'method' => 'cash', 'received_on' => '2026-10-14'])
            ->assertSessionHasErrors('method');
        expect(inCompany($this->company, fn () => Payment::query()->count()))->toBe(0);
    });

    test('only the office sees cash on hand; another company sees nothing', function () {
        $this->actingAs($this->tech)->get(route('cash.index'))->assertForbidden();

        $this->actingAs($this->owner)->post(route('payments.store', $this->invoice), ['amount' => '10.00', 'method' => 'cash', 'received_on' => '2026-10-14']);
        $movement = inCompany($this->company, fn () => CashMovement::query()->sole());

        app(CurrentCompany::class)->forget();
        $this->actingAs(memberOf(Company::factory()->create()));
        $this->post(route('cash.reverse', $movement), ['reason' => 'x'])->assertNotFound();
        $this->get(route('cash.index'))->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
    });
});

describe('reports', function () {
    beforeEach(function () {
        // Repaired by Tim: invoiced 280.50 (no tax), part cost 40.
        inCompany($this->company, fn () => $this->invoice->items()->first()->forceFill(['unit_cost' => 4000, 'cost_owner_id' => $this->owner->id, 'kind' => 'part'])->save());
        $visit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();
        $this->actingAs($this->tech)->post(route('visits.start', $visit));
        $this->post(route('visits.finish', $visit), ['outcome' => 'completed']);

        // A callback on it, and a no-charge job with a part.
        $this->actingAs($this->owner);
        $this->callback = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'visit_type' => 'callback', 'previous_job_id' => $this->job->id]);
        $this->free = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
        $this->post(route('jobs.costs.store', $this->free), ['kind' => 'part', 'description' => 'Fuse', 'quantity' => '1', 'unit_cost' => '12.00']);
        $this->post(route('jobs.close', $this->free), ['outcome' => 'no_charge', 'reason' => 'Goodwill']);
    });

    test('profit and margin by technician, callback rate, no-charge jobs', function () {
        $this->get(route('reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('totals.jobs', 2)
            ->where('totals.revenue', 28050)
            ->where('totals.cost', 4000 + 1200)
            ->where('byTechnician.0.name', 'Tim Tech')
            ->where('callbacks.total', ['jobs' => 2, 'callbacks' => 1, 'rate' => 50])
            ->where('callbacks.byBrand.0.name', 'Doctor Appliance')
            ->where('noCharge.count', 1)
            ->where('noCharge.loss', 1200));

        $this->actingAs($this->tech)->get(route('reports.index'))->assertForbidden();
    });

    test('expenses export as CSV and receipts as a ZIP', function () {
        $csv = $this->get(route('reports.expenses', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->streamedContent();
        expect($csv)->toContain('Total cost')->toContain('Fuse,,,1.00,,12.00')->toContain('Diagnosis');

        $this->post(route('jobs.receipts.store', $this->free), ['file' => UploadedFile::fake()->create('fuse.pdf', 20, 'application/pdf')]);
        $this->get(route('reports.receipts', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertDownload();
    });
});
