<?php

use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Messaging\SmsThreads;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\SmsThread;
use App\Models\User;
use App\Support\Locale\BusinessHours;
use App\Support\Locale\RelativeDate;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's task of November 18, part 1: the AI reads incoming texts (actions only after Confirm), reminders about
 * unanswered texts, auto replies, "Done", the new-message badge on job cards and "Possible warranty".
 */
const SMS_API = 'https://api.twilio.com';

beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => SMS_API, 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake([SMS_API.'/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201)]);
    Mail::fake();

    // Wednesday noon in Vancouver; default business hours Mon–Fri 08:00–17:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'business_hours' => Company::defaultBusinessHours()]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Tom Fixer', 'phone' => '604-555-0177']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-16 09:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-16 11:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
    // No AI unless a test installs one.
    config(['ai.key' => '']);
});

/** An incoming text through the provider's webhook (signed like Twilio). */
function incomingSms(string $body, string $from = '+16045550142', array $extra = []): Message
{
    $url = route('webhooks.sms.inbound', 'twilio');
    $params = ['AccountSid' => 'ACsub', 'From' => $from, 'To' => '+16045550100', 'Body' => $body, 'MessageSid' => 'SM'.uniqid(), ...$extra];
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }
    test()->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $data, 'sub-token', true)))->post($url, $params)->assertOk();

    return inCompany(test()->company, fn () => Message::query()->where('direction', 'inbound')->latest('id')->first());
}

function at(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'America/Vancouver'));
}

function sweep(): array
{
    return app(SmsThreads::class)->sweep();
}

function autoReplies(): int
{
    return inCompany(test()->company, fn () => Message::query()->where('kind', MessageKind::AutoReply)->count());
}

function reminders(User $user): int
{
    return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'sms_unanswered')->count();
}

function enableAutoReply(int $delay = 15): void
{
    test()->company->update(['auto_reply_enabled' => true, 'auto_reply_delay_minutes' => $delay, 'auto_reply_text' => 'Thanks, we will reply soon!']);
}

// ——— Auto reply ———

test('the auto reply goes after the delay, not before', function () {
    enableAutoReply(15);
    incomingSms('Is my repair still on Friday?');

    at('2026-10-14 12:14');
    sweep();
    expect(autoReplies())->toBe(0);

    at('2026-10-14 12:15');
    sweep();
    expect(autoReplies())->toBe(1);
    $reply = inCompany($this->company, fn () => Message::query()->where('kind', MessageKind::AutoReply)->sole());
    expect($reply->body)->toBe('Thanks, we will reply soon!')->and($reply->to)->toBe('+16045550142')->and($reply->user_id)->toBeNull();
});

test('the auto reply is not sent when a person replied within the delay', function () {
    enableAutoReply(15);
    incomingSms('Hello?');

    at('2026-10-14 12:05');
    $this->actingAs($this->owner)->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'Hi Jane, yes!'])->assertRedirect();

    at('2026-10-14 12:30');
    sweep();
    expect(autoReplies())->toBe(0);
});

test('one auto reply per 24 hours to a number', function () {
    enableAutoReply(0);
    incomingSms('First');
    expect(autoReplies())->toBe(1);

    at('2026-10-14 13:00');
    incomingSms('Second');
    sweep();
    expect(autoReplies())->toBe(1);

    // A day later the next one goes.
    at('2026-10-15 12:01');
    incomingSms('Third');
    expect(autoReplies())->toBe(2);
});

test('no auto reply outside business hours', function () {
    enableAutoReply(5);
    at('2026-10-14 20:00');
    incomingSms('Evening question');
    at('2026-10-14 20:10');
    sweep();
    // Nor the next morning: a text from the night is not auto-answered.
    at('2026-10-15 08:30');
    sweep();
    expect(autoReplies())->toBe(0);

    // Inside the hours, but the delay ends after closing: not sent either.
    at('2026-10-15 16:50');
    incomingSms('Late afternoon');
    at('2026-10-15 17:06');
    sweep();
    expect(autoReplies())->toBe(0);
});

test('staff numbers never get auto replies', function () {
    enableAutoReply(0);
    incomingSms('Running late, boss', '+16045550177');

    expect(autoReplies())->toBe(0);
});

