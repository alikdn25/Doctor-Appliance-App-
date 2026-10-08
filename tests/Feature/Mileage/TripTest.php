<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\Trip;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.google_maps.server_key' => 'server-key']);
    Http::fake([
        'maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
            'status' => 'OK', 'rows' => [['elements' => [['status' => 'OK', 'distance' => ['value' => 12345]]]]],
        ]),
        'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
    ]);
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver', 'mileage_rate' => 72]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    inCompany($this->company, fn () => Membership::query()->where('user_id', $this->tech->id)->update(['trip_start_address' => '1 Home St, Burnaby']));
    $customer = Customer::factory()->for($this->company)->create();
    $first = Property::factory()->for($customer)->create(['line1' => '10 First Ave', 'city' => 'Vancouver']);
    $second = Property::factory()->for($customer)->create(['line1' => '20 Second Ave', 'city' => 'Surrey']);
    $this->jobA = ServiceJob::factory()->for($first)->withVisit($this->tech)->create();
    $this->jobB = ServiceJob::factory()->for($second)->withVisit($this->tech)->create();
    $this->visitA = inCompany($this->company, fn () => $this->jobA->visits()->sole());
    $this->visitB = inCompany($this->company, fn () => $this->jobB->visits()->sole());
});

function tripsOf(Company $company)
{
    return inCompany($company, fn () => Trip::query()->orderBy('id')->get());
}

test('starting jobs logs the drive: from home to the first job, then from job to job', function () {
    $this->actingAs($this->tech)->post(route('visits.start', $this->visitA))->assertRedirect();
    $this->travel(2)->hours();
    $this->post(route('visits.finish', $this->visitA), ['outcome' => 'completed']);
    $this->post(route('visits.start', $this->visitB))->assertRedirect();
    // A repeated tap never adds a second trip.
    $this->post(route('visits.start', $this->visitB));

    $trips = tripsOf($this->company);
    $addressA = inCompany($this->company, fn () => $this->jobA->property->fullAddress());

    expect($trips)->toHaveCount(2)
        ->and($trips[0]->from_address)->toBe('1 Home St, Burnaby')
        ->and($trips[0]->to_address)->toBe($addressA)
        ->and($trips[0]->type)->toBe('client')
        ->and($trips[0]->service_job_id)->toBe($this->jobA->id)
        ->and($trips[0]->distance_km)->toBe('12.3')
        ->and($trips[1]->from_address)->toBe($addressA)
        ->and($trips[1]->user_id)->toBe($this->tech->id);
});

test('without a map key the trip is logged with the distance left to type', function () {
    config(['services.google_maps.server_key' => null]);

    $this->actingAs($this->tech)->post(route('visits.start', $this->visitA));

    expect(tripsOf($this->company)->sole()->distance_km)->toBeNull();
});

test('+ Trip adds a store run in one step; totals use the company rate', function () {
    $this->actingAs($this->tech)->post(route('trips.store'), [
        'trip_date' => now('America/Vancouver')->toDateString(), 'type' => 'parts_store',
        'from_address' => 'Shop', 'to_address' => 'Reliable Parts', 'distance' => '7.5', 'purpose' => 'Drain pump for #1001',
    ])->assertSessionHasNoErrors();

    $this->get(route('trips.index'))->assertInertia(fn (Assert $page) => $page
        ->component('trips/index')
        ->where('trips.0.type', 'parts_store')
        ->where('trips.0.distance', 7.5)
        ->where('totals.month', ['distance' => 7.5, 'amount' => 540])
        ->where('totals.year.distance', 7.5)
        ->where('unit', 'km'));

    $this->post(route('trips.store'), ['trip_date' => 'x', 'type' => 'boat'])->assertSessionHasErrors(['trip_date', 'type']);
});

test('a correction by hand is kept; a removed trip stays in the database', function () {
    $this->actingAs($this->tech)->post(route('visits.start', $this->visitA));
    $trip = tripsOf($this->company)->sole();

    $this->put(route('trips.update', $trip), [
        'trip_date' => $trip->trip_date->toDateString(), 'type' => 'client', 'from_address' => 'Shop',
        'to_address' => $trip->to_address, 'distance' => '15', 'purpose' => $trip->purpose,
    ])->assertSessionHasNoErrors();
    expect($trip->fresh())->distance_km->toBe('15.0')->distance_edited->toBeTrue();

    $this->delete(route('trips.destroy', $trip))->assertRedirect();
    expect(tripsOf($this->company))->toHaveCount(0)
        ->and(Trip::withoutCompanyScope()->withTrashed()->find($trip->id))->not->toBeNull();
});

test('miles: the company unit is used for input, display and the rate', function () {
    $this->company->update(['distance_unit' => 'mi', 'mileage_rate' => 70]);

    $this->actingAs($this->owner)->post(route('trips.store'), [
        'trip_date' => now('America/Vancouver')->toDateString(), 'type' => 'supplier', 'distance' => '10',
    ]);

    expect(tripsOf($this->company)->sole()->distance_km)->toBe('16.1');
    $this->get(route('trips.index'))->assertInertia(fn (Assert $page) => $page
        ->where('trips.0.distance', 10)
        ->where('totals.month', ['distance' => 10, 'amount' => 700]));
    expect(Company::factory()->create(['country' => 'US'])->distance_unit)->toBe('mi');
});

