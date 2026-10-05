<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->customer = Customer::factory()->for($this->company)->create();
});

test('an address typed by hand gets a map position when a server key is set', function () {
    config(['services.google_maps.server_key' => 'server-key']);
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK',
        'results' => [['place_id' => 'abc', 'geometry' => ['location' => ['lat' => 49.1, 'lng' => -122.8]]]],
    ])]);

    $property = inCompany($this->company, fn () => Property::factory()->for($this->customer)->create([
        'latitude' => null, 'longitude' => null, 'line1' => '8450 128 St', 'city' => 'Surrey', 'country' => 'CA',
    ]));

    expect((float) $property->fresh()->latitude)->toBe(49.1)
        ->and((float) $property->fresh()->longitude)->toBe(-122.8);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'components=country%3ACA'));
});

test('without a server key nothing is sent to Google', function () {
    config(['services.google_maps.server_key' => null]);
    Http::fake();

    $property = inCompany($this->company, fn () => Property::factory()->for($this->customer)->create(['latitude' => null, 'longitude' => null]));

    expect($property->fresh()->latitude)->toBeNull();
    Http::assertNothingSent();
    $this->artisan('properties:geocode')->assertFailed();
});

test('a refused lookup leaves the address without a position', function () {
    config(['services.google_maps.server_key' => 'server-key']);
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED', 'results' => []])]);

    $property = inCompany($this->company, fn () => Property::factory()->for($this->customer)->create(['latitude' => null, 'longitude' => null]));

    expect($property->fresh()->latitude)->toBeNull();
});
