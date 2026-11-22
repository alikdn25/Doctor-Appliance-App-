<?php

use App\Models\Brand;
use App\Models\Company;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;

/*
 * "Now" books for today at any hour and the "Booked #… · Today" sheet is not lost (owner's task of November 22):
 * a background JSON request between the booking and the page it leads to (the nearby windows lookup) used to use up
 * the one-time flash.
 */

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'UTC', 'country' => 'CA']);
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
});

function bookNow(string $at): array
{
    $now = CarbonImmutable::parse($at, 'UTC');
    test()->travelTo($now);

    test()->post(route('jobs.store'), [
        'quick_booking' => true,
        'brand_id' => test()->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Now Caller', 'phone' => '+16045550'.random_int(100, 999), 'property' => ['line1' => '16 Now Avenue', 'city' => 'Vancouver', 'country' => 'CA']],
        'add_visit' => true,
        'visit' => ['date' => $now->toDateString(), 'start_time' => $now->format('H:i'), 'end_time' => $now->addHour()->format('H:i'), 'assignee_ids' => []],
    ])->assertSessionHasNoErrors();

    return (array) session('inertia.flash_data.booking_confirmation');
}

/** The flash the calendar page is loaded with. */
function calendarFlash(): array
{
    return (array) (test()->get(route('calendar', ['view' => 'day', 'date' => '2030-01-15']))->assertOk()->viewData('page')['flash'] ?? []);
}

test('"Now" is booked for today at any hour of the day', function (string $at) {
    $booked = bookNow($at);
    $job = inCompany($this->company, fn () => ServiceJob::query()->latest('id')->first());

    expect($booked['message'])->toContain("Booked #{$job->number}")->toContain('Today');
})->with(['00:05' => '2030-01-15 00:05', '06:30' => '2030-01-15 06:30', '12:00' => '2030-01-15 12:00', '18:45' => '2030-01-15 18:45', '22:30' => '2030-01-15 22:30']);

test('a background JSON request between the booking and the next page keeps the "Booked" message', function () {
    bookNow('2030-01-15 01:25');

    // The nearby windows lookup (or any other background request) slips in before the calendar loads.
    $this->getJson(route('booking.nearby', ['address' => '16 Now Avenue, Vancouver']))->assertOk();

    expect(calendarFlash())->toHaveKey('booking_confirmation');
});

test('without background requests the message is shown once, then gone', function () {
    bookNow('2030-01-15 12:00');

    expect(calendarFlash())->toHaveKey('booking_confirmation')
        ->and(calendarFlash())->not->toHaveKey('booking_confirmation');
});
