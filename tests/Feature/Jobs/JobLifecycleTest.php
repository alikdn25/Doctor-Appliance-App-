<?php

use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Jobs\SendOfficeSms;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\JobBringItem;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Notifications\StrictArrivalReminder;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Deleting and restoring jobs, closing outcomes, cancelling, visit types (return visits, callbacks),
 * "Bring with you", job list filters.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create();
    $this->property = Property::factory()->for($this->customer)->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $this->visit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();
});

function startVisit(JobVisit $visit, $tech): void
{
    test()->actingAs($tech)->post(route('visits.start', $visit))->assertSessionHasNoErrors();
}

describe('deleting jobs', function () {
    test('the office deletes a job and restores it within 30 days; who and when is logged', function () {
        $this->actingAs($this->owner)->delete(route('jobs.destroy', $this->job))->assertRedirect(route('jobs.index'));

        $job = ServiceJob::withoutCompanyScope()->withTrashed()->find($this->job->id);
        expect($job->trashed())->toBeTrue()->and($job->deleted_by)->toBe($this->owner->id)
            ->and(AuditLog::query()->where('action', 'job.deleted')->where('user_id', $this->owner->id)->exists())->toBeTrue();

        $this->get(route('jobs.trash'))->assertInertia(fn (Assert $page) => $page
            ->component('jobs/trash')
            ->where('jobs.0.id', $this->job->id)
            ->where('jobs.0.deleted_by', $this->owner->name));

        $this->post(route('jobs.restore', $this->job))->assertRedirect(route('jobs.show', $this->job));
        expect(ServiceJob::withoutCompanyScope()->find($this->job->id))->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'job.restored')->exists())->toBeTrue();
    });

    test('after 30 days a deleted job can no longer be restored', function () {
        $this->actingAs($this->owner)->delete(route('jobs.destroy', $this->job));
        $this->travel(31)->days();

        $this->get(route('jobs.trash'))->assertInertia(fn (Assert $page) => $page->has('jobs', 0));
        $this->post(route('jobs.restore', $this->job))->assertForbidden();
    });

    test('a job with an invoice or a payment cannot be deleted', function () {
        $this->actingAs($this->owner);
        $this->post(route('invoices.store', $this->job), documentPayload());

        $this->delete(route('jobs.destroy', $this->job))->assertRedirect(route('jobs.show', $this->job));
        expect(ServiceJob::withoutCompanyScope()->find($this->job->id))->not->toBeNull();

        // A deposit paid on an estimate also blocks it.
        $other = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
        $this->post(route('estimates.store', $other), documentPayload());
        inCompany($this->company, function () {
            $estimate = Estimate::query()->latest('id')->first();
            $payment = new Payment(['amount' => 1000, 'method' => 'online', 'received_at' => now(), 'provider' => 'square', 'provider_payment_id' => 'D1']);
            $payment->estimate_id = $estimate->id;
            $payment->currency = $estimate->currency;
            $payment->save();
        });

        $this->delete(route('jobs.destroy', $other))->assertRedirect(route('jobs.show', $other));
        expect(ServiceJob::withoutCompanyScope()->find($other->id))->not->toBeNull();
    });

    test('technicians delete their own jobs only when the company allows it', function () {
        $this->actingAs($this->tech)->delete(route('jobs.destroy', $this->job))->assertForbidden();

        $this->company->update(['technicians_can_delete_jobs' => true]);
        $notMine = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
        $this->delete(route('jobs.destroy', $notMine))->assertForbidden();

        $this->delete(route('jobs.destroy', $this->job))->assertRedirect(route('jobs.mine'));
        expect(ServiceJob::withoutCompanyScope()->withTrashed()->find($this->job->id)->deleted_by)->toBe($this->tech->id);

        // Technicians cannot see or use the deleted list.
        $this->get(route('jobs.trash'))->assertForbidden();
        $this->post(route('jobs.restore', $this->job))->assertForbidden();
    });

    test('another company cannot delete or restore the job', function () {
        app(CurrentCompany::class)->forget();
        $this->actingAs(memberOf(Company::factory()->create()));

        $this->delete(route('jobs.destroy', $this->job))->assertNotFound();
        $this->post(route('jobs.restore', $this->job))->assertNotFound();
    });
});

