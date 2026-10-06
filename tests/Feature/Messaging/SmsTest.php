<?php

use App\Enums\UserRole;
use App\Mail\CustomerMessageMail;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

const TWILIO = 'https://api.twilio.com';

beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => TWILIO, 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake([TWILIO.'/2010-04-01/Accounts/ACsub/Messages.json' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201)]);
    Mail::fake();

    // Canadian company, daytime in its zone.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['sms_mode' => 'automatic']);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Tom Fixer']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->withEmail('jane@example.com')
        ->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-14 13:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-14 15:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id]);
    $this->account = inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));

    $this->actingAs($this->owner);
});

function messages(Company $company)
{
    return inCompany($company, fn () => Message::query()->orderBy('id')->get());
}

/**
 * A Twilio webhook signed like Twilio does: HMAC-SHA1 of the URL followed by the sorted POST params.
 */
function twilioWebhook(string $url, array $params, string $token = 'sub-token', ?string $signature = null)
{
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }

    return test()->withHeader('X-Twilio-Signature', $signature ?? base64_encode(hash_hmac('sha1', $data, $token, true)))
        ->post($url, $params);
}

test('new companies text from the technician\'s phone by default', function () {
    expect(Company::factory()->create()->fresh()->sms_mode->value)->toBe('technician_phone');
});

test('an SMS typed on the job is sent from the company number', function () {
    $this->post(route('jobs.sms', $this->job), ['body' => 'Hi Jane, running 10 minutes late.'])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->url() === TWILIO.'/2010-04-01/Accounts/ACsub/Messages.json'
        && $r['To'] === '+16045550142' && $r['From'] === '+16045550100' && $r['Body'] === 'Hi Jane, running 10 minutes late.'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('ACsub:sub-token'))
        && str_ends_with($r['StatusCallback'], '/webhooks/sms/twilio/status'));

    $message = messages($this->company)->sole();
    expect($message)->status->toBe('sent')->channel->toBe('sms')->service_job_id->toBe($this->job->id)
        ->customer_id->toBe($this->customer->id)->provider_message_id->toStartWith('SM');
});

test('texts wait for the end of the quiet hours', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 22:30', 'America/Vancouver'));

    $this->post(route('jobs.sms', $this->job), ['body' => 'Late text']);

    $message = messages($this->company)->sole();
    expect($message->status)->toBe('scheduled')
        ->and($message->send_after->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-15 08:00');
    Http::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-10-15 08:01', 'America/Vancouver'));
    $this->artisan('messages:deliver-due')->assertSuccessful();

    expect($message->fresh()->status)->toBe('sent');
    Http::assertSentCount(1);
});

test('texts to US numbers wait for the A2P 10DLC registration; Canadian numbers do not', function () {
    inCompany($this->company, fn () => $this->customer->phones()->first()->update(['number' => '512-555-0142']));

    $this->post(route('jobs.sms', $this->job), ['body' => 'Hello'])->assertSessionHasNoErrors();
    $blocked = messages($this->company)->last();
    expect($blocked)->status->toBe('blocked')->status_reason->toContain('A2P 10DLC');
    Http::assertNothingSent();

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('messaging.sms_blocked', fn (string $reason) => str_contains($reason, 'A2P 10DLC')));

    inCompany($this->company, fn () => SmsRegistration::create(['status' => 'approved']));
    $this->post(route('jobs.sms', $this->job), ['body' => 'Hello again']);
    expect(messages($this->company)->last()->status)->toBe('sent');
});

test('STOP switches texting off for that number, START back on; replies are kept on the timeline', function () {
    $url = route('webhooks.sms.inbound', 'twilio');
    $params = fn (string $body, string $sid) => ['AccountSid' => 'ACsub', 'From' => '+16045550142', 'To' => '+16045550100', 'Body' => $body, 'MessageSid' => $sid];

    twilioWebhook($url, $params('Sounds good, see you then', 'SMin1'))->assertOk();
    twilioWebhook($url, $params('Sounds good, see you then', 'SMin1'))->assertOk(); // retried by Twilio
    $reply = messages($this->company)->sole();
    expect($reply)->direction->toBe('inbound')->status->toBe('received')->customer_id->toBe($this->customer->id)->body->toBe('Sounds good, see you then');

    twilioWebhook($url, $params('STOP', 'SMin2'))->assertOk();
    $phone = inCompany($this->company, fn () => CustomerPhone::sole());
    expect($phone->sms_opted_out_at)->not->toBeNull();

    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('customer.phones.0.sms_opted_out_at', fn ($v) => $v !== null)
        ->has('messaging.messages', 2));

    $this->post(route('jobs.sms', $this->job), ['body' => 'Are you there?']);
    expect(messages($this->company)->last())->status->toBe('blocked')->status_reason->toContain('STOP');
    Http::assertNothingSent();

    twilioWebhook($url, $params('start', 'SMin3'))->assertOk();
    expect($phone->fresh()->sms_opted_out_at)->toBeNull();
});