test('the missed-call text follows the same rules and is off without Telnyx voice', function () {
    $this->company->update(['missed_call_reply_enabled' => true, 'missed_call_reply_text' => 'Sorry we missed you!']);

    expect(inCompany($this->company, fn () => app(SmsThreads::class)->missedCall('+16045550142')))->toBeNull();

    config(['sms.missed_calls.enabled' => true]);
    inCompany($this->company, fn () => app(SmsThreads::class)->missedCall('+16045550142'));
    inCompany($this->company, fn () => app(SmsThreads::class)->missedCall('+16045550142'));
    inCompany($this->company, fn () => app(SmsThreads::class)->missedCall('+16045550177'));
    expect(autoReplies())->toBe(1);

    at('2026-10-14 19:00');
    inCompany($this->company, fn () => app(SmsThreads::class)->missedCall('+16045550199'));
    expect(autoReplies())->toBe(1);
});

// ——— Reminders ———

test('reminders every 3 hours, only in business hours, at most 3', function () {
    incomingSms('Can you call me?');

    at('2026-10-14 14:59');
    sweep();
    expect(reminders($this->owner))->toBe(0);

    at('2026-10-14 15:00');
    sweep();
    expect(reminders($this->owner))->toBe(1);
    $alert = $this->owner->notifications()->first();
    expect($alert->data['title'])->toContain('Jane Cooper')->and($alert->data['url'])->toContain('phone=%2B16045550142');

    // 18:00 is after closing: the next one waits for Thursday 08:00.
    at('2026-10-14 18:00');
    sweep();
    expect(reminders($this->owner))->toBe(1);
    at('2026-10-15 08:00');
    sweep();
    expect(reminders($this->owner))->toBe(2);

    at('2026-10-15 11:00');
    sweep();
    expect(reminders($this->owner))->toBe(3);

    at('2026-10-15 14:00');
    sweep();
    at('2026-10-15 16:30');
    sweep();
    expect(reminders($this->owner))->toBe(3);
});

test('the interval comes from the settings', function () {
    $this->company->update(['sms_reminder_hours' => 1]);
    incomingSms('Hello');

    at('2026-10-14 13:00');
    sweep();
    expect(reminders($this->owner))->toBe(1);
});

test('a text from the night gets one reminder when the company opens', function () {
    at('2026-10-14 22:30');
    incomingSms('Fridge stopped!');

    at('2026-10-15 07:59');
    sweep();
    expect(reminders($this->owner))->toBe(0);

    at('2026-10-15 08:00');
    sweep();
    expect(reminders($this->owner))->toBe(1);

    at('2026-10-15 16:00');
    sweep();
    expect(reminders($this->owner))->toBe(1);
});

test('Done stops the reminders without a reply', function () {
    incomingSms('ok thanks');

    $this->actingAs($this->owner)->post(route('messages.done'), ['phone' => '+16045550142'])->assertRedirect();
    expect(inCompany($this->company, fn () => SmsThread::query()->sole()->done_at))->not->toBeNull();

    at('2026-10-14 15:00');
    sweep();
    expect(reminders($this->owner))->toBe(0);
    // Nothing was sent to the customer.
    expect(inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->count()))->toBe(0);
});

test('a reply also stops the reminders; technicians get none', function () {
    $tech = memberOf($this->company, UserRole::Technician);
    incomingSms('When?');
    $this->actingAs($this->owner)->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'Friday 9-11'])->assertRedirect();

    at('2026-10-14 15:00');
    sweep();
    expect(reminders($this->owner))->toBe(0)->and(reminders($tech))->toBe(0);
});

