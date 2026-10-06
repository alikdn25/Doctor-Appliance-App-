<?php

use App\Enums\ApplianceType;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\NameAvatar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Owner feedback of October 5: editing a booked job changes the customer's name and phone,
 * appliances are picked from image tiles (wine cooler included), Ukrainian names get a face,
 * and the customer signs on the invoice instead of the job page.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('+16045550101')
        ->create(['first_name' => 'Olexsandr', 'last_name' => 'Mykhailychenko']);
    $this->property = Property::factory()->for($this->customer)->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);

    $this->actingAs($this->owner);
});

function editPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'brand_id' => test()->brand->id,
        'job_type' => 'repair',
        'property_id' => test()->property->id,
        'appliance_ids' => [],
        'customer_edit' => [
            'first_name' => 'Oleksandr',
            'last_name' => 'Mykhailychenko',
            'company_name' => '',
            'phone' => '(778) 318-6846',
        ],
    ], $overrides);
}

test('the job edit form carries the customer name parts for correction', function () {
    $this->get(route('jobs.edit', $this->job))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customer.first_name', 'Olexsandr')
            ->where('customer.last_name', 'Mykhailychenko')
            ->where('customer.phone', '+16045550101'));
});

test('editing a job corrects the customer name and main phone', function () {
    $this->put(route('jobs.update', $this->job), editPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('jobs.show', $this->job));

    $customer = inCompany($this->company, fn () => $this->customer->fresh()->load('phones'));

    expect($customer->display_name)->toBe('Oleksandr Mykhailychenko')
        ->and($customer->phones)->toHaveCount(1)
        ->and($customer->phones->first()->number)->toBe('+17783186846')
        ->and($customer->phones->first()->is_primary)->toBeTrue();
});

test('a customer without a phone gets one from the job form', function () {
    inCompany($this->company, fn () => $this->customer->phones()->delete());

    $this->put(route('jobs.update', $this->job), editPayload())->assertSessionHasNoErrors();

    $phone = inCompany($this->company, fn () => $this->customer->primaryPhone()->first());
    expect($phone->number)->toBe('+17783186846');
});

test('the customer correction needs a name and a valid phone, and is optional', function () {
    $this->put(route('jobs.update', $this->job), editPayload(['customer_edit' => ['first_name' => '', 'last_name' => '']]))
        ->assertSessionHasErrors('customer_edit.first_name');
    $this->put(route('jobs.update', $this->job), editPayload(['customer_edit' => ['phone' => 'abc']]))
        ->assertSessionHasErrors('customer_edit.phone');

    $payload = editPayload();
    unset($payload['customer_edit']);
    $this->put(route('jobs.update', $this->job), $payload)->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => $this->customer->fresh()->first_name))->toBe('Olexsandr');
});

test('only the job own customer is corrected and a technician cannot edit the job', function () {
    $other = Customer::factory()->for($this->company)->withPhone('+16045550199')->create(['first_name' => 'Other']);

    $this->put(route('jobs.update', $this->job), editPayload(['customer_id' => $other->id]))->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => $other->fresh()->first_name))->toBe('Other')
        ->and(inCompany($this->company, fn () => $this->customer->fresh()->first_name))->toBe('Oleksandr');

    $this->actingAs($this->tech)->put(route('jobs.update', $this->job), editPayload(['customer_edit' => ['first_name' => 'Hacked']]))
        ->assertForbidden();
    expect(inCompany($this->company, fn () => $this->customer->fresh()->first_name))->toBe('Oleksandr');
});

test('another company cannot correct this customer through its own job', function () {
    $foreign = Company::factory()->create();
    $foreignOwner = memberOf($foreign, UserRole::Owner);

    $this->actingAs($foreignOwner)->put(route('jobs.update', $this->job), editPayload(['customer_edit' => ['first_name' => 'Foreign']]))
        ->assertNotFound();

    expect(inCompany($this->company, fn () => $this->customer->fresh()->first_name))->toBe('Olexsandr');
});

test('appliance types follow the picker order and include a wine cooler', function () {
    expect(array_column(ApplianceType::options(), 'value'))->toBe([
        'refrigerator', 'freezer', 'wine_cooler', 'washer', 'dryer', 'washer_dryer_combo',
        'dishwasher', 'range', 'oven', 'cooktop', 'microwave', 'range_hood', 'other',
    ]);

    foreach (ApplianceType::cases() as $type) {
        expect(file_exists(public_path("images/appliances/{$type->value}.png")))->toBeTrue();
    }
});

test('booking with an appliance tile adds it at the new address', function () {
    $this->post(route('jobs.store'), [
        'quick_booking' => true,
        'brand_id' => $this->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Anna', 'phone' => '+16045550177', 'property' => ['country' => 'CA']],
        'new_appliances' => [['type' => 'wine_cooler']],
    ])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::with('appliances')->latest('id')->first());
    expect($job->appliances->pluck('type')->all())->toBe([ApplianceType::WineCooler]);
});

