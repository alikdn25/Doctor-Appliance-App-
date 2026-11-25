<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Messaging\BookingConfirmation;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Services\AI\QuickBookingParser;
use App\Support\Jobs\JobBacklog;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's update of November 16: Book customer saves with any data. A job without an address keeps an empty one and
 * shows "No address"; without a time it has no visit and counts under No time; "Me" is the default technician and a
 * company of one person gets every job.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver', 'sms_mode' => 'automatic']);
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
});

function anyBooking(array $data = []): array
{
    return ['quick_booking' => true, 'new_customer_mode' => true, ...$data];
}

function bookingVisit(array $visit = []): array
{
    return ['add_visit' => true, 'visit' => ['date' => '2030-01-15', 'start_time' => '09:00', 'end_time' => '11:00', ...$visit]];
}

test('a booking without an address saves with an empty address marked No address', function () {
    $this->post(route('jobs.store'), anyBooking([
        'new_customer' => ['first_name' => 'Bobby Chan', 'phone' => '236 788 5588', 'property' => ['line1' => '', 'city' => '']],
        ...bookingVisit(['assignee_ids' => [$this->tech->id]]),
    ]))->assertSessionHasNoErrors()->assertRedirect(route('calendar', ['view' => 'day', 'date' => '2030-01-15']));

    $job = inCompany($this->company, fn () => ServiceJob::with('property', 'customer.primaryPhone')->sole());
    expect($job->customer->display_name)->toBe('Bobby Chan')
        ->and($job->customer->primaryPhone->number)->toBe('+12367885588')
        ->and($job->property->hasAddress())->toBeFalse()
        ->and($job->property->line1)->toBeNull()
        ->and($job->property->fullAddress())->toBe('')
        ->and(JobBacklog::summary($this->owner)['counts']['no_address'])->toBe(1);

    // The empty address is filled later from the job page (the same address, not a second one).
    $this->put(route('jobs.address', $job), ['line1' => '12 Oak St', 'city' => 'Surrey', 'country' => 'CA'])->assertSessionHasNoErrors();
    expect($job->fresh()->property->line1)->toBe('12 Oak St')
        ->and(inCompany($this->company, fn () => Property::count()))->toBe(1);
});

test('a booking with only a phone saves', function () {
    $this->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '604 555 0101'], ...bookingVisit()]))
        ->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::with('customer.primaryPhone', 'property')->sole());
    expect($job->customer->primaryPhone->number)->toBe('+16045550101')
        ->and($job->customer->display_name)->toBe('Unnamed customer')
        ->and($job->property->hasAddress())->toBeFalse()
        ->and($job->brand_id)->toBe($this->brand->id)
        ->and($job->job_type->value)->toBe('repair');
});

test('a booking with no data at all saves a job without a visit', function () {
    $this->post(route('jobs.store'), ['quick_booking' => true])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::with('customer.phones', 'property', 'visits')->sole());
    expect($job->customer)->not->toBeNull()
        ->and($job->customer->phones)->toHaveCount(0)
        ->and($job->property->hasAddress())->toBeFalse()
        ->and($job->description)->toBeNull()
        ->and($job->visits)->toHaveCount(0)
        ->and($job->status)->toBe(JobStatus::New);
});

test('a booking without a time has no visit and counts under No time', function () {
    $response = $this->post(route('jobs.store'), anyBooking([
        'new_customer' => ['first_name' => 'Anna', 'phone' => '604 555 0102', 'property' => ['line1' => '1 Main St', 'city' => 'Surrey']],
        'description' => 'Oven repair',
        'add_visit' => false,
    ]));

    $job = inCompany($this->company, fn () => ServiceJob::sole());
    // A booking without a time goes back to the day like any booking, and confirms itself (owner's task of Nov 25).
    $response->assertSessionHasNoErrors()->assertRedirect();
    expect($job->fresh()->confirmation_sent_at)->not->toBeNull();
    $summary = JobBacklog::summary($this->owner);
    expect($job->visits()->count())->toBe(0)
        ->and($summary['counts']['needs_schedule'])->toBe(1)
        ->and($summary['counts']['no_address'])->toBe(0)
        ->and(__('jobs.backlog.reasons.needs_schedule'))->toBe('No time');

    $this->get(route('jobs.index', ['tab' => 'open', 'reason' => 'needs_schedule']))
        ->assertInertia(fn (Assert $page) => $page->where('jobs.data.0.id', $job->id));
});

test('the No address filter lists only jobs without an address', function () {
    $this->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '604 555 0103']]))->assertSessionHasNoErrors();
    $this->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '604 555 0104', 'property' => ['line1' => '2 Main St', 'city' => 'Surrey']]]))
        ->assertSessionHasNoErrors();
    $without = inCompany($this->company, fn () => ServiceJob::query()->whereHas('property', fn ($q) => $q->whereNull('line1'))->sole());

    $this->get(route('jobs.index', ['tab' => 'open', 'reason' => 'no_address']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.reason', 'no_address')
            ->has('jobs.data', 1)->where('jobs.data.0.id', $without->id)->where('jobs.data.0.address', ''));
});

