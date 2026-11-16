<?php

use App\Enums\UserRole;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Services\AI\AiUnavailable;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeAiTransport;

/**
 * Quick booking: the text is read by the AI (AI_MODEL_BOOKING) into the booking form's fields, an existing customer is
 * found by phone, then by name and address, and nothing is saved until the person saves the form.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    $this->actingAs($this->owner);
    config(['ai.models.booking' => 'claude-haiku-5-5', 'ai.daily_limit' => 50]);
});

/** A full answer of the AI with the given fields changed. */
function bookingAnswer(array $fields = []): array
{
    return array_merge([
        'first_name' => 'Anna', 'last_name' => 'Kim', 'phone' => '604-555-0199', 'email' => 'Anna@Example.com',
        'address' => ['line1' => '123 Main St', 'city' => 'Surrey', 'region' => 'BC', 'postal_code' => 'V3T 1A1'],
        'unit' => '4', 'buzzer' => '1234', 'appliance_type' => 'washer', 'brand' => 'LG', 'model' => 'WM3900',
        'problem' => 'Washer not draining', 'date' => null, 'arrival_now' => false, 'arrival_start' => null,
        'arrival_end' => null, 'notes' => null, 'uncertain_fields' => [],
    ], $fields);
}

test('the text is parsed into the form fields with the booking model', function () {
    $fake = FakeAiTransport::install(bookingAnswer(['date' => '2026-11-16', 'arrival_start' => '09:00', 'arrival_end' => '11:00', 'notes' => 'Dog in the yard']));

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'Анна Ким 604 555 0199, стиралка LG не сливает, завтра утром'])
        ->assertOk()
        ->assertJsonPath('fields.first_name', 'Anna')
        ->assertJsonPath('fields.phone', '+16045550199')
        ->assertJsonPath('fields.email', 'anna@example.com')
        ->assertJsonPath('fields.address.line1', '123 Main St')
        ->assertJsonPath('fields.unit', '4')
        ->assertJsonPath('fields.buzzer', '1234')
        ->assertJsonPath('fields.appliance_type', 'washer')
        ->assertJsonPath('fields.brand', 'LG')
        ->assertJsonPath('fields.model', 'WM3900')
        ->assertJsonPath('fields.problem', 'Washer not draining')
        ->assertJsonPath('fields.date', '2026-11-16')
        ->assertJsonPath('fields.arrival_start', '09:00')
        ->assertJsonPath('fields.arrival_end', '11:00')
        ->assertJsonPath('fields.notes', 'Dog in the yard')
        ->assertJsonPath('fields.uncertain_fields', [])
        ->assertJsonPath('customer', null);

    expect($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]['model'])->toBe('claude-haiku-5-5')
        ->and($fake->requests[0]['schema']['additionalProperties'])->toBeFalse()
        ->and($fake->requests[0]['messages'][0]['content'])->toContain('стиралка');
    expect(AiRequest::withoutCompanyScope()->sole())->feature->toBe('quick_booking')->model->toBe('claude-haiku-5-5');
});

test('a Russian dictation comes back in English, with digits, a Latin model number and the oven type', function () {
    $fake = FakeAiTransport::install(bookingAnswer([
        'first_name' => 'Bobby', 'last_name' => 'Chan', 'phone' => '2367885588', 'email' => '',
        'address' => ['line1' => '', 'city' => '', 'region' => '', 'postal_code' => ''], 'unit' => '', 'buzzer' => '',
        'appliance_type' => 'oven', 'brand' => '', 'model' => 'yg 502 s', 'problem' => 'Oven repair',
    ]));
    $text = 'Смотри значит забронируй бобби чан только на английском пиши бобби чан телефон двести 30 шесть семь восемь '
        .'восемь пять пять восемь восемь, нужно отремонтировать духовку, номер модели уай джи пять ноль два эс';

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => $text])
        ->assertOk()
        ->assertJsonPath('fields.first_name', 'Bobby')
        ->assertJsonPath('fields.last_name', 'Chan')
        ->assertJsonPath('fields.phone', '+12367885588')
        ->assertJsonPath('fields.appliance_type', 'oven')
        ->assertJsonPath('fields.problem', 'Oven repair')
        ->assertJsonPath('fields.model', 'YG502S')
        ->assertJsonPath('fields.address', null)
        ->assertJsonPath('fields.uncertain_fields', []);

    // The prompt carries the rules and this very example.
    expect($fake->requests[0]['system'])
        ->toContain('Write every field value in English')
        ->toContain('"бобби чан" -> first_name "Bobby"')
        ->toContain('"двести 30 шесть" -> "236"')
        ->toContain('уай/игрек -> Y')
        ->toContain('"уай джи пять ноль два эс" -> "YG502S"')
        ->toContain('духовка/духовой шкаф -> oven')
        ->toContain('"забронируй"')
        ->toContain('model "YG502S"')
        ->toContain('problem "Oven repair"');
});

test('the AI says which fields it is not sure about', function () {
    $fake = FakeAiTransport::install(bookingAnswer(['uncertain_fields' => ['model', 'last_name', 'model', 'toaster', 42, ['x']]]));

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])
        ->assertOk()
        ->assertJsonPath('fields.uncertain_fields', ['model', 'last_name']);

    $schema = $fake->requests[0]['schema'];
    expect($schema['required'])->toContain('uncertain_fields')
        ->and($schema['properties']['uncertain_fields']['type'])->toBe('array')
        ->and($schema['properties']['uncertain_fields']['items']['enum'])->toContain('model', 'phone', 'arrival');
});

