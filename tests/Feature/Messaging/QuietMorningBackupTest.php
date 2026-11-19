<?php

use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Messaging\Messenger;
use App\Messaging\SmsThreads;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DriveBackup;
use App\Models\JobVisit;
use App\Models\JobPart;
use App\Models\Message;
use App\Models\Property;
use App\Models\PushAction;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\SmsThread;
use App\Models\User;
use App\Support\Backups\DriveBackups;
use App\Support\Jobs\MorningSms;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's task of November 19, part 1: "I did not answer" and "customer is quiet" reminders, the morning SMS to the
 * owner's own phone, the nightly Google Drive backup, buttons on push notifications and the small fixes.
 */
beforeEach(function () {
    $this->googleToken = null;
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201),
        'https://oauth2.googleapis.com/token' => fn () => test()->googleToken ?? Http::response(['access_token' => 'drive-access']),
        'https://www.googleapis.com/drive/v3/files/folder-1*' => Http::response(['id' => 'folder-1', 'trashed' => false]),
        'https://www.googleapis.com/drive/v3/files' => Http::response(['id' => 'folder-1']),
        'https://www.googleapis.com/upload/drive/v3/files*' => Http::sequence()->whenEmpty(Http::response(['id' => 'file-'.uniqid()])),
        'https://www.googleapis.com/drive/v3/files/*' => Http::response([], 204),
    ]);
    Mail::fake();
    config(['ai.key' => '']);

    // Wednesday noon in Vancouver; default business hours Mon–Fri 08:00–17:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create([
        'name' => 'Doctor Appliance', 'sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'country' => 'CA', 'currency' => 'CAD',
        'business_hours' => Company::defaultBusinessHours(),
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Tom Fixer']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '12 Main St', 'city' => 'Surrey']);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
});

/** An incoming text through the provider's webhook (signed like Twilio). */
function textIn(string $body, string $from = '+16045550142'): Message
{
    $url = route('webhooks.sms.inbound', 'twilio');
    $params = ['AccountSid' => 'ACsub', 'From' => $from, 'To' => '+16045550100', 'Body' => $body, 'MessageSid' => 'SM'.uniqid()];
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }
    test()->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $data, 'sub-token', true)))->post($url, $params)->assertOk();

    return inCompany(test()->company, fn () => Message::query()->where('direction', 'inbound')->latest('id')->first());
}

/** We text the customer from the chat (a person). */
function textOut(string $body, ?User $user = null): Message
{
    test()->actingAs($user ?? test()->owner)->post(route('messages.send'), ['phone' => '+16045550142', 'body' => $body])->assertRedirect();

    return inCompany(test()->company, fn () => Message::query()->where('direction', 'outbound')->latest('id')->first());
}

function localAt(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'America/Vancouver'));
}

function runSweep(): array
{
    return app(SmsThreads::class)->sweep();
}

function alerts(User $user, string $kind): int
{
    return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind)->count();
}

/** SMS sent through the provider to a number. */
function smsTo(string $number): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => str_contains($r->url(), 'Messages.json') && ($r->data()['To'] ?? null) === $number)
        ->map(fn (HttpRequest $r) => $r->data()['Body'])->values()->all();
}

// ——— Fixes ———

test('Edit by voice is on My Jobs for everyone who may edit the job, also when working alone', function () {
    $this->company->update(['works_alone' => true]);
    ServiceJob::factory()->for($this->property)->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-14 15:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-14 17:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);

    $this->actingAs($this->owner)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.can_edit', true));

    $tech = memberOf($this->company, UserRole::Technician);
    $this->company->update(['works_alone' => false]);
    $techJob = ServiceJob::factory()->for($this->property)->withVisit($tech, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-14 15:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-14 17:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    $canEdit = inCompany($this->company, fn () => $tech->can('update', $techJob));
    $this->actingAs($tech)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('visits.0.can_edit', $canEdit));
});

