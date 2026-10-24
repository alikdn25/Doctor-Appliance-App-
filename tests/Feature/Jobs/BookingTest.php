<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * UX-REDESIGN stage 5: Book customer (§7.1) and arrival windows (§25).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2030-01-15 16:00', 'UTC')); // 10:00 in Regina
    $this->company = Company::factory()->create(['timezone' => 'America/Regina', 'country' => 'CA']);
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tom Tech']);
    $this->admin = memberOf($this->company, UserRole::Admin, ['name' => 'Ada Admin']);
    $this->brand = Brand::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
});

function bookingMembership(User $user, Company $company): Membership
{
    return Membership::withoutCompanyScope()->where('user_id', $user->id)->where('company_id', $company->id)->sole();
}

function bookingPhone(Company $company, Customer $customer, string $number): void
{
    inCompany($company, function () use ($customer, $number) {
        $customer->phones()->delete();
        $customer->phones()->create(['number' => $number, 'label' => 'mobile', 'is_primary' => true]);
    });
}

test('owners and technicians go on calls by default; the Team screen can change it', function () {
    expect(bookingMembership($this->owner, $this->company)->shows_in_calendar)->toBeTrue()
        ->and(bookingMembership($this->tech, $this->company)->shows_in_calendar)->toBeTrue()
        ->and(bookingMembership($this->admin, $this->company)->shows_in_calendar)->toBeFalse();

    $admin = bookingMembership($this->admin, $this->company);
    $this->put(route('team.update', $admin), ['role' => 'admin', 'is_active' => true, 'shows_in_calendar' => true])
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->shows_in_calendar)->toBeTrue();
});

test('the booking form offers the company\'s windows and only the people who go on calls, with their busy times', function () {
    $this->company->update(['arrival_windows' => [['start' => '10:00', 'end' => '12:00'], ['start' => '08:00', 'end' => '10:00']]]);
    $customer = Customer::factory()->for($this->company)->create();
    $property = Property::factory()->for($customer)->create();
    $job = ServiceJob::factory()->for($property)->withVisit($this->tech)->create();
    inCompany($this->company, fn () => $job->visits()->sole()->update([
        'scheduled_start' => '2030-01-15 19:00:00', 'scheduled_end' => '2030-01-15 21:00:00', // 1–3 p.m. in Regina
    ]));

    // Another company's people and visits never show.
    $other = Company::factory()->create();
    memberOf($other, UserRole::Technician, ['name' => 'Foreign Tech']);

    $this->get(route('jobs.create', ['book' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/quick-book')
            ->where('arrivalWindows', [['start' => '08:00', 'end' => '10:00'], ['start' => '10:00', 'end' => '12:00']])
            ->where('nowTime', '10:00')
            ->where('bookingTechnicians', fn ($people) => collect($people)->pluck('name')->sort()->values()->all() === collect([$this->owner->name, 'Tom Tech'])->sort()->values()->all())
            ->where("busy.{$this->tech->id}", [['date' => '2030-01-15', 'start' => '13:00', 'end' => '15:00']]));
});

test('arrival windows are saved in Company settings, sorted, and the default list stays "not set"', function () {
    $settings = fn (array $windows) => $this->put(route('company.settings.update'), [
        ...$this->get(route('company.settings.edit'))->viewData('page')['props']['company'],
        'arrival_windows' => $windows,
    ]);

    $settings([['start' => '12:00', 'end' => '14:00'], ['start' => '09:00', 'end' => '11:00'], ['start' => '10:00', 'end' => '12:00']])
        ->assertSessionHasNoErrors();
    expect($this->company->fresh()->arrivalWindows())->toBe([
        ['start' => '09:00', 'end' => '11:00'], ['start' => '10:00', 'end' => '12:00'], ['start' => '12:00', 'end' => '14:00'],
    ]);

    $settings([['start' => '11:00', 'end' => '09:00']])->assertSessionHasErrors('arrival_windows.0.end');

    $settings(Company::defaultArrivalWindows())->assertSessionHasNoErrors();
    expect($this->company->fresh()->arrival_windows)->toBeNull();
});

test('typing the phone finds the caller by number only, with how many jobs they had', function () {
    $jane = Customer::factory()->for($this->company)->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    bookingPhone($this->company, $jane, '+16045550143');
    $property = Property::factory()->for($jane)->create(['line1' => '604 Main St']);
    ServiceJob::factory()->for($property)->create();
    ServiceJob::factory()->for($property)->create();
    // A house number with the same digits is not a phone match.
    $other = Customer::factory()->for($this->company)->create(['first_name' => 'Other']);
    Property::factory()->for($other)->create(['line1' => '604 Elm St']);

    $found = $this->getJson(route('jobs.lookup', ['search' => '604', 'by' => 'phone']))->assertOk()->json('customers');

    expect($found)->toHaveCount(1)
        ->and($found[0]['display_name'])->toBe('Jane Cooper')
        ->and($found[0]['jobs_count'])->toBe(2);

    expect($this->getJson(route('jobs.lookup', ['search' => '60', 'by' => 'phone']))->json('customers'))->toBe([]);
});

test('the phone search never finds another company\'s customers', function () {
    $other = Company::factory()->create();
    $foreign = Customer::factory()->for($other)->create();
    bookingPhone($other, $foreign, '+16045550199');

    expect($this->getJson(route('jobs.lookup', ['search' => '5550199', 'by' => 'phone']))->json('customers'))->toBe([]);
});

test('after booking the office lands on the day with "Booked #… · Tomorrow 9–11 a.m." and Open job', function () {
    $response = $this->post(route('jobs.store'), [
        'quick_booking' => true,
        'brand_id' => $this->brand->id,
        'job_type' => 'repair',
        'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Jane Quick', 'phone' => '+16045550101', 'property' => ['line1' => '1 Test Street', 'city' => 'Vancouver', 'country' => 'CA']],
        'add_visit' => true,
        'visit' => ['date' => '2030-01-16', 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => [$this->tech->id]],
    ]);
    $job = inCompany($this->company, fn () => ServiceJob::query()->sole());

    $response->assertSessionHasNoErrors()->assertRedirect(route('calendar', ['view' => 'day', 'date' => '2030-01-16']));
    // One sheet after booking: "Booked #… · …", Open job and Send Booking Confirmation.
    $booked = session('inertia.flash_data.booking_confirmation');
    $message = $booked['message'];

    expect($booked['url'])->toBe(route('jobs.show', $job, false))
        ->and($message)->toContain("Booked #{$job->number}")->toContain('Tomorrow')->toContain('9:00');
});
