<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobPart;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\Task;
use App\Models\Trip;
use App\Support\Jobs\JobBacklog;
use App\Support\Jobs\MorningSms;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's task of November 25: no required fields anywhere; jobs without a time or a day are always seen (calendar,
 * Today, My Jobs, morning summary, counters); the search finds any job.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver', 'currency' => 'CAD']);
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
    $this->today = CarbonImmutable::now('America/Vancouver')->toDateString();
});

/** An empty job made the way the full job form saves it with nothing typed. */
function emptyJob(): ServiceJob
{
    test()->post(route('jobs.store'), [])->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => ServiceJob::query()->latest('id')->firstOrFail());
}

test('the full job form saves completely empty, as an "Unnamed customer" job with no address', function () {
    $job = emptyJob();

    inCompany($this->company, function () use ($job) {
        $job->load('customer', 'property', 'visits');
        expect($job->customer->display_name)->toBe('Unnamed customer')
            ->and($job->customer->hasName())->toBeFalse()
            ->and($job->property->hasAddress())->toBeFalse()
            ->and($job->brand_id)->toBe($this->brand->id)
            ->and($job->visits)->toHaveCount(0)
            ->and($job->planned_date)->toBeNull();
    });
});

test('editing a job with every field emptied saves and keeps the brand, type and address', function () {
    $job = emptyJob();

    $this->put(route('jobs.update', $job), [
        'brand_id' => '', 'job_type' => '', 'property_id' => '',
        'customer_edit' => ['first_name' => '', 'last_name' => '', 'company_name' => '', 'phone' => ''],
        'address_edit' => ['line1' => '', 'city' => '', 'country' => ''],
        'bring_items' => [['description' => '', 'quantity' => '']],
        'new_appliances' => [['type' => '']],
    ])->assertSessionHasNoErrors()->assertRedirect(route('jobs.show', $job));

    expect($job->fresh()->brand_id)->toBe($this->brand->id)
        ->and($job->fresh()->job_type->value)->toBe('repair');
});

test('a saved job shows "Saved" with an Open button', function () {
    $this->post(route('jobs.store'), [])->assertSessionHasNoErrors();
    $job = inCompany($this->company, fn () => ServiceJob::sole());

    expect(session('inertia.flash_data.toast'))->toMatchArray([
        'type' => 'success',
        'message' => 'Saved',
        'action' => ['label' => 'Open', 'url' => route('jobs.show', $job)],
    ]);
});

test('a to-do saves empty as "Untitled" today without a time', function () {
    $this->post(route('tasks.store'), [])->assertSessionHasNoErrors();

    $task = Task::query()->sole();
    expect($task->title)->toBe('Untitled')
        ->and($task->task_date->toDateString())->toBe($this->today)
        ->and($task->all_day)->toBeTrue();
});

test('a part saves empty as "Untitled"', function () {
    $job = emptyJob();

    $this->post(route('jobs.parts.store', $job), [])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => JobPart::query()->sole()->name))->toBe('Untitled');
});

test('a job cost line saves empty', function () {
    $job = emptyJob();

    $this->post(route('jobs.costs.store', $job), [])->assertSessionHasNoErrors();
});

test('a customer saves completely empty', function () {
    $this->post(route('customers.store'), [])->assertSessionHasNoErrors();

    $customer = inCompany($this->company, fn () => Customer::query()->sole());
    expect($customer->display_name)->toBe('Unnamed customer')
        ->and($customer->type->value)->toBe('residential');
});

test('a customer with empty phone and email rows and an empty address saves', function () {
    $this->post(route('customers.store'), [
        'type' => '', 'first_name' => '', 'phones' => [['label' => '', 'number' => '']], 'emails' => [['label' => '', 'email' => '']],
        'add_property' => true, 'property' => ['line1' => '', 'city' => '', 'country' => ''],
    ])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Customer::query()->withCount('phones')->sole()->phones_count))->toBe(0);
});

test('an estimate and an invoice save with no lines and no date', function () {
    $job = emptyJob();

    $this->post(route('estimates.store', $job), [])->assertSessionHasNoErrors();
    $this->post(route('invoices.store', $job), ['items' => [['description' => '', 'quantity' => '', 'unit_price' => '']]])
        ->assertSessionHasNoErrors();

    inCompany($this->company, function () {
        expect(Estimate::query()->sole()->issued_on->toDateString())->toBe($this->today)
            ->and(Invoice::query()->sole()->total)->toBe(0);
    });
});

test('an invoice line with only a price saves as "Untitled", quantity 1', function () {
    $job = emptyJob();

    $this->post(route('invoices.store', $job), ['items' => [['unit_price' => '80']]])->assertSessionHasNoErrors();

    $item = inCompany($this->company, fn () => Invoice::query()->sole()->items()->sole());
    expect($item->description)->toBe('Untitled')
        ->and((float) $item->quantity)->toBe(1.0)
        ->and($item->unit_price)->toBe(8000);
});

