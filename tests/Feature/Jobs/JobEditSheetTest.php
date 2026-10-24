<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Support\Billing\JobProfit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's update of October 24: the job page's pencil sheet (customer name, address, date, window, technician,
 * "Notify client" off by default), Costs & profit only for the Owner on a closed job, and "+" → Expense → Job.
 */
beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/2010-04-01/Accounts/ACsub/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));

    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'country' => 'CA', 'timezone' => 'America/Vancouver', 'currency' => 'CAD']);
    $this->owner = memberOf($this->company);
    $this->admin = memberOf($this->company, UserRole::Admin);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->other = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')
        ->create(['first_name' => 'Paula', 'last_name' => 'Payer']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '500 Rental Ave', 'city' => 'Surrey']);
    $this->second = Property::factory()->for($this->customer)->create(['line1' => '12 Oak St', 'city' => 'Burnaby']);
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-16 13:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-16 15:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $brand->id]);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
    $this->category = inCompany($this->company, fn () => BusinessExpenseCategory::query()->forceCreate(['name' => 'Parts', 'created_by' => $this->owner->id]));
    $this->visit = fn () => inCompany($this->company, fn () => JobVisit::query()->where('service_job_id', $this->job->id)->sole());
    $this->texts = fn () => inCompany($this->company, fn () => Message::query()->where('kind', 'booking_confirmation')->count());
    $this->actingAs($this->owner);
});

test('the sheet saves name, address, day, window and technician in one go and texts nobody unless asked', function () {
    $this->put(route('jobs.details', $this->job), [
        'first_name' => 'Pauline', 'last_name' => 'Payer',
        'property_id' => $this->second->id,
        'date' => '2026-10-20', 'start_time' => '15:00', 'end_time' => '17:00',
        'technician_id' => $this->other->id,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $job = inCompany($this->company, fn () => $this->job->fresh());
    $visit = ($this->visit)();
    expect(inCompany($this->company, fn () => $this->customer->fresh()->display_name))->toBe('Pauline Payer')
        ->and($job->property_id)->toBe($this->second->id)
        ->and($visit->scheduled_start->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-20 15:00')
        ->and($visit->scheduled_end->setTimezone('America/Vancouver')->format('H:i'))->toBe('17:00')
        ->and(inCompany($this->company, fn () => $visit->assignees()->pluck('users.id')->all()))->toBe([$this->other->id])
        ->and(($this->texts)())->toBe(0)
        ->and(session('inertia.flash_data.notify_client'))->toBeNull();
});

test('"Notify client" ticked sends the updated confirmation after Save', function () {
    $this->put(route('jobs.details', $this->job), [
        'date' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '11:00', 'notify' => true,
    ])->assertSessionHasNoErrors();

    $message = inCompany($this->company, fn () => Message::query()->where('kind', 'booking_confirmation')->sole());
    expect($message->to)->toBe('+16045550142')
        ->and($message->body)->toContain('9:00 a.m. - 11:00 a.m.');
});

test('a new address in the sheet goes on the job and the customer', function () {
    $this->put(route('jobs.details', $this->job), [
        'address' => 'new', 'line1' => '77 New Rd', 'city' => 'Richmond', 'country' => 'CA',
    ])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => $this->job->fresh()->load('property'));
    expect($job->property->line1)->toBe('77 New Rd')
        ->and($job->property->customer_id)->toBe($this->customer->id);
});

test('the sheet keeps a name and a valid window, and only people who manage the job may use it', function () {
    $stranger = memberOf(Company::factory()->create());
    $this->put(route('jobs.details', $this->job), ['first_name' => '', 'last_name' => ''])->assertSessionHasErrors('first_name');
    $this->put(route('jobs.details', $this->job), ['date' => '2026-10-20', 'start_time' => '15:00', 'end_time' => '14:00'])->assertSessionHasErrors('end_time');

    // A technician does not dispatch: changing the technician is the office's.
    $this->actingAs($this->tech)->put(route('jobs.details', $this->job), ['technician_id' => $this->tech->id])->assertForbidden();

    // Another company's job is simply not found, and nothing of it changes.
    $this->actingAs($stranger)->put(route('jobs.details', $this->job), ['first_name' => 'Hacked'])->assertNotFound();
    expect(inCompany($this->company, fn () => $this->customer->fresh()->first_name))->toBe('Paula');
});

test('another customer\'s address cannot be put on the job', function () {
    $foreign = Property::factory()->for(Customer::factory()->for($this->company))->create(['line1' => '1 Else St', 'city' => 'Delta']);

    $this->put(route('jobs.details', $this->job), ['property_id' => $foreign->id])->assertSessionHasErrors('property_id');
    expect(inCompany($this->company, fn () => $this->job->fresh()->property_id))->toBe($this->property->id);
});

test('Costs & profit is shown only to the Owner and only once the job is closed', function () {
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('costs', null));

    inCompany($this->company, fn () => $this->job->forceFill(['closed_at' => now()])->save());
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->whereNot('costs', null));
    $this->actingAs($this->admin)->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('costs', null));
    $this->actingAs($this->tech)->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('costs', null));
});

