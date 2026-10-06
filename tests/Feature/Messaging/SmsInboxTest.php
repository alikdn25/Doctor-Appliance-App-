<?php

use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Messaging\Messenger;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'test-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMinbox', 'status' => 'queued'], 201)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['sms_mode' => 'automatic']);
    $this->owner = memberOf($this->company);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')
        ->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create();
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACinbox', 'auth_token' => 'test-token', 'phone_number' => '+16045550100',
    ]));
    $this->incoming = inCompany($this->company, fn () => Message::create([
        'customer_id' => $this->customer->id, 'service_job_id' => $this->job->id,
        'direction' => Message::INBOUND, 'channel' => Message::SMS, 'kind' => MessageKind::Reply,
        'from' => '+16045550142', 'to' => '+16045550100', 'body' => 'Please call before arriving.',
        'status' => 'received', 'sent_at' => now(),
    ]));
    $this->actingAs($this->owner);
});

test('the office sees grouped conversations, a personal unread badge and full message status', function () {
    $this->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->component('messages/index')->where('auth.can.viewMessageInbox', true)->where('unreadMessages', 1)
        ->has('threads.data', 1)->where('threads.data.0.phone', '+16045550142')->where('threads.data.0.unread_count', 1)
        ->where('conversation', null));
    $this->get(route('messages.index', ['phone' => '+16045550142']))->assertInertia(fn (Assert $page) => $page
        ->where('conversation.customer.id', $this->customer->id)->where('conversation.blocked', null)
        ->has('conversation.messages.data', 1)->where('conversation.messages.data.0.status_label', 'Received')
        ->where('conversation.unread_ids', [$this->incoming->id]));
    // GETs and link prefetches never mark a message as read.
    expect(inCompany($this->company, fn () => MessageRead::count()))->toBe(0);
});

test('reading rendered incoming messages is idempotent and does not clear another employee badge', function () {
    $office = inCompany($this->company, fn () => memberOf($this->company, UserRole::Admin));
    $payload = ['phone' => '+16045550142', 'message_ids' => [$this->incoming->id]];
    $this->post(route('messages.read'), $payload)->assertRedirect();
    $this->post(route('messages.read'), $payload)->assertRedirect();
    expect(inCompany($this->company, fn () => MessageRead::count()))->toBe(1);
    $this->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page->where('unreadMessages', 0));
    $this->actingAs($office)->get(route('messages.index', ['unread' => 1]))->assertInertia(fn (Assert $page) => $page
        ->where('unreadMessages', 1)->has('threads.data', 1));
});

test('read acknowledgements ignore other numbers, outgoing messages and another tenant', function () {
    $other = Company::factory()->create();
    $foreign = inCompany($other, fn () => Message::create([
        'direction' => 'inbound', 'channel' => 'sms', 'kind' => MessageKind::Reply,
        'from' => '+16045550142', 'to' => '+16045550100', 'body' => 'Foreign secret', 'status' => 'received',
    ]));
    $outbound = inCompany($this->company, fn () => Message::create([
        'direction' => 'outbound', 'channel' => 'sms', 'kind' => MessageKind::General,
        'customer_id' => $this->customer->id, 'to' => '+16045550142', 'body' => 'Sent text', 'status' => 'sent',
    ]));
    $this->post(route('messages.read'), ['phone' => '+16045550143', 'message_ids' => [$this->incoming->id]])->assertRedirect();
    $this->post(route('messages.read'), ['phone' => '+16045550142', 'message_ids' => [$foreign->id, $outbound->id]])->assertRedirect();
    expect(inCompany($this->company, fn () => MessageRead::count()))->toBe(0);
    $this->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->where('unreadMessages', 1)->has('threads.data', 1)->where('threads.data.0.preview', 'Sent text'));
});

