<?php

use App\Actions\Members\AddMember;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Do you work alone or with a team?" (owner's fixes, October 19): working alone every job is the Owner's and
 * there is no technician to choose; the first team member switches the company to "with a team".
 */
beforeEach(function () {
    Notification::fake();
});

function workModeSettings(Company $company, array $overrides = []): array
{
    return [
        'name' => $company->name, 'timezone' => $company->timezone, 'country' => $company->country,
        'currency' => $company->currency, 'locale' => $company->locale,
        'default_payment_terms' => $company->default_payment_terms->value,
        'invoice_next_number' => 1, 'estimate_next_number' => 1, 'travel_buffer_minutes' => 0,
        'business_hours' => collect(Company::WEEKDAYS)->mapWithKeys(fn ($d) => [$d => ['closed' => true]])->all(),
        'section' => 'jobs',
        ...$overrides,
    ];
}

test('registration asks whether the company works alone and remembers the answer', function () {
    $user = User::factory()->create();
    $payload = [
        'name' => 'Solo Repair', 'country' => 'US', 'vertical' => 'appliance_repair',
        'currency' => 'USD', 'locale' => 'en-US', 'timezone' => 'America/Chicago',
    ];

    $this->actingAs($user)->post(route('onboarding.company.store'), $payload)->assertSessionHasErrors('works_alone');
    $this->post(route('onboarding.company.store'), [...$payload, 'works_alone' => true])->assertSessionHasNoErrors();

    $company = $user->fresh()->accessibleCompanies()->sole();
    expect($company->works_alone)->toBeTrue();
    $this->get(route('jobs.index'))->assertInertia(fn (Assert $page) => $page->where('auth.company.works_alone', true));
});

test('working alone every booking goes to the Owner and nobody is offered to choose', function () {
    $company = Company::factory()->create(['works_alone' => true, 'timezone' => 'America/Chicago']);
    $owner = memberOf($company);
    $brand = Brand::factory()->for($company)->create();

    $this->actingAs($owner)->post(route('jobs.store'), [
        'quick_booking' => true, 'brand_id' => $brand->id, 'job_type' => 'repair', 'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Ann', 'last_name' => 'Lee', 'phone' => '+13125550100',
            'property' => ['line1' => '1 Main St', 'city' => 'Chicago', 'country' => 'US']],
        'add_visit' => true,
        'visit' => ['date' => '2030-01-15', 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => []],
    ])->assertSessionHasNoErrors();

    $visit = inCompany($company, fn () => JobVisit::with('assignees')->sole());
    expect($visit->assignees->pluck('id')->all())->toBe([$owner->id]);

    $job = inCompany($company, fn () => ServiceJob::sole());
    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->where('can.assign', false));
    // No "Unassigned" lane in the calendar.
    $this->get(route('calendar', ['view' => 'day', 'date' => '2030-01-15']))
        ->assertInertia(fn (Assert $page) => $page->where('lanes', fn ($lanes) => collect($lanes)->every(fn ($l) => $l['id'] !== null)));
});

test('switching to "Just me" gives the open jobs nobody has to the Owner', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company);
    $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($company)))->create(['status' => JobStatus::Scheduled]);
    $visit = JobVisit::factory()->for($job, 'job')->create();

    $this->actingAs($owner)->put(route('company.settings.update'), workModeSettings($company, ['works_alone' => true]))
        ->assertSessionHasNoErrors();

    expect($company->fresh()->works_alone)->toBeTrue()
        ->and(inCompany($company, fn () => $visit->assignees()->pluck('users.id')->all()))->toBe([$owner->id]);
});

test('adding the first team member switches the company to "with a team"', function () {
    $company = Company::factory()->create(['works_alone' => true]);
    $owner = memberOf($company);

    inCompany($company, fn () => app(AddMember::class)->handle($company, 'Tia Tech', 'tia@example.com', UserRole::Technician, [], 'Tech-password-123!'));

    expect($company->fresh()->works_alone)->toBeFalse();

    // With a team the office chooses; a person who goes on calls (Field menu) books to themselves.
    $this->actingAs($owner)->get(route('jobs.create', ['book' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('bookingTechnician', null));
    $owner->forceFill(['menu_mode' => 'field'])->save();
    $this->get(route('jobs.create', ['book' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('bookingTechnician', $owner->id));
});