test('booking an existing customer links the appliance already at the address', function () {
    $dryer = Appliance::factory()->for($this->property)->create(['type' => 'dryer']);

    $this->post(route('jobs.store'), [
        'quick_booking' => true,
        'brand_id' => $this->brand->id,
        'job_type' => 'repair',
        'customer_id' => $this->customer->id,
        'property_id' => $this->property->id,
        'appliance_ids' => [$dryer->id],
    ])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::with('appliances')->latest('id')->first());
    expect($job->appliances->pluck('id')->all())->toBe([$dryer->id])
        ->and(inCompany($this->company, fn () => Appliance::count()))->toBe(1);
});

test('common Latin spellings of Ukrainian and Russian names get a face', function () {
    expect(NameAvatar::suggest('Oleksandr'))->toBe('man')
        ->and(NameAvatar::suggest('Dmytro'))->toBe('man')
        ->and(NameAvatar::suggest('Olena'))->toBe('woman')
        ->and(NameAvatar::suggest('Iryna'))->toBe('woman')
        ->and(NameAvatar::suggest('Maria'))->toBe('woman');
});

test('the invoice page offers the customer signature and the job page only shows a taken one', function () {
    Storage::fake('local');
    $this->post(route('invoices.store', $this->job), documentPayload())->assertRedirect();
    $invoice = inCompany($this->company, fn () => Invoice::latest('id')->firstOrFail());

    $this->get(route('invoices.show', $invoice))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('signature.job_id', $this->job->id)
            ->where('signature.data', null)
            ->where('signature.can_sign', true));

    $this->actingAs($this->tech)->get(route('invoices.show', $invoice))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('signature.can_sign', true));

    $this->actingAs($this->tech)->post(route('jobs.signature.store', $this->job), [
        'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
        'signer_name' => 'Oleksandr',
    ])->assertCreated();

    $this->actingAs($this->owner)->get(route('invoices.show', $invoice))
        ->assertInertia(fn (Assert $page) => $page->where('signature.data.name', 'Oleksandr'));
    $this->get(route('jobs.show', $this->job))
        ->assertInertia(fn (Assert $page) => $page->where('job.signature.name', 'Oleksandr'));
});

test('editing a job adds the address missing from a quick booking', function () {
    inCompany($this->company, fn () => $this->property->forceFill(['line1' => '', 'city' => '', 'postal_code' => null])->save());

    $this->put(route('jobs.update', $this->job), editPayload(['address_edit' => [
        'line1' => '295 Guildford Way', 'unit' => '1204', 'city' => 'Port Moody', 'region' => 'BC',
        'postal_code' => 'V3H 0A1', 'country' => 'ca', 'google_place_id' => '', 'latitude' => '', 'longitude' => '',
    ]]))->assertSessionHasNoErrors();

    $property = inCompany($this->company, fn () => $this->property->fresh());
    expect($property->line1)->toBe('295 Guildford Way')
        ->and($property->unit)->toBe('1204')
        ->and($property->city)->toBe('Port Moody')
        ->and($property->country)->toBe('CA');

    $this->put(route('jobs.update', $this->job), editPayload(['address_edit' => [
        'line1' => 'Only street', 'city' => '', 'country' => 'CA',
    ]]))->assertSessionHasErrors('address_edit.city');
});

test('the job edit form carries the address parts of each place', function () {
    $this->get(route('jobs.edit', $this->job))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customer.properties.0.address.line1', $this->property->line1)
            ->where('customer.properties.0.address.city', $this->property->city));
});

test('a full name typed in the quick booking is split and gets a face', function () {
    $this->post(route('jobs.store'), [
        'quick_booking' => true,
        'brand_id' => $this->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Olena  Shevchenko', 'phone' => '+16045550178', 'property' => ['country' => 'CA']],
    ])->assertSessionHasNoErrors();

    $customer = inCompany($this->company, fn () => Customer::latest('id')->first());
    expect($customer->first_name)->toBe('Olena')
        ->and($customer->last_name)->toBe('Shevchenko')
        ->and($customer->avatarIcon())->toBe('woman')
        ->and(NameAvatar::suggest('Oleksandr Mykhailychenko'))->toBe('man');
});

test('my jobs shows tab counts, the problem and the appliance picture', function () {
    $microwave = Appliance::factory()->for($this->property)->create(['type' => 'microwave']);
    inCompany($this->company, function () use ($microwave) {
        $this->job->appliances()->attach($microwave->id);
        $this->job->forceFill(['description' => 'Not heating'])->save();
        $this->job->visits()->first()->forceFill([
            // Noon to 2 p.m. of today in the company's time zone (UTC may already be on the next day).
            'scheduled_start' => now($this->company->timezone)->setTime(12, 0)->utc(),
            'scheduled_end' => now($this->company->timezone)->setTime(14, 0)->utc(),
        ])->save();
    });

    $this->actingAs($this->tech)->get(route('jobs.mine'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.today', 1)
            ->where('counts.upcoming', 0)
            ->where('visits.0.job.picture', 'microwave')
            ->where('visits.0.job.problem', 'Not heating')
            ->where('visits.0.job.appliance_types', ['Microwave']));
});