describe('closing a job', function () {
    test('on site the technician finishes with "customer declined" and a reason, then invoices the diagnosis only', function () {
        $service = inCompany($this->company, fn () => Service::create(['name' => 'Diagnostic fee', 'unit_price' => 9500, 'taxable' => true, 'is_active' => true]));
        $this->company->update(['diagnostic_service_id' => $service->id]);
        startVisit($this->visit, $this->tech);

        $this->post(route('visits.finish', $this->visit), ['outcome' => 'customer_declined'])->assertSessionHasErrors('reason');
        $this->post(route('visits.finish', $this->visit), ['outcome' => 'customer_declined', 'reason' => 'Made up'])->assertSessionHasErrors('reason');

        $this->post(route('visits.finish', $this->visit), [
            'outcome' => 'customer_declined', 'reason' => 'Repair too expensive', 'note' => 'Quoted $480 for the board.',
            'invoice_diagnosis' => true,
        ])->assertRedirect(route('invoices.create', ['job' => $this->job->id, 'diagnosis' => 1]));

        $job = $this->job->fresh();
        expect($job->status)->toBe(JobStatus::Completed)
            ->and($job->outcome)->toBe(JobOutcome::CustomerDeclined)
            ->and($job->outcome_reason)->toBe('Repair too expensive')
            ->and($job->outcome_note)->toBe('Quoted $480 for the board.')
            ->and($job->closed_by)->toBe($this->tech->id)
            ->and($this->visit->fresh()->status)->toBe(VisitStatus::Completed);

        $this->get(route('invoices.create', ['job' => $this->job->id, 'diagnosis' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('prefillItems.0.description', 'Diagnostic fee')
            ->where('prefillItems.0.unit_price', 9500));
    });

    test('finishing as completed records "repaired"; waiting for parts leaves the job open', function () {
        startVisit($this->visit, $this->tech);
        $this->post(route('visits.finish', $this->visit), ['outcome' => 'waiting_for_parts'])->assertRedirect();
        expect($this->job->fresh())->status->toBe(JobStatus::WaitingForParts)->outcome->toBeNull();

        $this->actingAs($this->owner)->post(route('visits.store', $this->job), ['date' => '2030-07-01', 'start_time' => '13:00', 'end_time' => '15:00', 'assignee_ids' => [$this->tech->id]]);
        $second = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->latest('id')->first();
        startVisit($second, $this->tech);
        $this->post(route('visits.finish', $second), ['outcome' => 'completed'])->assertRedirect();

        expect($this->job->fresh())->status->toBe(JobStatus::Completed)->outcome->toBe(JobOutcome::Repaired);
    });

    test('the office closes a job as "unable to repair"; booking it again reopens it', function () {
        $this->actingAs($this->owner);
        $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
            ->where('can.close', true)
            ->where('closureReasons.unable_to_repair.0', 'Parts no longer available'));

        $this->post(route('jobs.close', $this->job), ['outcome' => 'unable_to_repair', 'reason' => 'Parts no longer available'])->assertRedirect();

        $job = $this->job->fresh();
        expect($job->outcome)->toBe(JobOutcome::UnableToRepair)
            ->and($job->status)->toBe(JobStatus::Completed)
            // Its scheduled visit is taken off the calendar.
            ->and($this->visit->fresh()->status)->toBe(VisitStatus::Cancelled);

        $this->post(route('visits.store', $this->job), ['date' => '2030-07-01', 'start_time' => '13:00', 'end_time' => '15:00'])->assertSessionHasNoErrors();
        expect($this->job->fresh())->outcome->toBeNull()->status->toBe(JobStatus::Scheduled);
    });

    test('a job cannot be closed while a visit is under way', function () {
        startVisit($this->visit, $this->tech);

        $this->actingAs($this->owner)->post(route('jobs.close', $this->job), ['outcome' => 'repaired'])->assertSessionHasErrors('outcome');
    });

    test('cancelling needs a reason and is only possible before any work', function () {
        $this->actingAs($this->owner);
        $this->put(route('jobs.status', $this->job), ['status' => 'cancelled'])->assertSessionHasErrors('reason');

        $this->put(route('jobs.status', $this->job), ['status' => 'cancelled', 'reason' => 'Nobody home / door not opened', 'note' => 'Knocked twice'])->assertSessionHasNoErrors();
        expect($this->job->fresh())->status->toBe(JobStatus::Cancelled)->outcome->toBe(JobOutcome::Cancelled)
            ->outcome_reason->toBe('Nobody home / door not opened');

        // Reopening clears the outcome.
        $this->put(route('jobs.status', $this->job), ['status' => 'new'])->assertSessionHasNoErrors();
        expect($this->job->fresh()->outcome)->toBeNull();

        $other = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
        startVisit(JobVisit::withoutCompanyScope()->where('service_job_id', $other->id)->sole(), $this->tech);
        $this->actingAs($this->owner)->put(route('jobs.status', $other), ['status' => 'cancelled', 'reason' => 'Customer changed their mind'])
            ->assertSessionHasErrors('status');
        expect($other->fresh()->status)->not->toBe(JobStatus::Cancelled);
    });

    test('the company sets its own reasons', function () {
        $this->actingAs($this->owner);
        $this->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('company.closure_reasons.customer_declined.0', 'Repair too expensive'));

        $settings = inCompany($this->company, fn () => $this->company->fresh());
        $this->put(route('company.settings.update'), [
            ...$settings->only(['name', 'timezone', 'country', 'currency', 'locale', 'invoice_next_number', 'estimate_next_number', 'travel_buffer_minutes']),
            'default_payment_terms' => $settings->default_payment_terms->value,
            'business_hours' => $settings->business_hours ?? Company::defaultBusinessHours(),
            'closure_reasons' => ['customer_declined' => ['Too old to repair ', '', 'Other'], 'unable_to_repair' => []],
            'technicians_can_delete_jobs' => true,
        ])->assertSessionHasNoErrors();

        $company = $this->company->fresh();
        expect($company->closureReasons(JobOutcome::CustomerDeclined))->toBe(['Too old to repair', 'Other'])
            ->and($company->closureReasons(JobOutcome::UnableToRepair)[0])->toBe('Parts no longer available')
            ->and($company->technicians_can_delete_jobs)->toBeTrue();
    });
});

