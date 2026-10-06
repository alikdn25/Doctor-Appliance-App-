<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2030-06-12 18:00:00');
    $this->company = Company::factory()->create(['sms_mode' => 'technician_phone']);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Card', 'last_name' => 'Owner']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $brand->id]);
    $this->visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
    $this->actingAs($this->tech);
});

test('a scheduled card offers On my way with the text for the technician\'s phone', function () {
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.id', $this->visit->id)
        ->where('visits.0.can_work', true)
        ->where('visits.0.on_my_way_sms.to', '+16045550101')
        ->where('visits.0.on_my_way_sms.body', fn ($body) => is_string($body) && $body !== ''));
});

test('no phone text is prepared when the company texts automatically or the visit is under way', function () {
    $this->company->update(['sms_mode' => 'automatic']);
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('visits.0.on_my_way_sms', null));

    $this->company->update(['sms_mode' => 'technician_phone']);
    $this->visit->forceFill(['status' => VisitStatus::InProgress])->saveQuietly();
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('visits.0.on_my_way_sms', null));
});

test('cards of a closed job offer no field action', function () {
    $this->job->forceFill(['status' => JobStatus::Cancelled])->saveQuietly();
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('visits.0.can_work', false));
});

test('an old finish link opens the Finish visit screen while the visit is under way', function () {
    $this->get(route('jobs.show', [$this->job, 'finish' => 1]))->assertOk();

    $this->visit->forceFill(['status' => VisitStatus::InProgress])->saveQuietly();
    $this->get(route('jobs.show', [$this->job, 'finish' => 1]))->assertRedirect(route('visits.finish-screen', $this->visit));
    $this->get(route('jobs.show', $this->job))->assertOk();
});

test('the Finish visit screen shows the job and saves the work notes with the result', function () {
    $this->visit->forceFill(['status' => VisitStatus::InProgress, 'started_at' => now()])->saveQuietly();
    $this->job->forceFill(['status' => JobStatus::InProgress])->saveQuietly();

    $this->get(route('visits.finish-screen', $this->visit))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/finish')
            ->where('visitId', $this->visit->id)
            ->where('job.number', $this->job->number)
            ->where('job.customer.display_name', 'Card Owner')
            ->where('job.customer.phone', '+16045550101'));

    $this->post(route('visits.finish', $this->visit), [
        'outcome' => 'completed',
        'tech_notes' => 'Replaced compressor. No leaks.',
    ])->assertSessionHasNoErrors();

    $job = ServiceJob::withoutCompanyScope()->find($this->job->id);
    expect($job->tech_notes)->toBe('Replaced compressor. No leaks.')
        ->and($job->status)->toBe(JobStatus::Completed)
        ->and($this->visit->fresh()->status)->toBe(VisitStatus::Completed);

    // Once finished, the screen goes back to the job.
    $this->get(route('visits.finish-screen', $this->visit))->assertRedirect(route('jobs.show', $this->job));
});

test('part needed keeps the job waiting for parts and other technicians cannot open the screen', function () {
    $foreign = memberOf(Company::factory()->create(), UserRole::Owner);
    $this->visit->forceFill(['status' => VisitStatus::InProgress, 'started_at' => now()])->saveQuietly();
    $this->job->forceFill(['status' => JobStatus::InProgress])->saveQuietly();

    $other = memberOf($this->company, UserRole::Technician);
    $this->actingAs($other)->get(route('visits.finish-screen', $this->visit))->assertForbidden();

    $this->actingAs($foreign)->get(route('visits.finish-screen', $this->visit))->assertNotFound();

    $this->actingAs($this->tech)->post(route('visits.finish', $this->visit), [
        'outcome' => 'waiting_for_parts',
        'tech_notes' => 'Needs drain pump.',
    ])->assertSessionHasNoErrors();

    $job = ServiceJob::withoutCompanyScope()->find($this->job->id);
    expect($job->status)->toBe(JobStatus::WaitingForParts)
        ->and($job->tech_notes)->toBe('Needs drain pump.');
});