test('the morning push and the morning SMS run every minute, so they come at their exact time', function () {
    $events = collect(app(Schedule::class)->events());
    foreach (['summary:morning', 'summary:morning-sms', 'push:resend'] as $command) {
        $event = $events->first(fn ($e) => str_contains($e->command, $command));
        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
    }
});

// ——— I did not answer ———

test('an unanswered text: a reminder after 2 hours, again every 2 hours, at most 3, with buttons; Done stops them', function () {
    expect($this->company->fresh()->sms_reminder_hours)->toBe(2);
    textIn('Can you call me?');

    localAt('2026-10-14 13:59');
    runSweep();
    expect(alerts($this->owner, 'sms_unanswered'))->toBe(0);

    localAt('2026-10-14 14:00');
    runSweep();
    expect(alerts($this->owner, 'sms_unanswered'))->toBe(1);
    $alert = $this->owner->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'sms_unanswered');
    expect(collect($alert->data['actions'])->pluck('action')->all())->toBe(['draft', 'later']);

    localAt('2026-10-14 16:00');
    runSweep();
    localAt('2026-10-15 08:00');
    runSweep();
    localAt('2026-10-15 10:00');
    runSweep();
    expect(alerts($this->owner, 'sms_unanswered'))->toBe(3);

    // Done (a new text) stops them.
    textIn('Hello again?');
    $this->actingAs($this->owner)->post(route('messages.done'), ['phone' => '+16045550142'])->assertRedirect();
    localAt('2026-10-15 13:00');
    runSweep();
    expect(alerts($this->owner, 'sms_unanswered'))->toBe(3);
});

// ——— Customer is quiet ———

test('customer is quiet: one reminder 2 hours after our text, with Text again, Call and Not waiting', function () {
    $ours = textOut('Can we come Thursday 10-12?');

    localAt('2026-10-14 13:59');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(0);

    localAt('2026-10-14 14:00');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(1);
    $alert = $this->owner->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'sms_quiet');
    expect($alert->data['title'])->toBe('Jane Cooper has not answered for 2 hours')
        ->and($alert->data['body'])->toContain('Can we come Thursday')
        ->and(collect($alert->data['actions'])->pluck('action')->all())->toBe(['again', 'call', 'not_waiting']);

    // Only one reminder.
    localAt('2026-10-14 16:30');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(1);
    expect(inCompany($this->company, fn () => SmsThread::query()->sole()->silent_message_id))->toBe($ours->id);
});

test('customer is quiet does not start on auto replies, On my way, review requests or texts marked Not waiting', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    inCompany($this->company, function () use ($job) {
        $messenger = app(Messenger::class);
        $messenger->sms(MessageKind::AutoReply, $this->customer, null, 'Thanks, we will get back to you');
        $messenger->sms(MessageKind::OnMyWay, $this->customer, $job, 'On my way', $this->owner);
        $messenger->sms(MessageKind::OnMyWayEta, $this->customer, $job, 'On my way, 20 min', $this->owner);
        $messenger->sms(MessageKind::ReviewRequest, $this->customer, $job, 'Leave us a review', $this->owner);
    });

    localAt('2026-10-14 15:00');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(0);

    // "Not waiting for a reply" in the chat.
    $ours = textOut('Your part is ordered.');
    $this->actingAs($this->owner)->post(route('messages.not-waiting', $ours))->assertRedirect();
    expect($ours->fresh()->no_reply_expected)->toBeTrue();
    localAt('2026-10-14 17:30');
    runSweep();
    localAt('2026-10-15 09:00');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(0);
});

test('customer is quiet ends when the customer answers', function () {
    textOut('Can we come Thursday 10-12?');
    localAt('2026-10-14 13:00');
    textIn('Yes, Thursday works');

    localAt('2026-10-14 15:00');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(0)
        ->and(inCompany($this->company, fn () => SmsThread::query()->sole()->silent_since))->toBeNull();
});