test('webhooks with a wrong signature are refused', function () {
    twilioWebhook(route('webhooks.sms.inbound', 'twilio'), ['AccountSid' => 'ACsub', 'From' => '+16045550142', 'To' => '+16045550100', 'Body' => 'STOP', 'MessageSid' => 'SMx'], signature: 'forged')
        ->assertForbidden();

    expect(inCompany($this->company, fn () => CustomerPhone::sole()->sms_opted_out_at))->toBeNull();
});

test('delivery status comes back from Twilio', function () {
    $this->post(route('jobs.sms', $this->job), ['body' => 'Hello']);
    $message = messages($this->company)->sole();

    twilioWebhook(route('webhooks.sms.status', 'twilio'), ['AccountSid' => 'ACsub', 'MessageSid' => $message->provider_message_id, 'MessageStatus' => 'delivered'])
        ->assertNoContent();
    twilioWebhook(route('webhooks.sms.status', 'twilio'), ['AccountSid' => 'ACsub', 'MessageSid' => $message->provider_message_id, 'MessageStatus' => 'sent'])
        ->assertNoContent();

    expect($message->fresh()->status)->toBe('delivered');
});

test('On my way texts the arrival window in Automatic mode', function () {
    $visit = inCompany($this->company, fn () => JobVisit::sole());

    $this->post(route('visits.on-my-way', $visit))->assertSessionHasNoErrors();

    $message = messages($this->company)->sole();
    expect($message->kind->value)->toBe('on_my_way')
        ->and($message->body)->toBe('Hi Jane, Tom from Doctor Appliance is on the way. Expected arrival: 1:00 p.m. - 3:00 p.m.')
        ->and($message->status)->toBe('sent');
});

test('On my way goes by email in Off mode and nothing is sent in technician\'s phone mode', function () {
    $visit = inCompany($this->company, fn () => JobVisit::sole());
    $this->company->update(['sms_mode' => 'off']);

    $this->post(route('visits.on-my-way', $visit));

    expect(messages($this->company)->sole())->channel->toBe('email')->to->toBe('jane@example.com');
    Mail::assertQueued(CustomerMessageMail::class, fn ($mail) => $mail->hasTo('jane@example.com'));
    Http::assertNothingSent();

    $this->company->update(['sms_mode' => 'technician_phone']);
    $visit->refresh()->forceFill(['status' => 'scheduled'])->save();
    $this->post(route('visits.on-my-way', $visit));
    expect(messages($this->company))->toHaveCount(1);
});

test('the technician\'s phone mode gives ready texts and records texts opened on the phone', function () {
    $this->company->update(['sms_mode' => 'technician_phone']);

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('messaging.mode', 'technician_phone')
        ->where('messaging.phone', '+16045550142')
        ->where('messaging.texts.on_my_way', 'Hi Jane, Tom from Doctor Appliance is on the way. Expected arrival: 1:00 p.m. - 3:00 p.m.'));

    $this->post(route('jobs.messages.opened', $this->job), ['kind' => 'on_my_way', 'to' => '+16045550142', 'body' => 'On the way'])->assertRedirect();

    expect(messages($this->company)->sole())->channel->toBe('technician_phone')->status->toBe('opened')->user_id->toBe($this->owner->id);
    $this->post(route('jobs.sms', $this->job), ['body' => 'x'])->assertNotFound();
    Http::assertNothingSent();
});

test('company templates replace the default texts', function () {
    $this->put(route('company.messaging.update'), [
        'sms_mode' => 'automatic', 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00',
        'templates' => ['on_my_way' => '{brand}: {tech_name} is coming, {arrival_window}.', 'general' => ''],
    ])->assertRedirect(route('company.messaging.edit'));

    $this->post(route('visits.on-my-way', inCompany($this->company, fn () => JobVisit::sole())));

    expect(messages($this->company)->sole()->body)->toBe('Doctor Appliance: Tom is coming, 1:00 p.m. - 3:00 p.m..')
        ->and($this->company->fresh()->message_templates)->toBe(['on_my_way' => '{brand}: {tech_name} is coming, {arrival_window}.']);
});

test('an invoice link goes by SMS from the invoice page', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = inCompany($this->company, fn () => Invoice::sole());

    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('sms.mode', 'automatic')->where('sms.blocked', null));
    $this->post(route('invoices.sms', $invoice))->assertSessionHasNoErrors();

    $message = messages($this->company)->sole();
    expect($message->kind->value)->toBe('invoice_link')
        ->and($message->body)->toContain($invoice->number)->toContain('/d/i')->toContain('$280.50');

    // Off mode: documents go by email only.
    $this->company->update(['sms_mode' => 'off']);
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page->where('sms', null));
});

