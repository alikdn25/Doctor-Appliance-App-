<?php

use App\Console\Commands\RollTasks;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\AiRequest;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobPart;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\Supplier;
use App\Models\Task;
use App\Services\AI\TaskParser;
use App\Support\Jobs\MorningSms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's task of November 19, part 4: the Jobs / To Do buttons of Today, To Do (types, Home only for its maker,
 * carried over, repeating, "On the way", the microphone, automatic "Buy at {supplier}"), and the Parts page (Order /
 * Waiting, Bought / Ordered / Arrived, the job's status follows, the Parts ready text only after Confirm).
 */
beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201)]);
    Mail::fake();
    // Wednesday morning in Vancouver.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'business_hours' => Company::defaultBusinessHours()]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Owner']);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Mike Tech']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '123 Main St', 'city' => 'Burnaby', 'latitude' => 49.2500000, 'longitude' => -123.0000000]);
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-14 13:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-14 15:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id, 'description' => 'Dryer not heating', 'status' => JobStatus::Scheduled]);
    inCompany($this->company, fn () => SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100']));
    $this->marcone = inCompany($this->company, fn () => Supplier::create(['name' => 'Marcone', 'address' => '4300 Kingsway, Burnaby']));
    config(['ai.key' => '']);
    $this->actingAs($this->owner);
});

function todoOutbound(): int
{
    return inCompany(test()->company, fn () => Message::query()->where('direction', 'outbound')->count());
}

function addPart(array $data = []): JobPart
{
    test()->post(route('jobs.parts.store', test()->job), $data + ['name' => 'Heating element'])->assertRedirect()->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => JobPart::query()->latest('id')->first());
}

function todoJob(): ServiceJob
{
    return test()->job->fresh();
}

// ——— Today: Jobs / To Do ———

test('Jobs / To Do: the choice is kept for the person and both cannot be off', function () {
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('panes', null)->where('canTasks', true));

    $this->put(route('jobs.panes'), ['panes' => 'todo'])->assertRedirect();
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('panes', 'todo'));

    $this->put(route('jobs.panes'), ['panes' => 'both'])->assertRedirect();
    expect($this->owner->membershipFor($this->company)->today_panes)->toBe('both');

    // Neither Jobs nor To Do is not a choice.
    foreach (['none', '', 'off'] as $bad) {
        $this->put(route('jobs.panes'), ['panes' => $bad])->assertSessionHasErrors('panes');
    }
    expect($this->owner->membershipFor($this->company)->today_panes)->toBe('both');

    // Per person: the technician's choice is their own.
    $this->actingAs($this->tech)->put(route('jobs.panes'), ['panes' => 'jobs'])->assertRedirect();
    expect($this->tech->membershipFor($this->company)->today_panes)->toBe('jobs')
        ->and($this->owner->membershipFor($this->company)->today_panes)->toBe('both');
});

// ——— To Do ———

test('a task keeps its type, address, job and repeat; Home is always one\'s own', function () {
    $this->post(route('tasks.store'), [
        'title' => 'Buy heating element', 'task_date' => '2026-10-14', 'all_day' => true,
        'kind' => 'parts', 'address' => '4300 Kingsway, Burnaby', 'service_job_id' => $this->job->id, 'repeat' => 'weekly',
    ])->assertSessionHasNoErrors();

    $task = inCompany($this->company, fn () => Task::query()->sole());
    expect($task)->kind->toBe('parts')->address->toBe('4300 Kingsway, Burnaby')->service_job_id->toBe($this->job->id)->repeat->toBe('weekly');

    // A Home task given to someone else stays the maker's.
    $this->post(route('tasks.store'), ['title' => 'Pick up kids', 'task_date' => '2026-10-14', 'all_day' => true, 'kind' => 'home', 'user_id' => $this->tech->id])
        ->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Task::query()->where('kind', 'home')->sole()->user_id))->toBe($this->owner->id);

    // A job of another company cannot be linked.
    $other = ServiceJob::factory()->create();
    $this->post(route('tasks.store'), ['title' => 'X', 'task_date' => '2026-10-14', 'all_day' => true, 'service_job_id' => $other->id])
        ->assertSessionHasErrors('service_job_id');
});