test('brand restrictions apply to inbox threads, read state, replies and partial badge refreshes', function () {
    $allowed = inCompany($this->company, fn () => Brand::factory()->create(['company_id' => $this->company->id]));
    $office = inCompany($this->company, function () use ($allowed) {
        $user = memberOf($this->company, UserRole::Admin);
        $user->brands()->attach($allowed->id, ['company_id' => $this->company->id]);

        return $user;
    });
    $response = $this->actingAs($office)->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->where('unreadMessages', 0)->has('threads.data', 0));
    $this->get(route('messages.index', ['phone' => '+16045550142']))->assertNotFound();
    $this->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'Unauthorized'])->assertNotFound();
    $this->post(route('messages.read'), ['phone' => '+16045550142', 'message_ids' => [$this->incoming->id]])->assertRedirect();
    expect(inCompany($this->company, fn () => MessageRead::count()))->toBe(0);
    $this->get(route('messages.index'), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $response->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'messages/index', 'X-Inertia-Partial-Data' => 'threads',
    ])->assertJsonPath('props.unreadMessages', 0);
    Http::assertNothingSent();
});

test('technicians keep job messaging but cannot open, read or send through the shared office inbox', function () {
    $tech = inCompany($this->company, fn () => memberOf($this->company, UserRole::Technician));
    $this->actingAs($tech)->get(route('messages.index'))->assertForbidden();
    $this->post(route('messages.read'), ['phone' => '+16045550142', 'message_ids' => [$this->incoming->id]])->assertForbidden();
    $this->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'No'])->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.viewMessageInbox', false)->where('unreadMessages', null));
});

test('replying to a secondary contact texts that exact number and retains the job and author', function () {
    inCompany($this->company, function () {
        $this->customer->phones()->create(['number' => '+16045550143', 'is_primary' => false]);
        $this->incoming->update(['from' => '+16045550143']);
    });
    $this->post(route('messages.send'), ['phone' => '+16045550143', 'body' => ' Yes, we will call. '])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request['To'] === '+16045550143' && $request['Body'] === 'Yes, we will call.');
    $message = inCompany($this->company, fn () => Message::latest('id')->first());
    expect($message)->status->toBe('sent')->user_id->toBe($this->owner->id)->service_job_id->toBe($this->job->id);
});

test('STOP is enforced again on reply submission even when the page was already open', function () {
    inCompany($this->company, fn () => $this->customer->phones()->first()->forceFill(['sms_opted_out_at' => now()])->save());
    $this->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'Do not send'])->assertSessionHasNoErrors();
    $message = inCompany($this->company, fn () => Message::latest('id')->first());
    expect($message)->status->toBe('blocked')->status_reason->toContain('STOP');
    Http::assertNothingSent();
});

test('quiet hours delay inbox replies instead of bypassing the company schedule', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 22:00', 'America/Vancouver'));
    $this->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'Morning reply'])->assertSessionHasNoErrors();
    $message = inCompany($this->company, fn () => Message::latest('id')->first());
    expect($message)->status->toBe('scheduled')
        ->and($message->send_after->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-15 08:00');
    Http::assertNothingSent();
});

test('US registration blocks inbox replies to a US secondary number', function () {
    inCompany($this->company, function () {
        $this->customer->phones()->create(['number' => '+15125550143', 'is_primary' => false]);
        $this->incoming->update(['from' => '+15125550143']);
    });
    $this->post(route('messages.send'), ['phone' => '+15125550143', 'body' => 'Blocked'])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Message::latest('id')->first()->status_reason))->toContain('A2P 10DLC');
    Http::assertNothingSent();
});

test('unknown numbers stay visible and can be texted without booking a customer', function () {
    inCompany($this->company, fn () => $this->incoming->update(['customer_id' => null, 'service_job_id' => null, 'from' => '+16045550143']));
    $this->get(route('messages.index', ['phone' => '+16045550143']))->assertInertia(fn (Assert $page) => $page
        ->where('conversation.customer', null)->where('unreadMessages', 1)->where('conversation.blocked', null));
    $this->post(route('messages.send'), ['phone' => '+16045550143', 'body' => 'Still need help?'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request['To'] === '+16045550143' && $request['Body'] === 'Still need help?');
    $message = inCompany($this->company, fn () => Message::latest('id')->first());
    expect($message)->customer_id->toBeNull()->service_job_id->toBeNull()->status->toBe('sent')->user_id->toBe($this->owner->id);
});

test('the office opens a new conversation with any number, then texts and sees it as a thread', function () {
    $this->get(route('messages.index', ['phone' => '+16045550199']))->assertInertia(fn (Assert $page) => $page
        ->where('conversation.phone', '+16045550199')->where('conversation.customer', null)
        ->where('conversation.blocked', null)->where('conversation.sms_mode', 'automatic')
        ->has('conversation.messages.data', 0));
    $this->post(route('messages.send'), ['phone' => '+16045550199', 'body' => 'Hi, we tried to call you.'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request['To'] === '+16045550199');
    $this->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->has('threads.data', 2)->where('threads.data.0.phone', '+16045550199')->where('threads.data.0.customer_name', null));
});

test('a new conversation with a customer number texts that customer and shows their name', function () {
    $other = inCompany($this->company, fn () => Customer::factory()->for($this->company)->withPhone('+16045550177')->create());
    $this->get(route('messages.index', ['phone' => '+16045550177']))->assertInertia(fn (Assert $page) => $page
        ->where('conversation.customer.id', $other->id)->where('conversation.blocked', null));
    $this->post(route('messages.send'), ['phone' => '+16045550177', 'body' => 'Hello'])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Message::latest('id')->first()))->customer_id->toBe($other->id)->status->toBe('sent');
});