test('visit reminders go out the day before: SMS in Automatic mode, email otherwise, once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 17:05', 'America/Vancouver'));

    $this->artisan('messages:send-visit-reminders')->assertSuccessful();
    $this->artisan('messages:send-visit-reminders')->assertSuccessful();

    $reminder = messages($this->company)->sole();
    expect($reminder)->kind->value->toBe('visit_reminder')->channel->toBe('sms')->status->toBe('sent')
        ->and($reminder->body)->toContain('Wednesday, Oct 14')->toContain('1:00 p.m. - 3:00 p.m.');

    // Another company, technician's phone mode: by email.
    $other = Company::factory()->create();
    $tech = memberOf($other, UserRole::Technician);
    $customer = Customer::factory()->for($other)->withEmail('sam@example.com')->create();
    ServiceJob::factory()->for(Property::factory()->for($customer))->withVisit($tech, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-14 09:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-14 11:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => Brand::factory()->create(['company_id' => $other->id])->id]);

    $this->artisan('messages:send-visit-reminders')->assertSuccessful();
    expect(messages($other)->sole())->channel->toBe('email')->to->toBe('sam@example.com');
});

test('the Owner gets the company an SMS subaccount and number', function () {
    $fresh = Company::factory()->inUnitedStates()->create(['sms_mode' => 'automatic']);
    $this->actingAs(memberOf($fresh, UserRole::Owner, ['two_factor_confirmed_at' => now()]));
    Http::fake([
        TWILIO.'/2010-04-01/Accounts.json' => Http::response(['sid' => 'ACnew', 'auth_token' => 'new-token'], 201),
        TWILIO.'/2010-04-01/Accounts/ACnew/AvailablePhoneNumbers/US/Local.json*' => Http::response(['available_phone_numbers' => [['phone_number' => '+15125550111']]]),
        TWILIO.'/2010-04-01/Accounts/ACnew/IncomingPhoneNumbers.json' => Http::response(['sid' => 'PN1', 'phone_number' => '+15125550111'], 201),
    ]);

    $this->post(route('company.messaging.provision'))->assertRedirect(route('company.messaging.edit'));

    $account = inCompany($fresh, fn () => SmsAccount::sole());
    expect($account)->account_sid->toBe('ACnew')->auth_token->toBe('new-token')->phone_number->toBe('+15125550111')
        ->and(DB::table('sms_accounts')->where('id', $account->id)->value('auth_token'))->not->toContain('new-token');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'IncomingPhoneNumbers.json') && str_ends_with($r['SmsUrl'], '/webhooks/sms/twilio'));

    $this->get(route('company.messaging.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('account.phone_number', '(512) 555-0111')
        ->where('registration.status', 'draft'));
});

test('US companies submit their A2P 10DLC details; the status follows Twilio', function () {
    $us = Company::factory()->inUnitedStates()->create(['sms_mode' => 'automatic']);
    $this->actingAs(memberOf($us, UserRole::Owner, ['two_factor_confirmed_at' => now()]));
    $business = [
        'legal_name' => 'Lone Star Appliance LLC', 'business_type' => 'llc', 'ein' => '12-3456789', 'website' => 'https://lonestar.example.com',
        'street' => '500 W 2nd St', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701',
        'contact_first_name' => 'Jordan', 'contact_last_name' => 'Austin', 'contact_email' => 'jordan@example.com', 'contact_phone' => '512-555-0100',
        'use_case_description' => 'Appointment reminders and technician arrival notices for customers who booked a repair.',
        'sample_message' => 'Hi Maria, Tom from Lone Star is on the way.',
    ];

    $this->put(route('company.messaging.registration'), ['business' => [...$business, 'ein' => ''], 'submit' => true])
        ->assertSessionHasErrors('business.ein');
    $this->put(route('company.messaging.registration'), ['business' => $business, 'submit' => true])->assertSessionHasNoErrors();

    $registration = inCompany($us, fn () => SmsRegistration::sole());
    expect($registration)->status->toBe('submitted')->and($registration->business['legal_name'])->toBe('Lone Star Appliance LLC');

    // The platform registers the campaign and records its IDs; the daily sync reads the outcome.
    inCompany($us, function () use ($registration) {
        SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACus', 'auth_token' => 't', 'phone_number' => '+15125550111']);
        $registration->update(['messaging_service_sid' => 'MG1', 'campaign_sid' => 'QE1']);
    });
    Http::fake(['https://messaging.twilio.com/v1/Services/MG1/Compliance/Usa2p/QE1' => Http::response(['campaign_status' => 'VERIFIED'])]);

    $this->artisan('sms:sync-registrations')->assertSuccessful();
    expect($registration->fresh())->status->toBe('approved')->approved_at->not->toBeNull();

    // Canadian companies are not asked for it.
    $this->actingAs($this->owner);
    $this->get(route('company.messaging.edit'))->assertInertia(fn (Assert $page) => $page->where('registration', null));
});
