<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Customer card (owner's update, October 24): the main block, and the Client / Work / Notes / Files tabs.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->company = Company::factory()->create(['country' => 'CA']);
    $this->owner = memberOf($this->company);
    $this->customer = Customer::factory()->for($this->company)->create([
        'first_name' => 'Rita', 'notes' => 'Calls back in the evening', 'billing_line1' => '1 Office Tower', 'billing_city' => 'Vancouver', 'billing_country' => 'CA',
    ]);
    $this->property = Property::factory()->for($this->customer)->create([
        'line1' => '500 Rental Ave', 'is_primary' => true, 'site_contact_name' => 'Tina Tenant', 'site_contact_phone' => '+16045550177',
    ]);
    $this->job = ServiceJob::factory()->for($this->property)->create(['notes' => 'Dog in the yard']);
    $this->actingAs($this->owner);
});

test('the card brings the billing address, occupants, job notes and the job photos', function () {
    $this->post(route('jobs.photos.store', $this->job), [
        'photo' => UploadedFile::fake()->image('plate.jpg', 800, 600), 'kind' => 'before', 'client_uuid' => (string) Str::uuid(),
    ], ['Accept' => 'application/json'])->assertSuccessful();

    $this->get(route('customers.show', $this->customer))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('customers/show')
        ->where('customer.billing.line1', '1 Office Tower')
        ->where('customer.billing_address', fn (string $text) => str_contains($text, '1 Office Tower'))
        ->where('customer.properties.0.site_contact_name', 'Tina Tenant')
        ->where('jobNotes.0.notes', 'Dog in the yard')
        ->has('files', 1)
        ->where('files.0.job_id', $this->job->id)
        ->where('files.0.job_number', $this->job->number));
});

test('without its own billing address the card shows the primary address', function () {
    inCompany($this->company, fn () => $this->customer->update(['billing_line1' => null, 'billing_city' => null]));

    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('customer.billing', null)
        ->where('customer.billing_address', fn (string $text) => str_contains($text, '500 Rental Ave')));
});

test('notes are edited on the Notes tab by people who can edit the customer', function () {
    $this->patch(route('customers.notes', $this->customer), ['notes' => '  Prefers texts  '])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $this->customer->fresh()->notes))->toBe('Prefers texts');

    $tech = memberOf($this->company, UserRole::Technician);
    $this->actingAs($tech)->patch(route('customers.notes', $this->customer), ['notes' => 'x'])->assertForbidden();
});

test('another company sees neither the card nor can edit its notes', function () {
    $stranger = memberOf(Company::factory()->create());

    $this->actingAs($stranger)->get(route('customers.show', $this->customer))->assertNotFound();
    $this->actingAs($stranger)->patch(route('customers.notes', $this->customer), ['notes' => 'hacked'])->assertNotFound();
    expect(inCompany($this->company, fn () => $this->customer->fresh()->notes))->toBe('Calls back in the evening');
});