test('New charge of a job saves with nothing typed and opens the invoice', function () {
    $job = emptyJob();

    $response = $this->post(route('jobs.quick-invoice', $job), []);

    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    $response->assertSessionHasNoErrors()->assertRedirect(route('invoices.show', $invoice));
});

test('marking a payment with nothing typed records the balance as "Other"', function () {
    $job = emptyJob();
    $this->post(route('invoices.store', $job), ['items' => [['description' => 'Repair', 'unit_price' => '100']]])->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());

    $this->post(route('payments.store', $invoice), [])->assertSessionHasNoErrors();

    inCompany($this->company, function () use ($invoice) {
        $payment = Payment::query()->sole();
        expect($payment->method->value)->toBe('other')
            ->and($payment->amount)->toBe($invoice->fresh()->total)
            ->and($invoice->fresh()->balance)->toBe(0);
    });

    // Nothing left to pay and nothing typed: saved, nothing recorded.
    $this->post(route('payments.store', $invoice), [])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Payment::query()->count()))->toBe(1);
});

test('an expense saves empty as "Other", "Untitled", 0, today', function () {
    $this->post(route('expenses.store'), [])->assertSessionHasNoErrors();

    $expense = inCompany($this->company, fn () => BusinessExpense::query()->with('category')->sole());
    expect($expense->description)->toBe('Untitled')
        ->and($expense->amount)->toBe(0)
        ->and($expense->spent_on->toDateString())->toBe($this->today)
        ->and($expense->category->name)->toBe('Other');
});

test('a job expense line saves without an amount and the form saves with no lines', function () {
    $job = emptyJob();

    $this->post(route('jobs.my-expenses.store', $job), [])->assertSessionHasNoErrors();
    $this->post(route('jobs.my-expenses.store', $job), ['lines' => [['description' => 'Parking', 'amount' => '']]])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => BusinessExpense::query()->sole()->amount))->toBe(0);
});

test('a mileage trip saves empty, today, without a distance', function () {
    $this->post(route('trips.store'), [])->assertSessionHasNoErrors();
    $this->post(route('trips.quick'), [])->assertSessionHasNoErrors();

    inCompany($this->company, function () {
        expect(Trip::query()->count())->toBe(2)
            ->and(Trip::query()->first()->trip_date->toDateString())->toBe($this->today);
    });
});

test('a visit saves with a day only (No time) and with nothing at all', function () {
    $job = emptyJob();

    $this->post(route('visits.store', $job), ['date' => '2030-01-15'])->assertSessionHasNoErrors();
    expect($job->fresh()->planned_date->toDateString())->toBe('2030-01-15')
        ->and(inCompany($this->company, fn () => JobVisit::query()->count()))->toBe(0);

    $this->post(route('visits.store', $job), [])->assertSessionHasNoErrors();

    // A time given later books the visit and the job leaves No time.
    $this->post(route('visits.store', $job), ['date' => '2030-01-15', 'start_time' => '10:00'])->assertSessionHasNoErrors();
    $visit = inCompany($this->company, fn () => JobVisit::query()->sole());
    expect($job->fresh()->planned_date)->toBeNull()
        ->and($visit->scheduled_end->greaterThan($visit->scheduled_start))->toBeTrue();
});

test('a job booked on a day without a time is in the calendar strip, on Today and on My Jobs that day', function () {
    $this->post(route('jobs.store'), [
        'quick_booking' => true,
        'new_customer' => ['first_name' => 'Nora Lee'],
        'add_visit' => true,
        'visit' => ['date' => $this->today, 'start_time' => null, 'end_time' => null],
    ])->assertSessionHasNoErrors();
    $job = inCompany($this->company, fn () => ServiceJob::sole());
    expect($job->planned_date->toDateString())->toBe($this->today);

    $this->get(route('calendar', ['date' => $this->today]))->assertInertia(fn (Assert $page) => $page
        ->has("noTime.{$this->today}", 1)
        ->where("noTime.{$this->today}.0.id", $job->id)
        ->has('noDate', 0));

    $this->get(route('calendar', ['view' => 'week', 'date' => $this->today]))->assertInertia(fn (Assert $page) => $page
        ->where("noTime.{$this->today}.0.id", $job->id));

    $this->get(route('today'))->assertInertia(fn (Assert $page) => $page
        ->has('summary.no_time', 1)
        ->where('summary.no_time.0.id', $job->id));

    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->has('noTime', 1)
        ->where('noTime.0.customer', 'Nora Lee'));

    // Another day: not there.
    $this->get(route('calendar', ['date' => CarbonImmutable::parse($this->today)->addDays(2)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->where('noTime', []));

    // In the counters of Not completed jobs and in the morning SMS.
    expect(JobBacklog::summary($this->owner)['counts']['needs_schedule'])->toBe(1)
        ->and(inCompany($this->company, fn () => MorningSms::build($this->company, CarbonImmutable::now())))->toContain('No time today: #'.$job->number.' Nora Lee');
});

test('a job with no day and no time is in the No date group of Today, the calendar and My Jobs', function () {
    $job = emptyJob();

    $this->get(route('today'))->assertInertia(fn (Assert $page) => $page
        ->has('summary.no_date', 1)
        ->where('summary.no_date.0.id', $job->id)
        ->where('summary.no_date.0.customer', 'Unnamed customer'));

    $this->get(route('calendar'))->assertInertia(fn (Assert $page) => $page
        ->has('noDate', 1)
        ->where('noDate.0.id', $job->id));

    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->has('noDate', 1));

    expect(JobBacklog::summary($this->owner)['counts']['needs_schedule'])->toBe(1)
        ->and(inCompany($this->company, fn () => MorningSms::build($this->company, CarbonImmutable::now())))->toContain('1 job with no date: #'.$job->number);

    // Once it has a time it leaves the group.
    $this->post(route('visits.store', $job), ['date' => $this->today, 'start_time' => '23:00', 'end_time' => '23:30'])->assertSessionHasNoErrors();
    $this->get(route('today'))->assertInertia(fn (Assert $page) => $page->has('summary.no_date', 0));
});