test('an expense for a job lands in that job\'s Costs and profit, not the overhead', function () {
    $this->get(route('expenses.create', ['job' => $this->job->id]))->assertInertia(fn (Assert $page) => $page
        ->where('jobId', $this->job->id)
        ->where('jobs.0.number', $this->job->number)
        ->where('jobs.0.customer', 'Paula Payer'));

    $this->post(route('expenses.store'), [
        'category_id' => $this->category->id, 'spent_on' => '2026-10-14', 'description' => 'Drain pump',
        'merchant' => 'Reliable Parts', 'amount' => '40.00', 'tax_amount' => '2.00', 'service_job_id' => $this->job->id,
    ])->assertSessionHasNoErrors();

    $expense = inCompany($this->company, fn () => BusinessExpense::query()->sole());
    expect($expense->service_job_id)->toBe($this->job->id)
        ->and(inCompany($this->company, fn () => JobProfit::for($this->job->fresh())['cost']))->toBe(4200);

    inCompany($this->company, fn () => $this->job->forceFill(['closed_at' => now()])->save());
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('costs.expenses.0.description', 'Drain pump')
        ->where('costs.expenses.0.total', 4200)
        ->where('costs.profit.cost', 4200));

    // The month's overhead leaves it out (it is in the job's cost).
    $this->get(route('reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page->where('profit.expenses', 0));
});

test('an expense can be for a job of the person only, never another company\'s', function () {
    $foreignJob = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for(Company::factory()->create())))->create();
    $payload = ['category_id' => $this->category->id, 'spent_on' => '2026-10-14', 'description' => 'Fuel', 'amount' => '10.00', 'tax_amount' => '0'];

    $this->post(route('expenses.store'), [...$payload, 'service_job_id' => $foreignJob->id])->assertSessionHasErrors('service_job_id');

    // A technician links only the jobs they go on.
    $theirs = ServiceJob::factory()->for($this->property)->withVisit($this->other)->create(['brand_id' => $this->job->brand_id]);
    $this->actingAs($this->tech)->post(route('expenses.store'), [...$payload, 'service_job_id' => $theirs->id])->assertSessionHasErrors('service_job_id');
    $this->actingAs($this->tech)->post(route('expenses.store'), [...$payload, 'service_job_id' => $this->job->id])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->get(route('expenses.create'))->assertInertia(fn (Assert $page) => $page
        ->where('jobs', fn ($jobs) => collect($jobs)->pluck('id')->all() === [$this->job->id]));

    expect(inCompany($this->company, fn () => BusinessExpense::query()->whereNotNull('service_job_id')->count()))->toBe(1);
});
