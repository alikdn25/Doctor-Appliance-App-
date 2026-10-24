<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\Trip;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * UX-REDESIGN stage 4 (technician): Work done autosave, "Waiting for parts" opening the Finish screen with
 * "Part needed", the Trip sheet's recent stops and "Enter by hand" from My Jobs.
 */

beforeEach(function () {
    config(['services.google_maps.server_key' => null]);
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver']);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $customer = Customer::factory()->for($this->company)->create();
    $property = Property::factory()->for($customer)->create(['line1' => '10 First Ave', 'city' => 'Vancouver']);
    $this->job = ServiceJob::factory()->for($property)->withVisit($this->tech)->create();
    $this->visit = inCompany($this->company, fn () => $this->job->visits()->sole());
});

function tripFor(Company $company, User $user, string $to, string $type = 'parts_store', ?int $visitId = null, string $date = '2026-10-01'): void
{
    inCompany($company, fn () => Trip::create([
        'user_id' => $user->id, 'trip_date' => $date, 'type' => $type, 'to_address' => $to,
        'job_visit_id' => $visitId,
    ]));
}

test('Work done saves in the background without a page reload or a toast', function () {
    $this->actingAs($this->tech)
        ->putJson(route('jobs.tech-notes', $this->job), ['tech_notes' => 'Drain filter clogged with a sock.'])
        ->assertOk()
        ->assertJson(['saved' => true])
        ->assertSessionMissing('inertia.flash_data');

    expect($this->job->fresh()->tech_notes)->toBe('Drain filter clogged with a sock.');

    // The ordinary form post still goes back to the page.
    $this->put(route('jobs.tech-notes', $this->job), ['tech_notes' => 'Cleaned filter.'])->assertRedirect();
    expect($this->job->fresh()->tech_notes)->toBe('Cleaned filter.');
});

test('autosave keeps the rules: someone else\'s job or another company\'s job is refused', function () {
    $otherTech = memberOf($this->company, UserRole::Technician);
    $outsider = memberOf(Company::factory()->create(), UserRole::Owner);
    $this->actingAs($otherTech)
        ->putJson(route('jobs.tech-notes', $this->job), ['tech_notes' => 'Hack'])
        ->assertForbidden();

    $response = $this->actingAs($outsider)->putJson(route('jobs.tech-notes', $this->job), ['tech_notes' => 'Hack']);

    expect($response->status())->toBeIn([403, 404])
        ->and($this->job->fresh()->tech_notes)->toBeNull();

    $this->actingAs($this->tech)
        ->putJson(route('jobs.tech-notes', $this->job), ['tech_notes' => str_repeat('a', 5001)])
        ->assertUnprocessable();
});

test('Waiting for parts opens the Finish screen with "Part needed" chosen', function () {
    $this->actingAs($this->tech)->post(route('visits.start', $this->visit))->assertSessionHasNoErrors();

    $this->get(route('visits.finish-screen', ['visit' => $this->visit, 'result' => 'parts']))
        ->assertInertia(fn (Assert $page) => $page->component('jobs/finish')->where('result', 'parts'));

    $this->get(route('visits.finish-screen', ['visit' => $this->visit, 'result' => 'nonsense']))
        ->assertInertia(fn (Assert $page) => $page->where('result', 'completed'));
});

test('the Trip sheet offers the person\'s last five stores and suppliers, newest first, without repeats', function () {
    foreach (['Store A', 'Store B', 'Supplier C', 'Store D', 'Store E', 'store b', 'Store F'] as $day => $to) {
        tripFor($this->company, $this->tech, $to, str_starts_with($to, 'Supplier') ? 'supplier' : 'parts_store', date: '2026-10-0'.($day + 1));
    }
    // Not stores: a drive to a customer from the route, a trip of someone else.
    tripFor($this->company, $this->tech, '10 First Ave', 'client', $this->visit->id, '2026-10-09');
    tripFor($this->company, memberOf($this->company, UserRole::Technician), 'Their Store', date: '2026-10-09');

    // The Trip sheet (with turn-by-turn directions) is on Mileage; the "+" opens the form by hand.
    $this->actingAs($this->tech)->get(route('trips.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('trips/index')
            ->where('recentStops', ['Store F', 'store b', 'Store E', 'Store D', 'Supplier C'])
            ->where('openManual', false));
});

test('recent stops never come from another company', function () {
    $other = Company::factory()->create();
    $otherTech = memberOf($other, UserRole::Technician);
    tripFor($other, $otherTech, 'Foreign Store');
    tripFor($this->company, $this->tech, 'Own Store');

    $this->actingAs($this->tech)->get(route('trips.index'))
        ->assertInertia(fn (Assert $page) => $page->where('recentStops', ['Own Store']));
});

test('"Enter by hand" on My Jobs opens Mileage with the trip form', function () {
    $this->actingAs($this->tech)->get(route('trips.index', ['manual' => 1]))
        ->assertInertia(fn (Assert $page) => $page->component('trips/index')->where('openManual', true));
});