test('closed and waiting jobs are not in No date', function () {
    $job = emptyJob();
    $job->forceFill(['status' => JobStatus::WaitingForParts])->save();
    $done = emptyJob();
    $done->forceFill(['status' => JobStatus::Completed])->save();

    $this->get(route('today'))->assertInertia(fn (Assert $page) => $page->has('summary.no_date', 0));
});

test('a technician sees only their own jobs without a time', function () {
    $tech = memberOf($this->company, UserRole::Technician);
    emptyJob();

    $this->actingAs($tech)->get(route('today'))->assertInertia(fn (Assert $page) => $page->has('summary.no_date', 0));
});

test('a job without a time and a day is found by name, by phone in any format and by part of the address', function () {
    $this->post(route('jobs.store'), [
        'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Margaret', 'last_name' => 'Okafor', 'phone' => '604 555 0199', 'property' => ['line1' => '4512 Kingsway', 'city' => 'Burnaby']],
        'description' => 'Dryer not heating',
    ])->assertSessionHasNoErrors();
    $job = inCompany($this->company, fn () => ServiceJob::sole());
    $other = emptyJob();

    $found = function (string $term) {
        $ids = [];
        $this->get(route('jobs.index', ['search' => $term]))->assertInertia(function (Assert $page) use (&$ids) {
            $ids = collect($page->toArray()['props']['jobs']['data'])->pluck('id')->all();
        });

        return $ids;
    };

    foreach (['marg', 'OKAFOR', 'margaret okafor', '6045550199', '(604) 555-0199', '+1 604 555 0199', '1-604-555-0199', '555 01', 'kingsw', 'burnaby', 'heating', 'm', '#'.$job->number] as $term) {
        expect($found($term))->toContain($job->id);
    }
    expect($found('kingsway'))->not->toContain($other->id);

    // The same search in the calendar and in customers.
    $this->get(route('calendar', ['search' => 'okaf']))->assertInertia(fn (Assert $page) => $page
        ->where('searchResults.data.0.id', $job->id));
    $this->get(route('customers.index', ['search' => '604-555-0199']))->assertInertia(fn (Assert $page) => $page
        ->where('customers.data.0.id', $job->customer_id));
});

test('the search finds closed and deleted jobs, the deleted ones marked Deleted', function () {
    $this->post(route('jobs.store'), ['new_customer_mode' => true, 'new_customer' => ['first_name' => 'Zed Quill']])->assertSessionHasNoErrors();
    $job = inCompany($this->company, fn () => ServiceJob::sole());
    $job->forceFill(['status' => JobStatus::Paid])->save();
    $job->delete();

    $this->get(route('jobs.index', ['search' => 'quill']))->assertInertia(fn (Assert $page) => $page
        ->where('jobs.data.0.id', $job->id)
        ->where('jobs.data.0.deleted', true));

    $this->get(route('jobs.index', ['tab' => 'all', 'search' => 'quill']))->assertInertia(fn (Assert $page) => $page
        ->where('jobs.data.0.deleted', true));
});

test('the search by invoice number and appliance model finds the job', function () {
    $job = emptyJob();
    $this->post(route('invoices.store', $job), ['items' => [['description' => 'Repair', 'unit_price' => '10']]])->assertSessionHasNoErrors();
    $number = inCompany($this->company, fn () => Invoice::query()->sole()->number);
    $this->put(route('jobs.update', $job), ['new_appliances' => [['type' => 'washer', 'manufacturer' => 'Whirlpool', 'model_number' => 'WTW5000DW']]])->assertSessionHasNoErrors();

    foreach ([$number, 'wtw5000', 'whirl', 'washer'] as $term) {
        $this->get(route('jobs.index', ['search' => $term]))->assertInertia(fn (Assert $page) => $page->where('jobs.data.0.id', $job->id));
    }
});