test('Home tasks are seen only by the one who made them, the Owner included', function () {
    $home = inCompany($this->company, function () {
        $task = new Task(['user_id' => $this->tech->id, 'title' => 'Dentist', 'task_date' => '2026-10-14', 'all_day' => true, 'kind' => 'home']);
        $task->created_by = $this->tech->id;
        $task->save();

        return $task;
    });

    // The Owner sees everyone's tasks in the calendar, but not someone's Home task.
    $this->get(route('calendar', ['view' => 'day', 'date' => '2026-10-14']))
        ->assertInertia(fn (Assert $page) => $page->where('tasks', fn ($tasks) => collect($tasks)->where('id', $home->id)->isEmpty()));
    $this->put(route('tasks.update', $home), ['title' => 'Changed', 'task_date' => '2026-10-14', 'all_day' => true, 'kind' => 'home'])->assertForbidden();
    $this->post(route('tasks.done', $home))->assertForbidden();

    $this->actingAs($this->tech)->get(route('jobs.mine'))
        ->assertInertia(fn (Assert $page) => $page->where('tasks.0.id', $home->id)->where('tasks.0.kind', 'home'));
});

test('undone tasks are carried over to the next day with the number of days', function () {
    [$open, $done] = inCompany($this->company, fn () => [
        Task::create(['user_id' => $this->owner->id, 'title' => 'Call Marcone', 'task_date' => '2026-10-14', 'all_day' => true]),
        Task::create(['user_id' => $this->owner->id, 'title' => 'Done one', 'task_date' => '2026-10-14', 'all_day' => true]),
    ]);
    inCompany($this->company, fn () => $done->forceFill(['completed_at' => now()])->save());

    // The same day: nothing moves.
    $this->artisan('tasks:roll')->assertSuccessful();
    expect($open->fresh()->task_date->format('Y-m-d'))->toBe('2026-10-14');

    // After midnight (company time): the undone task is today's, "carried over 1 day"; the done one stays.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:10', 'America/Vancouver'));
    $this->artisan('tasks:roll')->assertSuccessful();
    expect($open->fresh()->task_date->format('Y-m-d'))->toBe('2026-10-15')
        ->and($open->fresh()->planned_date->format('Y-m-d'))->toBe('2026-10-14')
        ->and($done->fresh()->task_date->format('Y-m-d'))->toBe('2026-10-14');

    $this->travelTo(CarbonImmutable::parse('2026-10-16 08:00', 'America/Vancouver'));
    $this->artisan('tasks:roll')->assertSuccessful();
    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('tasks.0.id', $open->id)->where('tasks.0.carried_days', 2));
});

test('repeating tasks make the next one on schedule, once', function () {
    $weekly = inCompany($this->company, fn () => Task::create(['user_id' => $this->owner->id, 'title' => 'Order filters', 'task_date' => '2026-10-14', 'all_day' => true, 'repeat' => 'weekly']));
    $monthly = inCompany($this->company, fn () => Task::create(['user_id' => $this->owner->id, 'title' => 'Pay rent', 'task_date' => '2026-10-14', 'start_time' => '09:00', 'repeat' => 'monthly']));
    inCompany($this->company, fn () => $weekly->forceFill(['completed_at' => now()])->save());

    // Not yet: nothing is made before the day comes.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'America/Vancouver'));
    $this->artisan('tasks:roll')->assertSuccessful();
    expect(inCompany($this->company, fn () => Task::query()->where('title', 'Order filters')->count()))->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-21 06:00', 'America/Vancouver'));
    $this->artisan('tasks:roll')->assertSuccessful();
    $this->artisan('tasks:roll')->assertSuccessful();
    $next = inCompany($this->company, fn () => Task::query()->where('title', 'Order filters')->whereNull('completed_at')->get());
    expect($next)->toHaveCount(1)
        ->and($next->first()->task_date->format('Y-m-d'))->toBe('2026-10-21')
        ->and($next->first()->repeat)->toBe('weekly');

    $this->travelTo(CarbonImmutable::parse('2026-11-14 06:00', 'America/Vancouver'));
    inCompany($this->company, fn () => RollTasks::roll($this->company, CarbonImmutable::now()));
    $rent = inCompany($this->company, fn () => Task::query()->where('title', 'Pay rent')->orderBy('id')->get());
    expect($rent)->toHaveCount(2)
        ->and($rent->last()->task_date->format('Y-m-d'))->toBe('2026-11-14')
        ->and($rent->last()->start_time)->toBe('09:00')
        // The first one, undone, is carried over to today as well.
        ->and($rent->first()->task_date->format('Y-m-d'))->toBe('2026-11-14');
});