test('business hours and next opening', function () {
    $company = $this->company;
    expect(BusinessHours::isOpen($company, CarbonImmutable::parse('2026-10-14 08:00', 'America/Vancouver')))->toBeTrue()
        ->and(BusinessHours::isOpen($company, CarbonImmutable::parse('2026-10-14 17:00', 'America/Vancouver')))->toBeFalse()
        ->and(BusinessHours::isOpen($company, CarbonImmutable::parse('2026-10-17 10:00', 'America/Vancouver')))->toBeFalse()
        // Friday evening → Monday 08:00.
        ->and(BusinessHours::nextOpen($company, CarbonImmutable::parse('2026-10-16 18:00', 'America/Vancouver'))->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-19 08:00');
});

// ——— New-message badge ———

test('the new-message badge shows on the job card and goes away when the chat is read', function () {
    $message = incomingSms('Can you come earlier?');
    expect($message->service_job_id)->toBe($this->job->id);

    $this->actingAs($this->owner)->get(route('jobs.mine', ['date' => '2026-10-16']))
        ->assertInertia(fn (Assert $page) => $page->where('visits.0.job.new_messages', 1));
    $this->get(route('calendar', ['date' => '2026-10-16']))
        ->assertInertia(fn (Assert $page) => $page->where('visits.0.job.new_messages', 1));

    $this->post(route('messages.read'), ['phone' => '+16045550142', 'message_ids' => [$message->id]])->assertRedirect();

    $this->get(route('jobs.mine', ['date' => '2026-10-16']))
        ->assertInertia(fn (Assert $page) => $page->where('visits.0.job.new_messages', 0));
    $this->get(route('calendar', ['date' => '2026-10-16']))
        ->assertInertia(fn (Assert $page) => $page->where('visits.0.job.new_messages', 0));
});

test('a finished job shows no badge', function () {
    $this->job->forceFill(['status' => JobStatus::Completed, 'completed_at' => now()])->save();
    incomingSms('Thanks!');

    $this->actingAs($this->owner)->get(route('jobs.mine', ['date' => '2026-10-16']))
        ->assertInertia(fn (Assert $page) => $page->where('visits.0.job.new_messages', 0));
});

// ——— The AI reads the text; nothing happens before Confirm ———

function triageAnswer(array $actions, string $urgency = 'normal', string $draft = 'Sure, we will confirm shortly.'): Closure
{
    return fn (array $request) => match ($request['model']) {
        default => ['urgency' => $urgency, 'actions' => $actions, 'reply_draft' => $draft],
    };
}

function moveAction(array $overrides = []): array
{
    return [...['type' => 'reschedule', 'job_number' => (string) test()->job->number, 'date' => 'tomorrow', 'arrival_start' => '13:00', 'arrival_end' => '15:00', 'arrival_now' => false], ...$overrides];
}

test('the AI reads an incoming text: urgency, actions, draft; nothing is changed or sent', function () {
    $fake = FakeAiTransport::install(triageAnswer([moveAction()], 'urgent'));
    config(['ai.models.booking' => 'claude-haiku-5-5']);

    $message = incomingSms('Water everywhere! Can you come tomorrow 1-3 instead?');

    expect($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]['model'])->toBe('claude-haiku-5-5')
        ->and($fake->requests[0]['messages'][0]['content'])->toContain('Water everywhere')->toContain('#'.$this->job->number);
    $triage = $message->fresh()->ai_triage;
    expect($triage['urgency'])->toBe('urgent')
        ->and($triage['actions'][0])->toMatchArray(['type' => 'reschedule', 'job_id' => $this->job->id, 'date' => '2026-10-15', 'start' => '13:00', 'status' => 'pending'])
        ->and($triage['reply_draft'])->toBe('Sure, we will confirm shortly.');

    // Nothing moved and nothing sent: the visit is where it was, no outgoing text.
    $visit = inCompany($this->company, fn () => JobVisit::query()->where('service_job_id', $this->job->id)->sole());
    expect($visit->scheduled_start->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-16 09:00')
        ->and(inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->count()))->toBe(0);

    // The chat shows the card, the urgent conversation first and the draft in the reply box.
    $other = Customer::factory()->for($this->company)->withPhone('604-555-0111')->create();
    inCompany($this->company, fn () => Message::create(['customer_id' => $other->id, 'direction' => 'inbound', 'channel' => 'sms', 'kind' => MessageKind::Reply, 'from' => '+16045550111', 'to' => '+16045550100', 'body' => 'Later message', 'status' => 'received']));
    $this->actingAs($this->owner)->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->where('threads.data.0.phone', '+16045550142')->where('threads.data.0.urgent', true)
        ->where('threads.data.1.urgent', false));
    $this->get(route('messages.index', ['phone' => '+16045550142']))->assertInertia(fn (Assert $page) => $page
        ->where('conversation.urgent', true)->where('conversation.awaiting', true)
        ->where('conversation.draft', 'Sure, we will confirm shortly.')
        ->where('conversation.messages.data.0.ai.actions.0.type', 'reschedule')
        ->where('conversation.messages.data.0.ai.actions.0.preview', fn ($text) => str_contains($text, '→')));

    // Confirm moves the visit (and still texts nobody).
    $this->postJson(route('messages.ai.confirm', [$message, 0]))->assertOk()->assertJsonPath('card.actions.0.status', 'done');
    expect($visit->fresh()->scheduled_start->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-15 13:00')
        ->and(inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->count()))->toBe(0);
    // Confirmed once only.
    $this->postJson(route('messages.ai.confirm', [$message, 0]))->assertUnprocessable();
});

