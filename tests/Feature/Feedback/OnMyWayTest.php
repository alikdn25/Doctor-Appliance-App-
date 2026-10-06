<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;

beforeEach(function () {
    $this->company = Company::factory()->create(['sms_mode' => 'technician_phone']);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create();
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $brand->id, 'status' => JobStatus::Scheduled]);
    $this->visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->tech)
        ->create(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
    $this->actingAs($this->tech);
});

test('On my way from the technician phone changes the status and records the text in one request', function () {
    $this->post(route('visits.on-my-way', $this->visit), [
        'phone_sms' => ['to' => '+16045550101', 'body' => 'On my way!'],
    ])->assertRedirect();

    expect($this->visit->fresh()->status)->toBe(VisitStatus::OnTheWay)
        ->and($this->job->fresh()->status)->toBe(JobStatus::OnTheWay);

    $message = inCompany($this->company, fn () => Message::query()->where('service_job_id', $this->job->id)->sole());
    expect($message->body)->toBe('On my way!')
        ->and($message->channel)->toBe(Message::TECHNICIAN_PHONE);
});

test('On my way still works without a phone text', function () {
    $this->post(route('visits.on-my-way', $this->visit))->assertRedirect();

    expect($this->job->fresh()->status)->toBe(JobStatus::OnTheWay);
    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
});