test('a task with an address near a job of that day says "On the way"', function () {
    inCompany($this->company, function () {
        $near = Task::create(['user_id' => $this->owner->id, 'title' => 'Buy belt', 'task_date' => '2026-10-14', 'all_day' => true, 'address' => 'Near']);
        $near->forceFill(['latitude' => 49.2600000, 'longitude' => -123.0000000])->save();
        $far = Task::create(['user_id' => $this->owner->id, 'title' => 'Far away', 'task_date' => '2026-10-14', 'all_day' => true, 'address' => 'Far']);
        $far->forceFill(['latitude' => 49.9000000, 'longitude' => -122.0000000])->save();
    });

    $tasks = collect($this->get(route('jobs.mine'))->viewData('page')['props']['tasks']);
    expect($tasks->firstWhere('title', 'Buy belt')['on_the_way'])->toMatch('/^On the way: near the job at 1:00\\s?p\\.m\\., 1\\.1 km$/u')
        ->and($tasks->firstWhere('title', 'Far away')['on_the_way'])->toBeNull();
});

test('the To Do microphone fills the form by AI_MODEL_BOOKING; nothing is saved', function () {
    $fake = FakeAiTransport::install([
        'title' => 'Buy heating element at Marcone', 'date' => 'завтра', 'time' => '', 'address' => 'Marcone on Kingsway',
        'supplier' => 'Marcone', 'kind' => 'parts',
    ]);
    config(['ai.models.booking' => 'claude-haiku-5-5']);

    $this->postJson(route('tasks.parse'), ['text' => 'завтра купить тэн в Marcone на Kingsway'])
        ->assertOk()
        ->assertExactJson(['task' => [
            'title' => 'Buy heating element at Marcone', 'task_date' => '2026-10-15', 'all_day' => true, 'start_time' => null,
            // The supplier's saved address.
            'address' => '4300 Kingsway, Burnaby', 'kind' => 'parts',
        ]]);

    expect($fake->requests[0]['model'])->toBe('claude-haiku-5-5')
        ->and(inCompany($this->company, fn () => Task::query()->count()))->toBe(0)
        ->and(inCompany($this->company, fn () => AiRequest::query()->where('feature', 'task_parse')->count()))->toBe(1);
});

test('task parser schema has no optional fields', function () {
    $schema = TaskParser::schema();
    expect($schema['required'])->toBe(array_keys($schema['properties']));
});

test('a Parts task marked done offers "Add a receipt?"', function () {
    $task = inCompany($this->company, fn () => Task::create(['user_id' => $this->owner->id, 'title' => 'Buy belt', 'task_date' => '2026-10-14', 'all_day' => true, 'kind' => 'parts']));
    $this->post(route('tasks.done', $task), ['done' => true])->assertRedirect();
    expect(session('inertia.flash_data.offerReceipt'))->toBeTrue();

    session()->forget('inertia.flash_data');
    $call = inCompany($this->company, fn () => Task::create(['user_id' => $this->owner->id, 'title' => 'Call Jane', 'task_date' => '2026-10-14', 'all_day' => true, 'kind' => 'call']));
    $this->post(route('tasks.done', $call), ['done' => true])->assertRedirect();
    expect(session('inertia.flash_data.offerReceipt'))->toBeNull();
});

test('"Buy at {supplier}" is made for today, counts the parts and closes by itself when they are bought', function () {
    $first = addPart(['supplier_id' => $this->marcone->id]);
    $second = addPart(['name' => 'Thermal fuse', 'supplier_id' => $this->marcone->id]);
    addPart(['name' => 'Belt']); // no supplier: no task

    $task = inCompany($this->company, fn () => Task::query()->whereNotNull('supplier_id')->sole());
    expect($task)->title->toBe('Buy at Marcone: 2 parts')
        ->task_date->format('Y-m-d')->toBe('2026-10-14')
        ->address->toBe('4300 Kingsway, Burnaby')
        ->kind->toBe('parts')
        ->user_id->toBe($this->owner->id)
        ->completed_at->toBeNull();

    $this->post(route('parts.bought', $first))->assertRedirect();
    expect($task->fresh()->title)->toBe('Buy at Marcone: 1 part')->and($task->fresh()->completed_at)->toBeNull();

    $this->post(route('parts.ordered', $second))->assertRedirect();
    expect($task->fresh()->completed_at)->not->toBeNull()
        ->and(inCompany($this->company, fn () => Task::query()->whereNotNull('supplier_id')->count()))->toBe(1);
});

test('ticking "Buy at {supplier}" done marks those parts bought; no second task is made', function () {
    $a = addPart(['supplier_id' => $this->marcone->id]);
    $b = addPart(['name' => 'Fuse', 'supplier_id' => $this->marcone->id]);
    $task = inCompany($this->company, fn () => Task::query()->whereNotNull('supplier_id')->sole());

    $this->post(route('tasks.done', $task), ['done' => true])->assertRedirect();

    expect($a->fresh()->status)->toBe(JobPart::ON_HAND)->and($b->fresh()->status)->toBe(JobPart::ON_HAND)
        ->and($task->fresh()->completed_at)->not->toBeNull()
        ->and(inCompany($this->company, fn () => Task::query()->whereNotNull('supplier_id')->count()))->toBe(1)
        ->and(todoJob()->status)->toBe(JobStatus::PartsReady);
});