test('"Thursday" and "tomorrow" become dates in the company time zone', function () {
    $tz = $this->company;
    // Wednesday 2026-10-14, late evening in Vancouver (already Thursday in UTC).
    $now = CarbonImmutable::parse('2026-10-14 23:30', 'America/Vancouver');
    expect(RelativeDate::resolve('tomorrow', $tz, $now))->toBe('2026-10-15')
        ->and(RelativeDate::resolve('завтра', $tz, $now))->toBe('2026-10-15')
        ->and(RelativeDate::resolve('thursday', $tz, $now))->toBe('2026-10-15')
        ->and(RelativeDate::resolve('в четверг', $tz, $now))->toBe('2026-10-15')
        ->and(RelativeDate::resolve('on Wednesday', $tz, $now))->toBe('2026-10-21')
        ->and(RelativeDate::resolve('today', $tz, $now))->toBe('2026-10-14')
        ->and(RelativeDate::resolve('2026-10-20', $tz, $now))->toBe('2026-10-20')
        ->and(RelativeDate::resolve('2026-02-30', $tz, $now))->toBeNull()
        ->and(RelativeDate::resolve('soon', $tz, $now))->toBeNull();
});

test('cancel and book wait for Confirm; dismiss leaves everything as it was', function () {
    FakeAiTransport::install(triageAnswer([['type' => 'cancel', 'job_number' => (string) $this->job->number, 'date' => '', 'arrival_start' => '', 'arrival_end' => '', 'arrival_now' => false]]));
    $message = incomingSms('Please cancel, it works again');

    expect($this->job->fresh()->status)->toBe(JobStatus::Scheduled);

    $this->actingAs($this->owner)->postJson(route('messages.ai.dismiss', [$message, 0]))->assertOk();
    expect($this->job->fresh()->status)->toBe(JobStatus::Scheduled)
        ->and($message->fresh()->ai_triage['actions'][0]['status'])->toBe('dismissed');

    $second = incomingSms('Yes cancel it please');
    $this->postJson(route('messages.ai.confirm', [$second, 0]))->assertOk();
    expect($this->job->fresh()->status)->toBe(JobStatus::Cancelled)
        ->and(inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->count()))->toBe(0);
});

test('a booking proposal opens the filled form and creates nothing', function () {
    $other = Customer::factory()->for($this->company)->withPhone('604-555-0123')->create(['first_name' => 'Bob', 'last_name' => 'Lee']);
    FakeAiTransport::install(fn (array $request) => str_contains($request['system'], 'text message (SMS)')
        ? ['urgency' => 'normal', 'actions' => [['type' => 'book', 'job_number' => '', 'date' => 'friday', 'arrival_start' => '', 'arrival_end' => '', 'arrival_now' => false]], 'reply_draft' => '']
        : array_merge(array_fill_keys(['first_name', 'last_name', 'phone', 'email', 'unit', 'buzzer', 'brand', 'model', 'problem', 'date', 'arrival_start', 'arrival_end', 'notes'], ''),
            ['address' => ['line1' => '', 'city' => '', 'region' => '', 'postal_code' => ''], 'appliance_type' => 'washer', 'arrival_now' => false, 'uncertain_fields' => [], 'problem' => 'Washer leaking']));
    $message = incomingSms('My washer leaks, can someone come Friday?', '+16045550123');
    $jobs = inCompany($this->company, fn () => ServiceJob::query()->count());

    $this->actingAs($this->owner)->postJson(route('messages.ai.confirm', [$message, 0]))->assertOk()
        ->assertJsonPath('booking.fields.phone', '+16045550123')
        ->assertJsonPath('booking.fields.date', '2026-10-16')
        ->assertJsonPath('booking.customer.id', $other->id);
    expect(inCompany($this->company, fn () => ServiceJob::query()->count()))->toBe($jobs);
});