test('technicians see and change only their own trips; the office sees everyone', function () {
    $this->actingAs($this->tech)->post(route('visits.start', $this->visitA));
    $this->actingAs($this->owner)->post(route('trips.store'), ['trip_date' => now('America/Vancouver')->toDateString(), 'type' => 'other', 'distance' => '3']);
    $ownerTrip = tripsOf($this->company)->firstWhere('user_id', $this->owner->id);

    $this->actingAs($this->tech)->get(route('trips.index'))->assertInertia(fn (Assert $page) => $page->has('trips', 1)->where('people', []));
    $this->put(route('trips.update', $ownerTrip), ['trip_date' => now()->toDateString(), 'type' => 'other'])->assertForbidden();
    $this->actingAs($this->owner)->get(route('trips.index'))->assertInertia(fn (Assert $page) => $page->has('trips', 2));
    $this->get(route('trips.index', ['person' => $this->tech->id]))->assertInertia(fn (Assert $page) => $page->has('trips', 1));
});

test('the start address is kept per person and company', function () {
    $this->actingAs($this->owner)->put(route('trips.start-address'), ['address' => '5 Shop Rd'])->assertRedirect();

    expect(inCompany($this->company, fn () => Membership::query()->where('user_id', $this->owner->id)->value('trip_start_address')))->toBe('5 Shop Rd');
});

test('the CSV log lists date, route, purpose and distance for the year', function () {
    $this->actingAs($this->tech)->post(route('trips.store'), [
        'trip_date' => now('America/Vancouver')->toDateString(), 'type' => 'parts_store',
        'from_address' => 'Shop', 'to_address' => '=HYPERLINK("x")', 'distance' => '7.5', 'purpose' => 'Parts',
    ]);

    $csv = $this->get(route('trips.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('Date,From,To,Purpose,Type,"Distance (km)",Driver,Job')
        ->toContain('Shop,"\'=HYPERLINK(""x"")",Parts,"Parts store",7.5');
});

test('the mileage rate and unit are company settings', function () {
    $this->actingAs($this->owner)->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('company.mileage_rate', '0.72')
        ->where('company.distance_unit', 'km'));
});

test('another company cannot touch a trip', function () {
    $stranger = memberOf(Company::factory()->create(), UserRole::Owner);
    $this->actingAs($this->tech)->post(route('visits.start', $this->visitA));
    $trip = tripsOf($this->company)->sole();

    $this->actingAs($stranger)
        ->delete(route('trips.destroy', $trip))->assertNotFound();
});

test('+ Trip on My Jobs: only the store is typed; the start is the phone position', function () {
    // Replace the default fake of beforeEach with one that names the GPS point's street.
    Http::swap(new Factory);
    Http::fake([
        'maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
            'status' => 'OK', 'origin_addresses' => ['10 First Ave, Vancouver, BC'], 'destination_addresses' => ['4320 Dawson St, Burnaby, BC'],
            'rows' => [['elements' => [['status' => 'OK', 'distance' => ['value' => 8400]]]]],
        ]),
        'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
    ]);

    $this->actingAs($this->tech)->post(route('trips.quick'), [
        'to_address' => 'Reliable Parts, Dawson Street, Burnaby', 'to_point' => '49.25,-123.0', 'latitude' => 49.26, 'longitude' => -123.11,
    ])->assertSessionHasNoErrors();

    $trip = tripsOf($this->company)->sole();
    expect($trip)
        ->type->toBe('parts_store')
        ->from_address->toBe('10 First Ave, Vancouver, BC')
        ->to_address->toBe('Reliable Parts, Dawson Street, Burnaby')
        ->distance_km->toBe('8.4');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'origins=49.26%2C-123.11') && str_contains($request->url(), 'destinations=49.25%2C-123.0'));

    // The next job is counted from the store, not from home.
    $this->post(route('visits.start', $this->visitA));
    expect(tripsOf($this->company)->last()->from_address)->toBe('Reliable Parts, Dawson Street, Burnaby');
});

test('+ Trip without GPS, or pressed on arrival, starts from the last point of the day', function () {
    $this->actingAs($this->tech)->post(route('trips.quick'), ['to_address' => 'Supplier Inc'])->assertSessionHasNoErrors();
    expect(tripsOf($this->company)->sole()->from_address)->toBe('1 Home St, Burnaby');

    // Pressed already at the store: Google finds 0.1 km from the phone, so the last point is used instead.
    Http::swap(new Factory);
    Http::fake(['maps.googleapis.com/*' => Http::sequence()
        ->push(['status' => 'OK', 'origin_addresses' => ['Store'], 'rows' => [['elements' => [['status' => 'OK', 'distance' => ['value' => 100]]]]]])
        ->push(['status' => 'OK', 'origin_addresses' => ['x'], 'rows' => [['elements' => [['status' => 'OK', 'distance' => ['value' => 5000]]]]]])]);
    $this->post(route('trips.quick'), ['to_address' => 'Parts Depot', 'latitude' => 49.2, 'longitude' => -123.1]);

    expect(tripsOf($this->company)->last())
        ->from_address->toBe('Supplier Inc')
        ->distance_km->toBe('5.0');

    $this->post(route('trips.quick'), ['to_address' => ''])->assertSessionHasErrors('to_address');
});
