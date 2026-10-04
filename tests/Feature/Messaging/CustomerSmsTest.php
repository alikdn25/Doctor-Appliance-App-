<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Membership;
use App\Models\Message;
use App\Models\SmsAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/2010-04-01/Accounts/ACsub/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));

    $this->company = Company::factory()->create(['sms_mode' => 'automatic']);
    $this->owner = memberOf($this->company);
    // A salesperson: view only plus the SMS inbox.
    $this->sales = memberOf($this->company, UserRole::Admin, ['name' => 'Sam Sales']);
    inCompany($this->company, fn () => Membership::query()->where('user_id', $this->sales->id)->update(['permissions' => json_encode(['messages'])]));
    $this->viewer = memberOf($this->company, UserRole::Admin);
    inCompany($this->company, fn () => Membership::query()->where('user_id', $this->viewer->id)->update(['permissions' => json_encode([])]));
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane']);
    $this->phone = inCompany($this->company, fn () => CustomerPhone::query()->where('customer_id', $this->customer->id)->sole());
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
    $this->other = Customer::factory()->for($this->company)->withPhone('604-555-0199')->create();
    $this->otherPhone = inCompany($this->company, fn () => CustomerPhone::query()->where('customer_id', $this->other->id)->sole());
    $this->foreignCompany = Company::factory()->create(['sms_mode' => 'automatic']);
    $this->foreign = Customer::factory()->for($this->foreignCompany)->withPhone('604-555-0177')->create();
});

test('a salesperson with the SMS inbox texts a customer from the profile, outside any job', function () {
    $this->actingAs($this->sales)->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('messaging.can_text', true)
        ->where('messaging.phones.0.id', $this->phone->id)
        ->where('messaging.text', fn (string $text) => str_starts_with($text, 'Hi Jane, this is Sam from')));

    $this->post(route('customers.sms', $this->customer), ['phone_id' => $this->phone->id, 'body' => 'Hi Jane, please call us back.'])
        ->assertSessionHasNoErrors();

    $message = inCompany($this->company, fn () => Message::sole());
    expect($message)->service_job_id->toBeNull()->customer_id->toBe($this->customer->id)
        ->channel->toBe('sms')->body->toBe('Hi Jane, please call us back.')->user_id->toBe($this->sales->id);
    Http::assertSent(fn ($request) => ($request['Body'] ?? null) === 'Hi Jane, please call us back.');

    // The salesperson sees the text in the customer history even without the Customers permission.
    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('messaging.messages.0.body', 'Hi Jane, please call us back.'));
});

test('from the technician\'s phone the text is opened on the phone and recorded', function () {
    $this->company->update(['sms_mode' => 'technician_phone']);
    $this->actingAs($this->sales)->post(route('customers.sms', $this->customer), ['phone_id' => $this->phone->id, 'body' => 'x'])->assertNotFound();
    $this->post(route('customers.messages.opened', $this->customer), ['phone_id' => $this->phone->id, 'body' => 'Hi Jane'])->assertRedirect();

    expect(inCompany($this->company, fn () => Message::sole()))->channel->toBe('technician_phone')->status->toBe('opened')
        ->to->toBe($this->phone->number);
    Http::assertNothingSent();
});

test('texting needs the SMS inbox area, a number of this customer and this company', function () {
    $this->actingAs($this->viewer)->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page->where('messaging.can_text', false));
    $this->post(route('customers.sms', $this->customer), ['phone_id' => $this->phone->id, 'body' => 'x'])->assertForbidden();
    $this->actingAs($this->tech)->post(route('customers.sms', $this->customer), ['phone_id' => $this->phone->id, 'body' => 'x'])->assertForbidden();

    $this->actingAs($this->sales)->post(route('customers.sms', $this->customer), ['phone_id' => $this->otherPhone->id, 'body' => 'x'])->assertSessionHasErrors('phone_id');
    $this->post(route('customers.sms', $this->foreign), ['phone_id' => 1, 'body' => 'x'])->assertNotFound();

    $this->company->update(['sms_mode' => 'off']);
    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page->where('messaging.can_text', false));
    expect(inCompany($this->company, fn () => Message::count()))->toBe(0);
});