test('reminders due outside business hours come once at the start of the next business day', function () {
    localAt('2026-10-14 16:00');
    textOut('Can we come Friday?');

    // 18:00 is after closing.
    localAt('2026-10-14 18:00');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(0);
    localAt('2026-10-15 07:59');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(0);
    localAt('2026-10-15 08:00');
    runSweep();
    expect(alerts($this->owner, 'sms_quiet'))->toBe(1);
});

test('a customer still quiet is a line in the next morning SMS, once', function () {
    textOut('Can we come Thursday 10-12?');
    localAt('2026-10-14 14:00');
    runSweep();

    localAt('2026-10-15 06:30');
    $text = inCompany($this->company, fn () => MorningSms::build($this->company->fresh(), CarbonImmutable::now(), markReported: true));
    expect($text)->toContain('Quiet customers: Jane Cooper');
    $again = inCompany($this->company, fn () => MorningSms::build($this->company->fresh(), CarbonImmutable::now(), markReported: true));
    expect($again)->not->toContain('Quiet customers');
});

test('Text again opens the chat with a short AI follow-up in the box; nothing is sent', function () {
    textOut('Can we come Thursday 10-12?');
    $ai = FakeAiTransport::install(['text' => 'Hi Jane, just checking you saw our message about Thursday?']);
    $sent = Message::withoutCompanyScope()->where('direction', 'outbound')->count();

    $this->actingAs($this->owner)->get(route('messages.index', ['phone' => '+16045550142', 'nudge' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.draft', 'Hi Jane, just checking you saw our message about Thursday?'));

    expect($ai->requests)->toHaveCount(1)
        ->and(Message::withoutCompanyScope()->where('direction', 'outbound')->count())->toBe($sent);
});

// ——— Buttons on push notifications ———

test('an incoming text gives the office a push with the AI line and the main action; a tap does it', function () {
    $job = ServiceJob::factory()->for($this->property)->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-16 09:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-16 11:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    FakeAiTransport::install(fn () => [
        'urgency' => 'normal', 'summary' => 'Wants the visit moved to Thursday morning',
        'actions' => [['type' => 'reschedule', 'job_number' => (string) $job->number, 'date' => '2026-10-15', 'arrival_start' => '10:00', 'arrival_end' => '12:00', 'arrival_now' => false]],
        'reply_draft' => 'Sure, Thursday 10-12 works.',
    ]);

    textIn('Can you come Thursday 10-12 instead?');

    $alert = $this->owner->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'sms_incoming');
    expect($alert->data['title'])->toBe('Jane Cooper: Wants the visit moved to Thursday morning')
        ->and($alert->data['body'])->toContain('Tap to:')
        ->and(collect($alert->data['actions'])->pluck('action')->all())->toBe(['main', 'draft', 'later'])
        ->and($alert->data['actions'][0]['title'])->toBe('Move to Thu 10:00–12:00');

    // Nothing moved before the tap.
    $visit = inCompany($this->company, fn () => JobVisit::query()->sole());
    expect(CarbonImmutable::instance($visit->scheduled_start)->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-16 09:00');

    $url = str_replace('__action__', 'main', $alert->data['action_url']);
    $this->actingAs($this->owner)->postJson($url)->assertOk()->assertJsonPath('message', 'Job #'.$job->number.' moved.');
    $visit->refresh();
    expect(CarbonImmutable::instance($visit->scheduled_start)->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-15 10:00');

    // Once only; another person cannot use the token.
    $this->postJson($url)->assertOk()->assertJsonPath('message', 'This was already done.');
    $this->actingAs(memberOf($this->company, UserRole::Admin))->postJson($url)->assertNotFound();
    // The customer was not texted.
    expect(smsTo('+16045550142'))->toBe([]);
});

test('Book from the push books a known customer at once and sends the usual booking confirmation', function () {
    FakeAiTransport::install(fn () => [
        'urgency' => 'normal', 'summary' => 'Wants a dryer repair Thursday',
        'actions' => [['type' => 'book', 'job_number' => '', 'date' => '2026-10-15', 'arrival_start' => '10:00', 'arrival_end' => '12:00', 'arrival_now' => false]],
        'reply_draft' => '',
    ]);
    textIn('My dryer is not heating, can you come Thursday 10-12?');
    $alert = $this->owner->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'sms_incoming');
    expect($alert->data['actions'][0]['title'])->toBe('Book Thu 10:00–12:00')
        ->and($alert->data['body'])->toContain('book Jane Cooper for Thu 10:00–12:00 at 12 Main St');
    expect(inCompany($this->company, fn () => ServiceJob::query()->count()))->toBe(0);

    $this->actingAs($this->owner)->postJson(str_replace('__action__', 'main', $alert->data['action_url']))->assertOk();
    $job = inCompany($this->company, fn () => ServiceJob::query()->with('visits')->sole());
    expect($job->customer_id)->toBe($this->customer->id)->and($job->property_id)->toBe($this->property->id)
        ->and(CarbonImmutable::instance($job->visits->first()->scheduled_start)->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-15 10:00');
});

test('Later brings the same notification back an hour later', function () {
    textIn('Hi, are you open Saturday?');
    $alert = $this->owner->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'sms_incoming');
    expect(collect($alert->data['actions'])->pluck('action')->all())->toBe(['draft', 'later']);

    $this->actingAs($this->owner)->postJson(str_replace('__action__', 'later', $alert->data['action_url']))->assertOk();
    Artisan::call('push:resend');
    expect(alerts($this->owner, 'sms_incoming'))->toBe(1);

    $this->travel(61)->minutes();
    Artisan::call('push:resend');
    expect(alerts($this->owner, 'sms_incoming'))->toBe(2);
    Artisan::call('push:resend');
    expect(alerts($this->owner, 'sms_incoming'))->toBe(2);
});

test('Not waiting from the quiet reminder stops the wait', function () {
    $ours = textOut('Can we come Thursday?');
    localAt('2026-10-14 14:00');
    runSweep();
    $alert = $this->owner->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'sms_quiet');

    $this->actingAs($this->owner)->postJson(str_replace('__action__', 'not_waiting', $alert->data['action_url']))
        ->assertOk()->assertJsonPath('message', 'OK, not waiting for a reply.');
    expect($ours->fresh()->no_reply_expected)->toBeTrue()
        ->and(inCompany($this->company, fn () => SmsThread::query()->sole()->silent_since))->toBeNull();
});

test('push action tokens of another company do nothing', function () {
    $other = Company::factory()->create();
    $stranger = memberOf($other, UserRole::Owner);
    $action = inCompany($this->company, fn () => PushAction::query()->create([
        'user_id' => $this->owner->id, 'token' => str_repeat('a', 48), 'kind' => 'x', 'title' => 't', 'body' => 'b',
        'actions' => ['later' => ['title' => 'Later']], 'expires_at' => now()->addDay(),
    ]));

    $this->actingAs($stranger)->postJson(route('push-actions.run', [$action->token, 'later']))->assertNotFound();
    expect($action->fresh()->resend_at)->toBeNull();
});

// ——— Morning SMS ———

function morningJob(): ServiceJob
{
    $job = ServiceJob::factory()->for(test()->property)->withVisit(test()->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-15 09:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-15 11:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => test()->brand->id, 'status' => JobStatus::Scheduled, 'description' => 'Washer not draining']);

    return $job;
}

test('the morning SMS goes to the owner\'s own phone at the set time, once, and replaces the owner\'s morning push', function () {
    morningJob();
    $this->company->update(['morning_sms_enabled' => true, 'morning_sms_phone' => '+16045550199', 'morning_sms_time' => '06:30']);

    localAt('2026-10-15 06:29');
    Artisan::call('summary:morning-sms');
    Artisan::call('summary:morning');
    expect(smsTo('+16045550199'))->toBe([]);

    localAt('2026-10-15 06:30');
    Artisan::call('summary:morning-sms');
    $sms = smsTo('+16045550199');
    expect($sms)->toHaveCount(1)
        ->and($sms[0])->toContain('1. 9:00')->toContain('Jane Cooper')->toContain('(604) 555-0142')->toContain('12 Main St')->toContain('Washer not draining');

    localAt('2026-10-15 07:00');
    Artisan::call('summary:morning-sms');
    Artisan::call('summary:morning');
    expect(smsTo('+16045550199'))->toHaveCount(1)
        ->and(alerts($this->owner, 'morning_summary'))->toBe(0);
    // Not stored in any customer chat.
    expect(inCompany($this->company, fn () => Message::query()->where('to', '+16045550199')->count()))->toBe(0);
});

test('the morning SMS lists the day: no address, unanswered texts, unpaid, completed without invoice, parts', function () {
    $noAddress = Property::factory()->for($this->customer)->create(['line1' => null, 'city' => null, 'is_primary' => false]);
    ServiceJob::factory()->for($noAddress)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::New]);
    ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Completed]);
    $waiting = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::WaitingForParts]);
    inCompany($this->company, fn () => JobPart::query()->create(['service_job_id' => $waiting->id, 'name' => 'Drain pump', 'status' => JobPart::ORDERED, 'ordered_on' => '2026-10-10']));
    textIn('Is anyone there?');

    localAt('2026-10-15 06:30');
    $text = inCompany($this->company, fn () => MorningSms::build($this->company->fresh(), CarbonImmutable::now()));
    expect($text)->toContain('No jobs today.')
        ->toContain('No address: #')
        ->toContain('1 unanswered text: Jane Cooper')
        ->toContain('1 completed job without an invoice')
        ->toContain('1 part to receive: #')->toContain('Drain pump')
        ->not->toContain('Backup failed');
});