test('without uncertain_fields in the answer the list is empty', function () {
    $answer = bookingAnswer();
    unset($answer['uncertain_fields']);
    FakeAiTransport::install($answer);

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])
        ->assertOk()
        ->assertJsonPath('fields.uncertain_fields', []);
});

test('dates count in the company time zone', function () {
    // 05:30 UTC on Nov 15 is still Nov 14 in Vancouver.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 05:30:00', 'UTC'));
    $fake = FakeAiTransport::install(bookingAnswer());

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'tomorrow'])->assertOk();

    expect($fake->requests[0]['system'])->toContain('today is 2026-11-14 (Saturday)')
        ->toContain('America/Vancouver');
});

test('what is not in the text stays empty and bad values are dropped', function () {
    FakeAiTransport::install(bookingAnswer([
        'first_name' => ' ', 'phone' => 'call me', 'email' => 'nope', 'address' => ['line1' => '', 'city' => null, 'region' => null, 'postal_code' => null],
        'appliance_type' => 'toaster', 'date' => '2026-02-30', 'arrival_start' => '25:00', 'arrival_end' => '11:00',
    ]));

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'something broke'])
        ->assertOk()
        ->assertJsonPath('fields.first_name', null)
        ->assertJsonPath('fields.phone', null)
        ->assertJsonPath('fields.email', null)
        ->assertJsonPath('fields.address', null)
        ->assertJsonPath('fields.appliance_type', null)
        ->assertJsonPath('fields.date', null)
        ->assertJsonPath('fields.arrival_start', null)
        ->assertJsonPath('fields.arrival_end', null);
});

test('an existing customer is found by phone, never in another company', function () {
    $mine = Customer::factory()->for($this->company)->create(['first_name' => 'Known']);
    inCompany($this->company, fn () => $mine->phones()->create(['label' => 'mobile', 'number' => '+16045550199', 'is_primary' => true]));
    $other = Company::factory()->create();
    $theirs = Customer::factory()->for($other)->create();
    inCompany($other, fn () => $theirs->phones()->create(['label' => 'mobile', 'number' => '+16045550177', 'is_primary' => true]));
    FakeAiTransport::install(bookingAnswer());

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])
        ->assertJsonPath('customer.id', $mine->id)
        ->assertJsonPath('matched_by', 'phone');

    FakeAiTransport::install(bookingAnswer(['phone' => '604 555 0177']));

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])->assertJsonPath('customer', null);
});

test('without a phone match the customer is found by name and address', function () {
    $mine = Customer::factory()->for($this->company)->create(['first_name' => 'Anna', 'last_name' => 'Kim']);
    Property::factory()->for($mine)->create(['line1' => '123 Main St']);
    // Same name at another address is not the same customer.
    $namesake = Customer::factory()->for($this->company)->create(['first_name' => 'Anna', 'last_name' => 'Kim']);
    Property::factory()->for($namesake)->create(['line1' => '9 Oak Rd']);
    FakeAiTransport::install(bookingAnswer(['phone' => '604 555 0100', 'address' => ['line1' => '123 main st', 'city' => 'Surrey', 'region' => null, 'postal_code' => null]]));

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])
        ->assertJsonPath('customer.id', $mine->id)
        ->assertJsonPath('matched_by', 'name_address');
});

test('parsing saves nothing: no customer, no job', function () {
    FakeAiTransport::install(bookingAnswer());

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'Anna Kim 604 555 0199'])->assertOk();

    expect(Customer::withoutCompanyScope()->count())->toBe(0)
        ->and(ServiceJob::withoutCompanyScope()->count())->toBe(0);
});

test('a booking is only saved by a signed-in person pressing Save', function () {
    auth()->logout();

    $this->post(route('jobs.store'), ['quick_booking' => true])->assertRedirect(route('login'));
    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])->assertUnauthorized();
    expect(ServiceJob::withoutCompanyScope()->count())->toBe(0);
});

test('when the AI fails the message is shown and nothing is saved', function () {
    FakeAiTransport::install(fn () => throw new AiUnavailable('Overloaded'));

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'Anna'])
        ->assertStatus(503)
        ->assertJsonPath('message', __('jobs.quick_booking.failed'));
});

test('over the daily limit the AI is not asked', function () {
    config(['ai.daily_limit' => 1]);
    $fake = FakeAiTransport::install(bookingAnswer());

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'one'])->assertOk();
    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'two'])
        ->assertStatus(429)
        ->assertJsonPath('message', __('jobs.quick_booking.limit'));

    expect($fake->requests)->toHaveCount(1);
});

test('without an API key it says the AI is not set up', function () {
    config(['ai.key' => null]);

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])
        ->assertStatus(503)
        ->assertJsonPath('message', __('jobs.quick_booking.not_set_up'));
});

test('Owner and Office can use it, a technician cannot', function () {
    FakeAiTransport::install(bookingAnswer());

    $this->get(route('jobs.quick-booking'))->assertOk();

    $office = memberOf($this->company, UserRole::Admin);
    $this->actingAs($office)->get(route('jobs.quick-booking'))->assertOk();
    $this->actingAs($office)->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])->assertOk();

    $technician = memberOf($this->company, UserRole::Technician);
    $this->actingAs($technician)->get(route('jobs.quick-booking'))->assertForbidden();
    $this->actingAs($technician)->postJson(route('jobs.quick-booking.parse'), ['text' => 'x'])->assertForbidden();
});

test('the API key is never sent to the browser', function () {
    config(['ai.key' => 'sk-ant-secret-key']);

    $page = $this->get(route('jobs.quick-booking'))->assertOk()->getContent();
    expect($page)->not->toContain('sk-ant-secret-key');
});