test('a STOP from a number without a customer blocks new texts until START', function () {
    $reply = fn (string $body) => inCompany($this->company, fn () => Message::create([
        'direction' => 'inbound', 'channel' => 'sms', 'kind' => MessageKind::Reply,
        'from' => '+16045550199', 'to' => '+16045550100', 'body' => $body, 'status' => 'received',
    ]));
    $reply(' stop ');
    $this->get(route('messages.index', ['phone' => '+16045550199']))->assertInertia(fn (Assert $page) => $page
        ->where('conversation.blocked', fn ($reason) => str_contains($reason, 'STOP')));
    $this->post(route('messages.send'), ['phone' => '+16045550199', 'body' => 'Blocked'])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Message::latest('id')->first()))->status->toBe('blocked');
    Http::assertNothingSent();
    $reply('START');
    $this->post(route('messages.send'), ['phone' => '+16045550199', 'body' => 'Welcome back'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $request) => $request['Body'] === 'Welcome back');
});

test('a STOP arriving after a text was queued at night stops it before delivery', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 22:00', 'America/Vancouver'));
    $this->post(route('messages.send'), ['phone' => '+16045550199', 'body' => 'Morning text'])->assertSessionHasNoErrors();
    $queued = inCompany($this->company, fn () => Message::latest('id')->first());
    inCompany($this->company, fn () => Message::create([
        'direction' => 'inbound', 'channel' => 'sms', 'kind' => MessageKind::Reply,
        'from' => '+16045550199', 'to' => '+16045550100', 'body' => 'STOP', 'status' => 'received',
    ]));
    inCompany($this->company, fn () => app(Messenger::class)->deliver($queued->fresh()));
    expect(inCompany($this->company, fn () => $queued->fresh()->status))->toBe('blocked');
    Http::assertNothingSent();
});

test('new conversations follow the SMS mode, tenant isolation and validation', function () {
    $other = Company::factory()->create(['sms_mode' => 'automatic']);
    inCompany($other, fn () => Message::create([
        'direction' => 'inbound', 'channel' => 'sms', 'kind' => MessageKind::Reply,
        'from' => '+16045550199', 'to' => '+16045550101', 'body' => 'Foreign secret', 'status' => 'received',
    ]));
    $this->get(route('messages.index', ['phone' => '+16045550199']))->assertInertia(fn (Assert $page) => $page
        ->has('conversation.messages.data', 0));
    $this->get(route('messages.index', ['phone' => '6045550199']))->assertSessionHasErrors('phone');
    $this->post(route('messages.send'), ['phone' => 'abc', 'body' => 'x'])->assertSessionHasErrors('phone');
    $this->company->update(['sms_mode' => 'technician_phone']);
    $this->get(route('messages.index', ['phone' => '+16045550199']))->assertInertia(fn (Assert $page) => $page
        ->where('conversation.sms_mode', 'technician_phone'));
    $this->post(route('messages.send'), ['phone' => '+16045550199', 'body' => 'Wrong mode'])->assertSessionHasErrors('body');
    Http::assertNothingSent();
});