describe('visit types', function () {
    function lifecycleJobPayload(array $overrides = []): array
    {
        return [
            'brand_id' => test()->brand->id,
            'job_type' => 'repair',
            'customer_id' => test()->customer->id,
            'property_id' => test()->property->id,
            ...$overrides,
        ];
    }

    test('a return visit links the earlier job and its appliances, with a "bring with you" list', function () {
        $this->actingAs($this->owner);
        $this->post(route('jobs.store'), lifecycleJobPayload(['visit_type' => 'return_visit']))->assertSessionHasErrors('previous_job_id');

        $this->post(route('jobs.store'), lifecycleJobPayload([
            'visit_type' => 'return_visit',
            'previous_job_id' => $this->job->id,
            'bring_items' => [['description' => 'Drain pump WPW10730972', 'quantity' => '1'], ['description' => 'Hose clamps', 'quantity' => '2']],
            'add_visit' => true,
            'visit' => ['date' => '2030-07-01', 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => [$this->tech->id], 'strict_arrival' => true],
        ]))->assertSessionHasNoErrors();

        $return = inCompany($this->company, fn () => ServiceJob::query()->latest('id')->with(['bringItems', 'visits'])->first());
        expect($return->visit_type->value)->toBe('return_visit')
            ->and($return->previous_job_id)->toBe($this->job->id)
            ->and($return->bringItems->pluck('description')->all())->toBe(['Drain pump WPW10730972', 'Hose clamps'])
            ->and($return->visits->sole()->strict_arrival)->toBeTrue();

        $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
            ->where('job.follow_ups.0.id', $return->id));

        // The technician ticks the items while loading.
        $item = $return->bringItems->first();
        $this->actingAs($this->tech)->put(route('jobs.bring.toggle', [$return, $item]), ['is_checked' => true])->assertRedirect();
        expect(inCompany($this->company, fn () => $item->fresh()))->is_checked->toBeTrue()->checked_by->toBe($this->tech->id);

        $day = $return->visits->sole()->scheduled_start->timezone($this->company->timezone)->toDateString();
        $visits = collect($this->get(route('jobs.mine', ['date' => $day]))->viewData('page')['props']['visits']);
        $visit = $visits->firstWhere('job.id', $return->id);
        expect($visit['job']['bring'])->toBe(['done' => 1, 'total' => 2])
            ->and($visit['strict_arrival'])->toBeTrue();
    });

    test('the earlier job must be one of the same customer', function () {
        $otherCustomer = Customer::factory()->for($this->company)->create();
        $otherJob = ServiceJob::factory()->for(Property::factory()->for($otherCustomer))->create(['brand_id' => $this->brand->id]);

        $this->actingAs($this->owner)->post(route('jobs.store'), lifecycleJobPayload([
            'visit_type' => 'callback', 'previous_job_id' => $otherJob->id,
        ]))->assertSessionHasErrors('previous_job_id');

        app(CurrentCompany::class)->forget();
        $foreign = ServiceJob::factory()->create();
        $this->actingAs($this->owner);
        $this->post(route('jobs.store'), lifecycleJobPayload(['visit_type' => 'callback', 'previous_job_id' => $foreign->id]))
            ->assertSessionHasErrors('previous_job_id');
    });

    test('another company cannot tick bring items', function () {
        $item = inCompany($this->company, function () {
            $item = new JobBringItem(['description' => 'Pump', 'position' => 0]);
            $item->service_job_id = $this->job->id;
            $item->save();

            return $item;
        });
        app(CurrentCompany::class)->forget();
        $this->actingAs(memberOf(Company::factory()->create()));

        $this->put(route('jobs.bring.toggle', [$this->job, $item]), ['is_checked' => true])->assertNotFound();
    });
});