// ——— Parts ———

test('the Parts page: Order grouped by supplier, Waiting with days, counts on the tabs', function () {
    addPart(['supplier_id' => $this->marcone->id, 'part_number' => 'WP3387747', 'quantity' => 2]);
    addPart(['name' => 'Belt']);
    $waiting = addPart(['name' => 'Door gasket']);
    $this->post(route('parts.ordered', $waiting))->assertRedirect();

    $this->get(route('parts.index'))->assertInertia(fn (Assert $page) => $page
        ->component('parts/index')
        ->where('tab', 'order')
        ->where('counts', ['order' => 2, 'waiting' => 1])
        ->has('parts', 2)
        ->where('parts.0.name', 'Heating element')->where('parts.0.supplier', 'Marcone')->where('parts.0.quantity', 2)
        ->where('parts.0.job.number', $this->job->number)->where('parts.0.job.customer', 'Jane Cooper')
        ->where('suppliers.0.label', 'Marcone'));

    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Vancouver'));
    $this->get(route('parts.index', ['tab' => 'waiting']))->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'waiting')->has('parts', 1)
        ->where('parts.0.ordered_on', '2026-10-14')->where('parts.0.days_waiting', 9)->where('parts.0.long_wait', true));
});

test('number, quantity and supplier are edited in the row; a new number takes "check" off', function () {
    $part = addPart();
    inCompany($this->company, fn () => $part->forceFill(['part_number' => 'AI123', 'number_unverified' => true])->save());

    $this->put(route('parts.update', $part), ['quantity' => 3, 'supplier_id' => $this->marcone->id])->assertRedirect();
    expect($part->fresh())->quantity->toBe(3)->supplier_id->toBe($this->marcone->id)->number_unverified->toBeTrue();

    $this->put(route('parts.update', $part), ['part_number' => 'WP999'])->assertRedirect();
    expect($part->fresh())->part_number->toBe('WP999')->number_unverified->toBeFalse();
});

test('Bought and Arrived take a part off the lists, Ordered moves it to Waiting', function () {
    $bought = addPart(['name' => 'Belt']);
    $ordered = addPart(['name' => 'Pump']);

    $this->post(route('parts.bought', $bought))->assertRedirect();
    expect($bought->fresh()->status)->toBe(JobPart::ON_HAND)->and(session('inertia.flash_data.offerReceipt'))->toBeTrue();

    $this->post(route('parts.ordered', $ordered))->assertRedirect();
    expect($ordered->fresh())->status->toBe(JobPart::ORDERED)->ordered_on->format('Y-m-d')->toBe('2026-10-14');

    $this->get(route('parts.index'))->assertInertia(fn (Assert $page) => $page->has('parts', 0)->where('counts', ['order' => 0, 'waiting' => 1]));

    $this->post(route('parts.arrived', $ordered))->assertRedirect();
    $this->get(route('parts.index', ['tab' => 'waiting']))->assertInertia(fn (Assert $page) => $page->has('parts', 0)->where('counts', ['order' => 0, 'waiting' => 0]));

    // A part already on hand cannot be bought again.
    $this->post(route('parts.bought', $bought))->assertStatus(409);
});

test('the job\'s status follows its parts; Parts ready prepares the text, sent only after Confirm', function () {
    visitOf($this->job)->forceFill(['status' => VisitStatus::Completed])->save();
    $before = todoOutbound();

    $a = addPart(['name' => 'Belt']);
    $b = addPart(['name' => 'Pump']);
    expect(todoJob()->status)->toBe(JobStatus::NeedToOrderParts);

    $this->post(route('parts.ordered', $a))->assertRedirect();
    expect(todoJob()->status)->toBe(JobStatus::NeedToOrderParts);

    $this->post(route('parts.bought', $b))->assertRedirect();
    // Not all on hand: waiting.
    expect(todoJob()->status)->toBe(JobStatus::WaitingForParts);

    $this->post(route('parts.arrived', $a))->assertRedirect();
    expect(todoJob()->status)->toBe(JobStatus::PartsReady)
        ->and(session('inertia.flash_data.partsReadyJob'))->toBe($this->job->id)
        ->and($this->owner->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'parts_ready')->count())->toBe(1)
        // Nothing went to the customer by itself.
        ->and(todoOutbound())->toBe($before);

    $text = $this->getJson(route('jobs.part-arrived', $this->job))->assertOk()->json('text');
    expect($text)->toContain('1) ')->toContain('3) ')->and(todoOutbound())->toBe($before);

    $this->post(route('jobs.part-arrived.send', $this->job), ['text' => $text])->assertRedirect();
    expect(todoOutbound())->toBe($before + 1);

    // A new part needed again: back to Need to order parts.
    addPart(['name' => 'Knob']);
    expect(todoJob()->status)->toBe(JobStatus::NeedToOrderParts);
});

