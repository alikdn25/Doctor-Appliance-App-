<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Maria']);
    $this->property = Property::factory()->for($this->customer)->create();
    $this->appliance = Appliance::factory()->for($this->property)->create();
});

test('the database has no cascading deletes left except the people of a visit time', function () {
    $cascades = collect(DB::select(
        "select conrelid::regclass::text as tbl, (select string_agg(attname, ',') from pg_attribute where attrelid = conrelid and attnum = any(conkey)) as col
         from pg_constraint where contype = 'f' and confdeltype = 'c' and connamespace = current_schema()::regnamespace",
    ))->map(fn ($row) => "{$row->tbl}.{$row->col}")->all();

    expect($cascades)->toBe(['job_visit_user.job_visit_id']);
});

test('hard-deleting a customer with jobs and invoices is refused by the database', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $brand->id]);
    $invoice = inCompany($this->company, fn () => app(SaveBillingDocument::class)->createInvoice($job, documentPayload(), $this->owner));

    // In its own savepoint, so the refused statement does not end the test's transaction.
    expect(fn () => DB::transaction(fn () => DB::table('customers')->where('id', $this->customer->id)->delete()))->toThrow(QueryException::class);

    expect(ServiceJob::withoutCompanyScope()->find($job->id))->not->toBeNull()
        ->and(Invoice::withoutCompanyScope()->find($invoice->id))->not->toBeNull();
});

test('deleting a customer keeps its jobs and invoices; the app refuses while jobs exist', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $brand->id]);

    $this->actingAs($this->owner)->delete(route('customers.destroy', $this->customer))
        ->assertRedirect(route('customers.show', $this->customer));

    expect(Customer::withoutCompanyScope()->find($this->customer->id))->not->toBeNull()
        ->and(ServiceJob::withoutCompanyScope()->find($job->id))->not->toBeNull();
});

test('a deleted customer comes back from Deleted items with its addresses and appliances', function () {
    $other = Property::factory()->for($this->customer)->create(['line1' => '2 Second St']);

    $this->actingAs($this->owner)->delete(route('customers.destroy', $this->customer))->assertRedirect();

    expect(Customer::withoutCompanyScope()->onlyTrashed()->find($this->customer->id))->not->toBeNull()
        ->and(Property::withoutCompanyScope()->onlyTrashed()->count())->toBe(2)
        ->and(Appliance::withoutCompanyScope()->onlyTrashed()->count())->toBe(1);

    // The addresses and appliance deleted with the customer are not listed separately.
    $this->get(route('trash.index'))->assertInertia(fn (Assert $page) => $page
        ->component('trash/index')
        ->has('items', 1)
        ->where('items.0.type', 'customer')
        ->where('items.0.label', 'Maria '.$this->customer->last_name));

    $this->post(route('trash.restore', ['type' => 'customer', 'id' => $this->customer->id]))->assertRedirect(route('trash.index'));

    expect(Customer::withoutCompanyScope()->find($this->customer->id))->not->toBeNull()
        ->and(Property::withoutCompanyScope()->whereKey([$this->property->id, $other->id])->count())->toBe(2)
        ->and(Appliance::withoutCompanyScope()->find($this->appliance->id))->not->toBeNull();
});

test('an address deleted earlier stays deleted when its customer is restored', function () {
    $old = Property::factory()->for($this->customer)->create(['line1' => '9 Old Rd']);
    $this->actingAs($this->owner)->delete(route('properties.destroy', $old))->assertRedirect();
    $this->travel(5)->minutes();
    $this->delete(route('customers.destroy', $this->customer))->assertRedirect();

    $this->post(route('trash.restore', ['type' => 'customer', 'id' => $this->customer->id]))->assertRedirect();

    expect(Property::withoutCompanyScope()->find($this->property->id))->not->toBeNull()
        ->and(Property::withoutCompanyScope()->find($old->id))->toBeNull()
        ->and(Property::withoutCompanyScope()->onlyTrashed()->find($old->id))->not->toBeNull();
});

test('restoring an appliance also restores its address and customer', function () {
    $this->actingAs($this->owner)->delete(route('customers.destroy', $this->customer))->assertRedirect();

    $this->post(route('trash.restore', ['type' => 'appliance', 'id' => $this->appliance->id]))->assertRedirect();

    expect(Customer::withoutCompanyScope()->find($this->customer->id))->not->toBeNull()
        ->and(Property::withoutCompanyScope()->find($this->property->id))->not->toBeNull()
        ->and(Appliance::withoutCompanyScope()->find($this->appliance->id))->not->toBeNull();
});

test('payments and job costs are soft-deleted, never removed', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $brand->id]);

    inCompany($this->company, function () use ($job) {
        $invoice = app(SaveBillingDocument::class)->createInvoice($job, documentPayload(), $this->owner);
        $payment = app(RecordPayment::class)->manual($invoice, 50, PaymentMethod::Cash, null, null, now(), $this->owner);
        $payment->delete();

        expect(Payment::query()->find($payment->id))->toBeNull()
            ->and(Payment::withTrashed()->find($payment->id))->not->toBeNull();
    });
});

test('deleted items are the company\'s own and only the office sees them', function () {
    $otherCompany = Company::factory()->create();
    $otherCustomer = Customer::factory()->for($otherCompany)->create(['first_name' => 'Secret']);
    $otherCustomer->delete();
    $this->customer->delete();

    $this->actingAs($this->owner)->get(route('trash.index'))->assertInertia(fn (Assert $page) => $page
        ->has('items', 1)
        ->where('items.0.id', $this->customer->id));

    $this->post(route('trash.restore', ['type' => 'customer', 'id' => $otherCustomer->id]))->assertNotFound();
    expect(Customer::withoutCompanyScope()->onlyTrashed()->find($otherCustomer->id))->not->toBeNull();

    $admin = memberOf($this->company, UserRole::Admin);
    $this->actingAs($admin)->get(route('trash.index'))->assertOk();

    $tech = memberOf($this->company, UserRole::Technician);
    $this->actingAs($tech)->get(route('trash.index'))->assertForbidden();
    $this->actingAs($tech)->post(route('trash.restore', ['type' => 'customer', 'id' => $this->customer->id]))->assertForbidden();
});

test('items deleted more than 30 days ago leave the list but stay in the database', function () {
    $this->customer->delete();
    $this->travel(31)->days();

    $this->actingAs($this->owner)->get(route('trash.index'))->assertInertia(fn (Assert $page) => $page->has('items', 0));
    expect(Customer::withoutCompanyScope()->onlyTrashed()->find($this->customer->id))->not->toBeNull();
});
