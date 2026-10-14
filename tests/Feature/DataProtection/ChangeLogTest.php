<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Owner']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Maria', 'last_name' => 'Lopez']);
});

test('creating, changing and deleting a customer is logged with who, the old and the new value', function () {
    $this->actingAs($this->owner)->put(route('customers.update', $this->customer), [
        'type' => 'residential',
        'first_name' => 'Marie',
        'last_name' => 'Lopez',
        'phones' => [['label' => 'mobile', 'number' => '604-555-0101', 'is_primary' => true]],
        'emails' => [],
    ])->assertRedirect();

    $log = AuditLog::query()->where('action', 'customer.updated')->sole();
    expect($log->user_id)->toBe($this->owner->id)
        ->and($log->company_id)->toBe($this->company->id)
        ->and($log->source)->toBe('web')
        ->and($log->changes['old']['first_name'])->toBe('Maria')
        ->and($log->changes['new']['first_name'])->toBe('Marie');

    expect(AuditLog::query()->where('action', 'customer.created')->where('auditable_id', $this->customer->id)->exists())->toBeTrue();

    $this->delete(route('customers.destroy', $this->customer))->assertRedirect();
    expect(AuditLog::query()->where('action', 'customer.deleted')->where('user_id', $this->owner->id)->exists())->toBeTrue();
});

test('jobs, estimates, invoices and payments are logged too', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $property = Property::factory()->for($this->customer)->create();
    $job = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id]);

    inCompany($this->company, function () use ($job) {
        $this->actingAs($this->owner);
        $save = app(SaveBillingDocument::class);
        $save->createEstimate($job, documentPayload(), $this->owner);
        $invoice = $save->createInvoice($job, documentPayload(), $this->owner);
        app(RecordPayment::class)->manual($invoice, 50, PaymentMethod::Cash, null, null, now(), $this->owner);
    });

    foreach (['job.created', 'estimate.created', 'invoice.created', 'payment.created'] as $action) {
        expect(AuditLog::query()->where('action', $action)->exists())->toBeTrue($action);
    }
});

test('the Owner reads a record\'s history; others cannot; another company\'s record is not found', function () {
    $this->customer->update(['notes' => 'Gate code 1234']);
    $other = Customer::factory()->for(Company::factory())->create();

    $this->actingAs($this->owner)->getJson(route('history.show', ['type' => 'customer', 'id' => $this->customer->id]))
        ->assertOk()
        ->assertJsonPath('entries.0.event', 'updated')
        ->assertJsonPath('entries.0.fields.0.field', 'Notes')
        ->assertJsonPath('entries.0.fields.0.new', 'Gate code 1234');

    $admin = memberOf($this->company, UserRole::Admin);
    $this->actingAs($admin)->getJson(route('history.show', ['type' => 'customer', 'id' => $this->customer->id]))->assertForbidden();
    $tech = memberOf($this->company, UserRole::Technician);
    $this->actingAs($tech)->getJson(route('history.show', ['type' => 'customer', 'id' => $this->customer->id]))->assertForbidden();

    $this->actingAs($this->owner)->getJson(route('history.show', ['type' => 'customer', 'id' => $other->id]))->assertNotFound();
});

test('the change log page shows only the company\'s own entries, for the Owner only', function () {
    $otherCompany = Company::factory()->create();
    Customer::factory()->for($otherCompany)->create(['first_name' => 'Secret']);

    $this->actingAs($this->owner)->get(route('company.change-log'))->assertInertia(fn (Assert $page) => $page
        ->component('company/change-log')
        ->where('entries.data', fn ($entries) => collect($entries)->every(fn ($e) => ! str_contains((string) $e['record'], 'Secret')))
        ->where('entries.data.0.type', 'customer'));

    $this->actingAs($this->owner)->get(route('company.change-log', ['type' => 'job']))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));

    $admin = memberOf($this->company, UserRole::Admin);
    $this->actingAs($admin)->get(route('company.change-log'))->assertForbidden();
});
