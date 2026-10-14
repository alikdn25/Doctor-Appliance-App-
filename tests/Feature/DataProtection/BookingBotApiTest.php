<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BotToken;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Monday, 7:00 in Vancouver.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'America/Vancouver'));

    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    [$this->token, $this->plain] = BotToken::issue($this->company, 'Website', $this->owner);

    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Maria']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '12 Main St', 'city' => 'Surrey']);

    $this->other = Company::factory()->create();
    $this->otherCustomer = Customer::factory()->for($this->other)->withPhone('604-555-0202')->create(['first_name' => 'Secret']);
    Property::factory()->for($this->otherCustomer)->create();

    $this->bot = fn () => $this->withHeader('Authorization', "Bearer {$this->plain}");
});

test('without a valid token the bot gets nothing', function () {
    $this->getJson('/api/bot/v1/windows')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer bot_wrong')->getJson('/api/bot/v1/windows')->assertUnauthorized();

    $this->token->forceFill(['revoked_at' => now()])->save();
    ($this->bot)()->getJson('/api/bot/v1/windows')->assertUnauthorized();
});

test('the bot sees free arrival windows in the company time zone', function () {
    $response = ($this->bot)()->getJson('/api/bot/v1/windows?days=1')->assertOk();

    expect($response->json('timezone'))->toBe('America/Vancouver')
        ->and(collect($response->json('windows'))->pluck('start')->all())->toBe(['09:00', '11:00', '13:00', '15:00']);

    // Saturday and Sunday are closed by default.
    $weekend = ($this->bot)()->getJson('/api/bot/v1/windows?from=2026-10-24&days=2')->assertOk();
    expect($weekend->json('windows'))->toBe([]);
});

test('the bot finds a customer by phone in its own company only, without the customer file', function () {
    ($this->bot)()->getJson('/api/bot/v1/customers?phone=(604) 555-0101')
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('customer.first_name', 'Maria')
        ->assertJsonPath('customer.addresses.0.street', '12 Main St')
        ->assertJsonMissingPath('customer.last_name')
        ->assertJsonMissingPath('customer.notes');

    ($this->bot)()->getJson('/api/bot/v1/customers?phone=604-555-0202')
        ->assertOk()
        ->assertJsonPath('found', false);
});

test('the bot adds a customer and books a free window; the Owner is notified and the log says bot', function () {
    $created = ($this->bot)()->postJson('/api/bot/v1/customers', [
        'first_name' => 'Anna',
        'last_name' => 'Kim',
        'phone' => '604-555-0303',
        'address' => ['line1' => '5 Oak Ave', 'city' => 'Burnaby'],
    ])->assertCreated()->json();

    $booking = ($this->bot)()->postJson('/api/bot/v1/bookings', [
        'customer_id' => $created['customer_id'],
        'date' => '2026-10-19',
        'start' => '11:00',
        'appliance' => 'Washer',
        'problem' => 'Does not drain',
    ])->assertCreated();

    $job = ServiceJob::withoutCompanyScope()->where('company_id', $this->company->id)->where('number', $booking->json('job_number'))->sole();
    $botUser = User::query()->find($this->token->user_id);

    expect($job->created_by)->toBe($botUser->id)
        ->and($botUser->is_bot)->toBeTrue()
        ->and($booking->json('start'))->toBe('11:00')
        ->and($booking->json('end'))->toBe('13:00')
        ->and(AuditLog::query()->where('action', 'job.created')->where('auditable_id', $job->id)->value('source'))->toBe('bot')
        ->and(AuditLog::query()->where('action', 'customer.created')->where('auditable_id', $created['customer_id'])->value('source'))->toBe('bot');

    $notification = $this->owner->notifications()->sole();
    expect($notification->data['kind'])->toBe('bot_booking')
        ->and($notification->data['title'])->toContain((string) $job->number);
});

test('a phone that is already a customer returns that customer unchanged', function () {
    ($this->bot)()->postJson('/api/bot/v1/customers', [
        'first_name' => 'Somebody',
        'phone' => '604-555-0101',
        'address' => ['line1' => '1 New St', 'city' => 'Surrey'],
    ])->assertOk()->assertJsonPath('existing', true)->assertJsonPath('customer_id', $this->customer->id);

    expect($this->customer->fresh()->first_name)->toBe('Maria');
});

test('the bot cannot book a taken window, another company\'s customer, or more than its hourly limit', function () {
    // One person goes on calls (the Owner): one booking fills a window.
    ($this->bot)()->postJson('/api/bot/v1/bookings', ['customer_id' => $this->customer->id, 'date' => '2026-10-19', 'start' => '09:00', 'problem' => 'Noise'])->assertCreated();
    ($this->bot)()->postJson('/api/bot/v1/bookings', ['customer_id' => $this->customer->id, 'date' => '2026-10-19', 'start' => '09:00', 'problem' => 'Noise'])
        ->assertUnprocessable()->assertJsonValidationErrors('start');

    ($this->bot)()->postJson('/api/bot/v1/bookings', ['customer_id' => $this->otherCustomer->id, 'date' => '2026-10-19', 'start' => '11:00', 'problem' => 'x'])
        ->assertNotFound();

    $this->token->update(['bookings_per_hour' => 1]);
    ($this->bot)()->postJson('/api/bot/v1/bookings', ['customer_id' => $this->customer->id, 'date' => '2026-10-20', 'start' => '09:00', 'problem' => 'Noise'])
        ->assertStatus(429);
});

test('the bot has no other endpoints, cannot sign in and has a request limit', function () {
    ($this->bot)()->getJson('/api/bot/v1/invoices')->assertNotFound();
    ($this->bot)()->deleteJson('/api/bot/v1/customers')->assertStatus(405);
    ($this->bot)()->getJson(route('invoices.index'))->assertUnauthorized();

    $botUser = User::query()->find($this->token->user_id);
    $this->actingAs($botUser)->get(route('jobs.index'))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->token->update(['requests_per_minute' => 5]);
    foreach (range(1, 5) as $i) {
        ($this->bot)()->getJson('/api/bot/v1/windows?days=1')->assertOk();
    }
    ($this->bot)()->getJson('/api/bot/v1/windows?days=1')->assertStatus(429);
});

test('the Owner creates and revokes tokens; the token is shown once', function () {
    $this->actingAs($this->owner)->post(route('company.booking-bot.store'), ['name' => 'Site', 'bookings_per_hour' => 3, 'requests_per_minute' => 20])
        ->assertRedirect(route('company.booking-bot'))
        ->assertSessionHas('bot_token_plain');

    $token = BotToken::withoutCompanyScope()->where('name', 'Site')->sole();
    expect($token->bookings_per_hour)->toBe(3);

    $this->delete(route('company.booking-bot.destroy', $token))->assertRedirect();
    expect($token->fresh()->revoked_at)->not->toBeNull();

    $admin = memberOf($this->company, UserRole::Admin);
    $this->actingAs($admin)->get(route('company.booking-bot'))->assertForbidden();
});