test('"Parts ordered" on the job orders every part still to order', function () {
    addPart(['name' => 'Belt']);
    addPart(['name' => 'Pump']);

    $this->post(route('jobs.parts.ordered', $this->job))->assertRedirect();
    expect(inCompany($this->company, fn () => JobPart::query()->pluck('status')->unique()->all()))->toBe([JobPart::ORDERED])
        ->and(todoJob()->status)->toBe(JobStatus::WaitingForParts);
});

test('a job on site or closed keeps its status when parts change', function () {
    inCompany($this->company, fn () => $this->job->forceFill(['status' => JobStatus::InProgress])->save());
    addPart();
    expect(todoJob()->status)->toBe(JobStatus::InProgress);
});

test('the parts counters of the bar and More open the Parts page; the job page lists the parts', function () {
    addPart(['supplier_id' => $this->marcone->id]);

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('job.parts.0.name', 'Heating element')->where('job.parts.0.supplier', 'Marcone')
        ->where('suppliers.0.label', 'Marcone'));
});

test('suppliers are kept in the settings; another company cannot see or touch parts and suppliers', function () {
    $this->post(route('company.suppliers.store'), ['name' => 'Reliable Parts', 'address' => '123 Fraser St'])->assertSessionHasNoErrors();
    $this->get(route('company.suppliers.index'))->assertInertia(fn (Assert $page) => $page->has('suppliers', 2));

    $part = addPart();
    $stranger = memberOf(Company::factory()->create());
    $this->actingAs($stranger);
    $this->get(route('parts.index'))->assertInertia(fn (Assert $page) => $page->has('parts', 0)->where('counts', ['order' => 0, 'waiting' => 0])->has('suppliers', 0));
    $this->post(route('parts.bought', $part))->assertNotFound();
    $this->put(route('parts.update', $part), ['quantity' => 5])->assertNotFound();
    $this->put(route('company.suppliers.update', $this->marcone), ['name' => 'Hacked'])->assertNotFound();
    $this->post(route('jobs.parts.store', $this->job), ['name' => 'X'])->assertNotFound();
    expect($part->fresh()->status)->toBe(JobPart::TO_ORDER)->and($this->marcone->fresh()->name)->toBe('Marcone');

    // A technician cannot manage suppliers.
    $this->actingAs($this->tech)->post(route('company.suppliers.store'), ['name' => 'Nope'])->assertForbidden();
});

// ——— Morning SMS ———

test('the morning SMS lists the day\'s tasks and the parts to buy and to receive', function () {
    inCompany($this->company, function () {
        Task::create(['user_id' => $this->owner->id, 'title' => 'Call the bank', 'task_date' => '2026-10-15', 'all_day' => true]);
        Task::create(['user_id' => $this->owner->id, 'title' => 'Pick up gasket', 'task_date' => '2026-10-15', 'start_time' => '08:00']);
        // Someone else's task is not in the owner's SMS.
        Task::create(['user_id' => $this->tech->id, 'title' => 'Tech only', 'task_date' => '2026-10-15', 'all_day' => true]);
    });
    addPart(['supplier_id' => $this->marcone->id]);
    $waiting = addPart(['name' => 'Door gasket']);
    $this->post(route('parts.ordered', $waiting))->assertRedirect();

    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:30', 'America/Vancouver'));
    $this->artisan('tasks:roll')->assertSuccessful();
    $text = inCompany($this->company, fn () => MorningSms::build($this->company->fresh(), CarbonImmutable::now()));

    expect($text)->toContain('To do: Call the bank')->toContain('Pick up gasket')->toMatch('/8:00\\s?a\\.m\\.\\s+Pick up gasket/u')
        ->toContain('Buy at Marcone')
        ->not->toContain('Tech only')
        ->toContain('1 part to buy: Marcone: Heating element')
        ->toContain('1 part to receive: #'.$this->job->number.' Door gasket');
});

/** The job's visit (the beforeEach one). */
function visitOf(ServiceJob $job): JobVisit
{
    return inCompany(test()->company, fn () => JobVisit::query()->where('service_job_id', $job->id)->sole());
}