test('the morning SMS says "Backup failed" when the night\'s Drive backup did not go through', function () {
    $this->company->forceFill(['google_drive_token' => 'refresh-1'])->save();
    inCompany($this->company, fn () => DriveBackup::query()->create(['status' => DriveBackup::FAILED, 'error' => 'quota']));

    localAt('2026-10-15 06:30');
    inCompany($this->company, fn () => DriveBackup::query()->create(['status' => DriveBackup::FAILED, 'error' => 'quota']));
    expect(inCompany($this->company, fn () => MorningSms::build($this->company->fresh(), CarbonImmutable::now())))->toContain('Backup failed');

    inCompany($this->company, fn () => DriveBackup::query()->create(['status' => DriveBackup::OK, 'files' => []]));
    expect(inCompany($this->company, fn () => MorningSms::build($this->company->fresh(), CarbonImmutable::now())))->not->toContain('Backup failed');
});

test('the Owner sets the morning SMS: number, time, on/off', function () {
    $this->actingAs($this->owner)->get(route('company.morning.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('company/morning')->where('settings.morning_sms_enabled', false)->where('settings.morning_sms_time', '06:30')
        ->where('drive.available', false)->where('folder', 'Doctor Appliance Backup'));

    $this->put(route('company.morning.update'), ['morning_sms_enabled' => true, 'morning_sms_phone' => '', 'morning_sms_time' => '06:30'])
        ->assertSessionHasErrors('morning_sms_phone');
    $this->put(route('company.morning.update'), ['morning_sms_enabled' => true, 'morning_sms_phone' => '604 555 0199', 'morning_sms_time' => '06:45'])
        ->assertRedirect();
    expect($this->company->fresh())->morning_sms_enabled->toBeTrue()->morning_sms_phone->toBe('+16045550199')->morning_sms_time->toBe('06:45');

    $this->actingAs(memberOf($this->company, UserRole::Admin))->get(route('company.morning.edit'))->assertForbidden();
});

// ——— Google Drive backup ———

test('the nightly backup uploads two CSV files at 3:00 into the app folder and keeps at most 30 copies', function () {
    config(['services.google_drive' => ['client_id' => 'cid', 'client_secret' => 'secret']]);
    $this->company->forceFill(['google_drive_token' => 'refresh-1', 'google_drive_folder_id' => 'folder-1'])->save();
    morningJob();
    inCompany($this->company, function () {
        foreach (range(1, 30) as $n) {
            DriveBackup::query()->create(['status' => DriveBackup::OK, 'files' => [['id' => "old-{$n}a", 'name' => 'a.csv'], ['id' => "old-{$n}b", 'name' => 'b.csv']]]);
        }
    });

    localAt('2026-10-15 02:59');
    Artisan::call('backup:drive');
    expect(inCompany($this->company, fn () => DriveBackup::query()->count()))->toBe(30);

    localAt('2026-10-15 03:00');
    Artisan::call('backup:drive');
    Artisan::call('backup:drive');

    $uploads = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'upload/drive'));
    expect($uploads)->toHaveCount(2)
        ->and($uploads->first()->body())->toContain('customers-2026-10-15.csv')->toContain('Jane Cooper')->toContain('folder-1')
        ->and($uploads->last()->body())->toContain('jobs-next-7-days-2026-10-15.csv')->toContain('Washer not draining');

    expect(inCompany($this->company, fn () => DriveBackup::query()->count()))->toBe(30);
    $deleted = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => $r->method() === 'DELETE');
    expect($deleted->map(fn (HttpRequest $r) => basename($r->url()))->values()->all())->toBe(['old-1a', 'old-1b']);
});