test('a customer number whose texts belong to a hidden brand is not opened as a new conversation', function () {
    $allowed = inCompany($this->company, fn () => Brand::factory()->create(['company_id' => $this->company->id]));
    $office = inCompany($this->company, function () use ($allowed) {
        $user = memberOf($this->company, UserRole::Admin);
        $user->brands()->attach($allowed->id, ['company_id' => $this->company->id]);

        return $user;
    });
    $this->actingAs($office)->get(route('messages.index', ['phone' => '+16045550199']))->assertOk();
    $this->get(route('messages.index', ['phone' => '+16045550142']))->assertNotFound();
});

test('ambiguous shared contact numbers and non-automatic modes cannot send replies', function () {
    inCompany($this->company, function () {
        Customer::factory()->for($this->company)->withPhone('+16045550142')->create();
    });
    $this->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'Ambiguous'])->assertSessionHasErrors('body');
    $this->company->update(['sms_mode' => 'technician_phone']);
    $this->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'Wrong mode'])->assertSessionHasErrors('body');
    Http::assertNothingSent();
});

test('searching an older message retains the latest preview and conversation-wide unread count', function () {
    inCompany($this->company, fn () => $this->incoming->replicate()->fill(['body' => 'Newest customer reply'])->save());
    $this->get(route('messages.index', ['search' => 'before arriving', 'unread' => 1]))->assertInertia(fn (Assert $page) => $page
        ->has('threads.data', 1)->where('threads.data.0.preview', 'Newest customer reply')->where('threads.data.0.unread_count', 2));
    $this->get(route('messages.index', ['search' => 'Jane']))->assertInertia(fn (Assert $page) => $page->has('threads.data', 1));
});

test('message pagination retains older history and only marks the rendered messages as read', function () {
    inCompany($this->company, function () {
        for ($i = 0; $i < 50; $i++) {
            $this->incoming->replicate()->fill(['body' => "Reply {$i}"])->save();
        }
    });
    $this->get(route('messages.index', ['phone' => '+16045550142']))->assertInertia(fn (Assert $page) => $page
        ->has('conversation.messages.data', 50)->where('conversation.messages.total', 51)->has('conversation.unread_ids', 50));
    $this->get(route('messages.index', ['phone' => '+16045550142', 'message_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('conversation.messages.data', 1)->where('conversation.messages.data.0.id', $this->incoming->id));
    $this->post(route('messages.read'), ['phone' => '+16045550142', 'message_ids' => [$this->incoming->id]])->assertRedirect();
    $this->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page->where('unreadMessages', 50));
});

test('archived jobs retain office correspondence without linking to a deleted job', function () {
    inCompany($this->company, fn () => $this->job->delete());
    $this->get(route('messages.index', ['phone' => '+16045550142']))->assertInertia(fn (Assert $page) => $page
        ->where('unreadMessages', 1)->has('threads.data', 1)
        ->where('conversation.messages.data.0.job_id', null));
    $this->post(route('messages.send'), ['phone' => '+16045550142', 'body' => 'We received your reply.'])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => Message::latest('id')->first()->service_job_id))->toBeNull();
});

test('deleting a limited brand never grants access to other brands or their inbox messages', function () {
    [$office, $otherJob] = inCompany($this->company, function () {
        $office = memberOf($this->company, UserRole::Admin);
        $office->brands()->attach($this->job->brand_id, ['company_id' => $this->company->id]);
        $otherJob = ServiceJob::factory()->for($this->job->property)->create();
        $this->incoming->replicate()->fill([
            'service_job_id' => $otherJob->id, 'from' => '+16045550143', 'body' => 'Private other-brand reply',
        ])->save();
        $this->job->brand->delete();

        return [$office, $otherJob];
    });
    expect(inCompany($this->company, fn () => $office->limitedBrandIds()))->toBe([$this->job->brand_id])
        ->and(inCompany($this->company, fn () => ServiceJob::query()->visibleTo($office)->pluck('id')->all()))->toBe([$this->job->id]);
    $this->actingAs($office)->get(route('messages.index'))->assertInertia(fn (Assert $page) => $page
        ->where('unreadMessages', 1)->has('threads.data', 1)->where('threads.data.0.phone', '+16045550142'));
    $this->get(route('messages.index', ['phone' => '+16045550143']))->assertNotFound();
    $this->get(route('jobs.show', $otherJob))->assertForbidden();
});