test('the job list filters by visit type, strict arrival and outcome', function () {
    $this->actingAs($this->owner);
    $callback = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'visit_type' => 'callback', 'previous_job_id' => $this->job->id]);
    inCompany($this->company, fn () => $this->visit->forceFill(['strict_arrival' => true])->save());
    $declined = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    inCompany($this->company, fn () => $declined->forceFill(['outcome' => 'customer_declined', 'outcome_reason' => 'Other'])->save());

    $ids = fn (array $query) => collect($this->get(route('jobs.index', $query))->viewData('page')['props']['jobs']['data'])->pluck('id')->all();

    expect($ids(['visit_type' => 'callback']))->toBe([$callback->id])
        ->and($ids(['strict' => 1]))->toBe([$this->job->id])
        ->and($ids(['outcome' => 'customer_declined']))->toBe([$declined->id])
        ->and($ids(['outcome' => 'none']))->toContain($this->job->id)->not->toContain($declined->id);
});

test('technicians are reminded once before a visit with a strict arrival time', function () {
    Notification::fake();
    Queue::fake();
    config(['services.twilio.account_sid' => 'ACmaster', 'services.twilio.auth_token' => 't']);
    $this->company->update(['sms_mode' => 'automatic', 'strict_arrival_reminder_minutes' => 60]);
    $this->tech->update(['phone' => '+16045550123']);
    inCompany($this->company, function () {
        SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 's', 'phone_number' => '+16045550100']);
        $this->visit->forceFill(['strict_arrival' => true, 'scheduled_start' => now()->addMinutes(45), 'scheduled_end' => now()->addMinutes(165)])->save();
    });
    $loose = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    inCompany($this->company, fn () => JobVisit::query()->where('service_job_id', $loose->id)->update(['scheduled_start' => now()->addMinutes(30), 'scheduled_end' => now()->addMinutes(90)]));

    $this->artisan('visits:strict-arrival-reminders')->assertSuccessful();
    $this->artisan('visits:strict-arrival-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->tech, StrictArrivalReminder::class, 1);
    Queue::assertPushed(SendOfficeSms::class, 1);
    expect($this->visit->fresh()->strict_reminder_sent_at)->not->toBeNull();
});