test('a failed backup notifies the Owner and is kept as failed', function () {
    config(['services.google_drive' => ['client_id' => 'cid', 'client_secret' => 'secret']]);
    $this->googleToken = Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been revoked.'], 400);
    $this->company->forceFill(['google_drive_token' => 'refresh-1'])->save();

    localAt('2026-10-15 03:05');
    Artisan::call('backup:drive');

    expect(inCompany($this->company, fn () => DriveBackup::query()->sole()))->status->toBe('failed')->error->toContain('revoked')
        ->and(alerts($this->owner, 'drive_backup_failed'))->toBe(1);
});

test('CSV cells that look like formulas are made safe', function () {
    expect(DriveBackups::csv([['=HYPERLINK("x")', 'Jane']]))->toContain("'=HYPERLINK");
});

test('Connect Google Drive asks only for the files the app creates; the callback saves the account', function () {
    config(['services.google_drive' => ['client_id' => 'cid', 'client_secret' => 'secret']]);
    $this->actingAs($this->owner)->post(route('google-drive.connect'))->assertRedirectContains('drive.file');
    $state = session('google_drive_state')['state'];

    $payload = rtrim(strtr(base64_encode(json_encode(['email' => 'owner@example.com'])), '+/', '-_'), '=');
    $this->googleToken = Http::response(['refresh_token' => 'r-1', 'access_token' => 'a', 'id_token' => "h.{$payload}.s"]);
    $this->get(route('google-drive.callback', ['state' => 'wrong', 'code' => 'c']))->assertForbidden();

    $this->withSession(['google_drive_state' => ['state' => $state, 'company' => $this->company->id]])
        ->get(route('google-drive.callback', ['state' => $state, 'code' => 'c']))->assertRedirect(route('company.morning.edit'));
    expect($this->company->fresh())->google_drive_token->toBe('r-1')->google_drive_email->toBe('owner@example.com');
});

// ——— Home-screen shortcuts ———

test('the manifest has the By voice and Quick booking shortcuts with the microphone on', function () {
    $shortcuts = collect($this->get('/manifest.webmanifest')->json('shortcuts'));
    expect($shortcuts->pluck('url')->all())->toBe(['/assistant?mic=1', '/jobs/quick-booking?mic=1'])
        ->and($shortcuts->pluck('name')->all())->toBe(['By voice', 'Quick booking']);
});