test('the default technician is the person booking, Office menu starts on Unassigned', function () {
    $this->owner->forceFill(['menu_mode' => 'field'])->save();
    $this->actingAs($this->owner)->get(route('jobs.create', ['book' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('bookingTechnician', $this->owner->id)->where('soloCompany', false));

    $this->owner->forceFill(['menu_mode' => 'office'])->save();
    $this->actingAs($this->owner)->get(route('jobs.create', ['book' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('bookingTechnician', null));
});

test('in a company of one person the job goes to them', function () {
    $solo = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver']);
    $owner = memberOf($solo);
    Brand::factory()->for($solo)->create();

    $this->actingAs($owner)->get(route('jobs.create', ['book' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('soloCompany', true));
    $this->actingAs($owner)->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '604 555 0105'], ...bookingVisit()]))
        ->assertSessionHasNoErrors();

    $visit = inCompany($solo, fn () => ServiceJob::sole()->visits()->with('assignees')->sole());
    expect($visit->assignees->pluck('id')->all())->toBe([$owner->id]);

    // With a team, an unassigned booking stays unassigned.
    $this->actingAs($this->owner)->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '604 555 0106'], ...bookingVisit()]))
        ->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => ServiceJob::sole()->visits()->sole()->assignees()->count()))->toBe(0);
});

test('a phone, when given, must be a real number; the full job form saves without a customer too', function () {
    $this->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '12']]))->assertSessionHasErrors('new_customer.phone');
    expect(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0);

    // No required fields (owner's task of November 25): an "Unnamed customer" is made.
    $this->post(route('jobs.store'), ['brand_id' => $this->brand->id, 'job_type' => 'repair'])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => ServiceJob::with('customer')->sole()->customer->display_name))->toBe('Unnamed customer');
});

test('another company customer is never used by a booking', function () {
    $other = Company::factory()->create();
    $theirs = Customer::factory()->for($other)->create();

    $this->post(route('jobs.store'), anyBooking(['new_customer_mode' => false, 'customer_id' => $theirs->id]))
        ->assertSessionHasErrors('customer_id');
    expect(inCompany($other, fn () => Property::count()))->toBe(0)
        ->and(inCompany($this->company, fn () => ServiceJob::count()))->toBe(0);
});

test('the confirmation leaves the address line out and nothing goes without a phone', function () {
    $this->post(route('jobs.store'), anyBooking(['new_customer' => ['first_name' => 'Anna', 'phone' => '604 555 0107'], ...bookingVisit()]))
        ->assertSessionHasNoErrors();
    $job = inCompany($this->company, fn () => ServiceJob::sole());
    $body = inCompany($this->company, fn () => app(BookingConfirmation::class)->body($job));
    expect($body)->toContain('Hi Anna')->toContain('9:00 a.m.')->not->toContain(' at ')->not->toContain('null')->not->toContain('  ');

    $this->post(route('jobs.store'), anyBooking(bookingVisit()))->assertSessionHasNoErrors()
        ->assertSessionMissing('errors');
    $noPhone = inCompany($this->company, fn () => ServiceJob::query()->latest('id')->first());
    $plan = inCompany($this->company, fn () => app(BookingConfirmation::class)->plan($noPhone));
    expect($plan)->toBe(['channel' => null, 'to' => null, 'reason' => __('booking_confirmation.status.no_contact')]);
});

test('a job without an address is not on the map', function () {
    $this->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '604 555 0108'], ...bookingVisit(['assignee_ids' => [$this->owner->id]])]));
    $this->post(route('jobs.store'), anyBooking([
        'new_customer' => ['phone' => '604 555 0109', 'property' => ['line1' => '3 Main St', 'city' => 'Surrey']],
        ...bookingVisit(['assignee_ids' => [$this->owner->id]]),
    ]));

    $this->get(route('map', ['date' => '2030-01-15', 'person' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('stops', 1)->where('stops.0.address', fn ($a) => str_contains($a, '3 Main St')));
});

test('Paste from message on the job fills only the empty fields', function () {
    config(['ai.key' => null]);
    $this->post(route('jobs.store'), anyBooking(['new_customer' => ['phone' => '604 555 0110'], 'description' => 'Oven repair']));
    $job = inCompany($this->company, fn () => ServiceJob::sole());

    $this->post(route('jobs.fill-from-message', $job), [
        'text' => 'Anna Kim 604-555-0199, 123 Main St, Surrey. Fridge not cooling',
    ])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::with('customer.phones', 'property')->find($job->id));
    expect($job->customer->display_name)->toBe('Anna Kim')
        ->and($job->property->line1)->toBe('123 Main St')
        // Already filled: kept as they were.
        ->and($job->customer->phones->pluck('number')->all())->toBe(['+16045550110'])
        ->and($job->description)->toBe('Oven repair');
});

test('the AI model number is used only when it looks like one', function () {
    expect(QuickBookingParser::modelNumber('yg 502 s'))->toBe('YG502S')
        ->and(QuickBookingParser::modelNumber('WF45T6000AW/A5'))->toBe('WF45T6000AW/A5')
        ->and(QuickBookingParser::modelNumber('YG.Y'))->toBeNull()
        ->and(QuickBookingParser::modelNumber('the old one'))->toBeNull()
        ->and(QuickBookingParser::modelNumber(''))->toBeNull();

    config(['ai.models.booking' => 'claude-haiku-5-5', 'ai.daily_limit' => 50]);
    FakeAiTransport::install(['first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '',
        'address' => ['line1' => '', 'city' => '', 'region' => '', 'postal_code' => ''], 'unit' => '', 'buzzer' => '',
        'appliance_type' => 'oven', 'brand' => '', 'model' => 'YG.Y', 'problem' => 'Oven repair', 'date' => '',
        'arrival_now' => false, 'arrival_start' => '', 'arrival_end' => '', 'notes' => '', 'uncertain_fields' => []]);
    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'духовка'])->assertOk()->assertJsonPath('fields.model', null);
});