test('a rating plate in an MMS: photo kept on the job, model and serial only after Confirm', function () {
    Http::fake([
        'https://media.example.com/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        SMS_API.'/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201),
    ]);
    Storage::fake(PrivateMedia::diskName());
    $fake = FakeAiTransport::install(fn (array $request) => isset($request['schema']['properties']['is_rating_plate'])
        ? ['is_rating_plate' => true, 'brand' => 'LG', 'model' => 'wm 3400 cw', 'serial' => '512 KW00123']
        : ['urgency' => 'normal', 'actions' => [], 'reply_draft' => '']);
    config(['ai.models.assistant' => 'claude-sonnet-5-5']);

    $message = incomingSms('Here is the sticker', '+16045550142', ['NumMedia' => '1', 'MediaUrl0' => 'https://media.example.com/p1', 'MediaContentType0' => 'image/jpeg']);

    expect(collect($fake->requests)->pluck('model')->all())->toContain('claude-sonnet-5-5');
    $message->refresh();
    expect($message->media)->toHaveCount(1)
        ->and($message->ai_triage['plate'])->toMatchArray(['brand' => 'LG', 'model' => 'WM3400CW', 'serial' => '512KW00123', 'job_id' => $this->job->id, 'status' => 'pending'])
        ->and(inCompany($this->company, fn () => $this->job->photos()->count()))->toBe(1)
        ->and(inCompany($this->company, fn () => $this->job->appliances()->count()))->toBe(0);

    $this->actingAs($this->owner)->postJson(route('messages.ai.plate', $message))->assertOk();
    $appliance = inCompany($this->company, fn () => Appliance::query()->sole());
    expect($appliance->model_number)->toBe('WM3400CW')->and($appliance->serial_number)->toBe('512KW00123')
        ->and(inCompany($this->company, fn () => $this->job->appliances()->count()))->toBe(1);
});

test('AI calls on texts count toward the daily limit', function () {
    config(['ai.daily_limit' => 1]);
    $fake = FakeAiTransport::install(triageAnswer([]));

    incomingSms('one');
    incomingSms('two');

    expect($fake->requests)->toHaveCount(1);
});

test('technicians cannot use the AI card or Done', function () {
    FakeAiTransport::install(triageAnswer([moveAction()]));
    $message = incomingSms('Tomorrow 1-3?');
    $tech = memberOf($this->company, UserRole::Technician);

    $this->actingAs($tech)->postJson(route('messages.ai.confirm', [$message, 0]))->assertForbidden();
    $this->post(route('messages.done'), ['phone' => '+16045550142'])->assertForbidden();
});

test('another company cannot confirm actions of a text', function () {
    FakeAiTransport::install(triageAnswer([moveAction()]));
    $message = incomingSms('Tomorrow 1-3?');
    $stranger = memberOf(Company::factory()->create());

    $this->actingAs($stranger)->postJson(route('messages.ai.confirm', [$message, 0]))->assertNotFound();
});

// ——— Possible warranty ———

test('possible warranty within the window, not after it', function () {
    $previous = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Completed, 'tech_notes' => 'Replaced drain pump']);
    $previous->forceFill(['completed_at' => now()->subDays(30)])->save();

    $this->actingAs($this->owner)->get(route('messages.index', ['phone' => '+16045550142']))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.warranty.job_id', $previous->id)
            ->where('conversation.warranty.work', 'Replaced drain pump'));
    $this->get(route('jobs.show', $this->job))
        ->assertInertia(fn (Assert $page) => $page->where('possibleWarranty.number', $previous->number));

    $previous->forceFill(['completed_at' => now()->subDays(91)])->save();
    $this->get(route('messages.index', ['phone' => '+16045550142']))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.warranty', null));
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('possibleWarranty', null));

    // The window is a setting.
    $this->company->update(['warranty_lookback_days' => 120]);
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('possibleWarranty.job_id', $previous->id));
});

// ——— Settings ———

test('the Owner sets up auto messages; auto replies are off by default', function () {
    expect(Company::factory()->create()->fresh()->auto_reply_enabled)->toBeFalse();

    $this->actingAs($this->owner)->get(route('company.auto-messages.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('company/auto-messages')->where('settings.auto_reply_enabled', false)
        ->where('settings.auto_reply_delay_minutes', 15)->where('missedCallsAvailable', false));

    $this->put(route('company.auto-messages.update'), [
        'auto_reply_enabled' => true, 'auto_reply_text' => 'Got it!', 'auto_reply_delay_minutes' => 30,
        'missed_call_reply_enabled' => false, 'missed_call_reply_text' => '', 'sms_reminder_hours' => 2, 'warranty_lookback_days' => 60,
    ])->assertRedirect();

    expect($this->company->fresh())->auto_reply_enabled->toBeTrue()->auto_reply_text->toBe('Got it!')
        ->auto_reply_delay_minutes->toBe(30)->sms_reminder_hours->toBe(2)->warranty_lookback_days->toBe(60);

    $this->put(route('company.auto-messages.update'), ['auto_reply_enabled' => true, 'auto_reply_delay_minutes' => 7, 'missed_call_reply_enabled' => false, 'sms_reminder_hours' => 2, 'warranty_lookback_days' => 60])
        ->assertSessionHasErrors('auto_reply_delay_minutes');
    $this->actingAs(memberOf($this->company, UserRole::Admin))->get(route('company.auto-messages.edit'))->assertForbidden();
});
