<?php

use App\Actions\Billing\SendDocument;
use App\Enums\EstimateStatus;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Enums\UserRole;
use App\Mail\CustomerMessageMail;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo('2030-06-12 23:00:00'); // 17:00 in Regina.
    config(['sms.reminder_hour' => 17]);
    Mail::fake();
    $this->company = Company::factory()->create(['timezone' => 'America/Regina', 'estimate_followup_days' => 3, 'sms_mode' => SmsMode::Off]);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->create();
    $property = Property::factory()->for($customer)->create();
    $this->job = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id, 'status' => JobStatus::WaitingForCustomer]);
    inCompany($this->company, fn () => $customer->emails()->create(['label' => 'personal', 'email' => 'client@example.com', 'is_primary' => true]));
});

function followupEstimate(array $overrides = []): Estimate
{
    return inCompany(test()->company, fn () => Estimate::factory()->create([
        'service_job_id' => test()->job->id, 'sent_at' => now()->subDays(3), 'currency' => 'CAD', 'total' => 12500,
        ...$overrides,
    ]));
}

test('an unanswered sent estimate receives one reminder with its own public link', function () {
    $estimate = followupEstimate();
    $this->artisan('estimates:send-followups')->assertSuccessful();
    $this->artisan('estimates:send-followups')->assertSuccessful();
    $message = inCompany($this->company, fn () => Message::sole());
    expect($message->kind)->toBe(MessageKind::EstimateFollowup)
        ->and($message->body)->toContain($estimate->number, '/d/'.$estimate->fresh()->public_token)
        ->and($message->to)->toBe('client@example.com')
        ->and($estimate->fresh()->followup_processed_at)->not->toBeNull();
    Mail::assertQueued(CustomerMessageMail::class, 1);
});

test('unsent recent expired and answered estimates do not receive reminders', function () {
    followupEstimate(['sent_at' => null]);
    followupEstimate(['sent_at' => now()->subDays(2)]);
    followupEstimate(['valid_until' => '2030-06-11']);
    foreach ([EstimateStatus::Approved, EstimateStatus::Declined, EstimateStatus::Invoiced, EstimateStatus::Revised] as $status) {
        followupEstimate(['status' => $status]);
    }
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    expect(inCompany($this->company, fn () => Message::count()))->toBe(0);
    Mail::assertNothingQueued();
});

test('disabled companies and reminder hours are respected', function () {
    $estimate = followupEstimate(['sent_at' => now()->subDays(4)]);
    $this->company->update(['estimate_followup_days' => null]);
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    $this->company->update(['estimate_followup_days' => 3]);
    $this->travelTo('2030-06-12 22:00:00');
    $this->artisan('estimates:send-followups')->assertSuccessful();
    expect($estimate->fresh()->followup_processed_at)->toBeNull();
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    expect($estimate->fresh()->followup_processed_at)->not->toBeNull();
});

test('closed jobs and inactive companies are excluded', function () {
    $estimate = followupEstimate();
    foreach ([JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled, JobStatus::OnHold] as $status) {
        $this->job->forceFill(['status' => $status])->saveQuietly();
        $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    }
    $this->job->forceFill(['status' => JobStatus::WaitingForCustomer, 'closed_at' => now()])->saveQuietly();
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    $this->job->forceFill(['status' => JobStatus::WaitingForCustomer, 'closed_at' => null])->saveQuietly();
    $this->company->update(['status' => 'suspended']);
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    expect($estimate->fresh()->followup_processed_at)->toBeNull();
});

test('a missing contact is recorded once instead of retried every hour', function () {
    inCompany($this->company, fn () => $this->job->customer->emails()->delete());
    $estimate = followupEstimate();
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    expect(inCompany($this->company, fn () => Message::sole()->status))->toBe('blocked')
        ->and($estimate->fresh()->followup_processed_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

test('processing more than a chunk never skips estimates and keeps tenants separate', function () {
    for ($i = 0; $i < 102; $i++) {
        followupEstimate();
    }
    $other = Company::factory()->create(['timezone' => 'America/Regina', 'estimate_followup_days' => null]);
    $otherEstimate = inCompany($other, function () use ($other) {
        $customer = Customer::factory()->for($other)->create();
        $property = Property::factory()->for($customer)->create();
        $job = ServiceJob::factory()->for($property)->create();

        return Estimate::factory()->create(['service_job_id' => $job->id, 'currency' => $other->currency, 'sent_at' => now()->subDays(4)]);
    });
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    expect(inCompany($this->company, fn () => Message::count()))->toBe(102)
        ->and(inCompany($other, fn () => Message::count()))->toBe(0)
        ->and($otherEstimate->fresh()->followup_processed_at)->toBeNull();
});

test('an explicit resend restarts the delay before a new reminder', function () {
    $estimate = followupEstimate();
    $owner = memberOf($this->company, UserRole::Owner);
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    inCompany($this->company, fn () => app(SendDocument::class)->handle($estimate->fresh(), 'client@example.com', 'Updated estimate.', $owner));
    expect($estimate->fresh()->followup_processed_at)->toBeNull();
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    expect(inCompany($this->company, fn () => Message::count()))->toBe(1);
    $this->travel(3)->days();
    $this->artisan('estimates:send-followups', ['--force' => true])->assertSuccessful();
    expect(inCompany($this->company, fn () => Message::count()))->toBe(2);
});
