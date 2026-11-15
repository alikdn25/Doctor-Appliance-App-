<?php

use App\Enums\UserRole;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Services\AI\AiUnavailable;
use Tests\Fakes\FakeAiTransport;

/**
 * "Paste from message" on Book customer: the text is split into the form's fields by the AI provider, or by the
 * basic parser when there is none (or it fails).
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA']);
    $this->owner = memberOf($this->company);
    $this->actingAs($this->owner);
    config(['ai.key' => null]);
});

function fakeAi(?Closure $answer): void
{
    if ($answer === null) {
        config(['ai.key' => null]);

        return;
    }

    FakeAiTransport::install(fn (array $request) => $answer($request['messages'][0]['content'], $request['schema']));
}

test('without an AI provider the basic parser fills phone, email, address, appliance and the problem', function () {
    $text = "Anna Kim\n604-555-0199\nanna@example.com\n123 Main St, Surrey, BC V3T 1A1\nTenant Bob 604 555 0177\nDishwasher not draining, error E24";

    $this->postJson(route('jobs.parse-message'), ['text' => $text])
        ->assertOk()
        ->assertJsonPath('source', 'basic')
        ->assertJsonPath('fields.first_name', 'Anna')
        ->assertJsonPath('fields.last_name', 'Kim')
        ->assertJsonPath('fields.phone', '+16045550199')
        ->assertJsonPath('fields.email', 'anna@example.com')
        ->assertJsonPath('fields.job_address.line1', '123 Main St')
        ->assertJsonPath('fields.job_address.city', 'Surrey')
        ->assertJsonPath('fields.job_address.region', 'BC')
        ->assertJsonPath('fields.occupant_phone', '+16045550177')
        ->assertJsonPath('fields.occupant_name', 'Bob')
        ->assertJsonPath('fields.appliance_type', 'dishwasher')
        ->assertJsonPath('fields.problem', 'Dishwasher not draining, error E24')
        ->assertJsonPath('customer', null);
});

test('the AI answer is cleaned up: phones in E.164, unknown appliance types and bad emails dropped', function () {
    fakeAi(function (string $text, array $schema) {
        expect($schema['additionalProperties'])->toBeFalse()->and($text)->toContain('fridge');

        return [
            'first_name' => ' Sam ', 'last_name' => 'Lee', 'company_name' => null, 'phone' => '(604) 555-0188',
            'email' => 'not an email', 'job_address' => ['line1' => '9 Oak Rd', 'unit' => '4', 'city' => 'Burnaby', 'region' => 'BC', 'postal_code' => null],
            'billing_address' => ['line1' => '', 'unit' => null, 'city' => null, 'region' => null, 'postal_code' => null],
            'occupant_name' => null, 'occupant_phone' => null, 'access_notes' => null,
            'appliance_type' => 'toaster', 'appliance_brand' => 'LG', 'problem' => 'Fridge warm',
        ];
    });

    $this->postJson(route('jobs.parse-message'), ['text' => 'Sam Lee fridge warm 604 555 0188, 9 Oak Rd #4 Burnaby'])
        ->assertOk()
        ->assertJsonPath('source', 'ai')
        ->assertJsonPath('fields.first_name', 'Sam')
        ->assertJsonPath('fields.phone', '+16045550188')
        ->assertJsonPath('fields.email', null)
        ->assertJsonPath('fields.job_address.unit', '4')
        ->assertJsonPath('fields.billing_address', null)
        ->assertJsonPath('fields.appliance_type', null)
        ->assertJsonPath('fields.problem', 'Fridge warm');
});

test('when the AI fails the basic parser answers', function () {
    fakeAi(fn () => throw new AiUnavailable('timeout'));

    $this->postJson(route('jobs.parse-message'), ['text' => "Call 604 555 0100\nWasher leaking"])
        ->assertOk()
        ->assertJsonPath('source', 'basic')
        ->assertJsonPath('fields.phone', '+16045550100')
        ->assertJsonPath('fields.appliance_type', 'washer');
});

test('a known phone brings the customer from this company only', function () {
    $mine = Customer::factory()->for($this->company)->create(['first_name' => 'Known']);
    inCompany($this->company, fn () => $mine->phones()->create(['label' => 'mobile', 'number' => '+16045550123', 'is_primary' => true]));
    $other = Company::factory()->create();
    $theirs = Customer::factory()->for($other)->create();
    inCompany($other, fn () => $theirs->phones()->create(['label' => 'mobile', 'number' => '+16045550999', 'is_primary' => true]));

    $this->postJson(route('jobs.parse-message'), ['text' => '604 555 0123 oven broken'])
        ->assertJsonPath('customer.id', $mine->id);
    $this->postJson(route('jobs.parse-message'), ['text' => '604 555 0999 oven broken'])
        ->assertJsonPath('customer', null);
});

test('people who cannot create jobs cannot use it', function () {
    $collector = memberOf($this->company, UserRole::Collector);
    $this->actingAs($collector)->postJson(route('jobs.parse-message'), ['text' => 'hello'])->assertForbidden();
});

test('it goes through the AI service on the booking model and is logged', function () {
    config(['ai.models.booking' => 'claude-haiku-5-5']);
    $fake = FakeAiTransport::install(fn () => throw new AiUnavailable('Down'));

    $this->postJson(route('jobs.parse-message'), ['text' => '604 555 0100 washer'])->assertJsonPath('source', 'basic');

    expect($fake->requests[0]['model'])->toBe('claude-haiku-5-5')
        ->and(AiRequest::withoutCompanyScope()->sole()->feature)->toBe('paste_message');
});

test('the basic parser reads a one-line message: name before the phone, address up to the period, the rest as the problem', function () {
    $this->postJson(route('jobs.parse-message'), ['text' => 'John Smith 604-555-0123, 123 Main St, Vancouver. Fridge not cooling'])
        ->assertOk()
        ->assertJsonPath('source', 'basic')
        ->assertJsonPath('fields.first_name', 'John')
        ->assertJsonPath('fields.last_name', 'Smith')
        ->assertJsonPath('fields.phone', '+16045550123')
        ->assertJsonPath('fields.job_address.line1', '123 Main St')
        ->assertJsonPath('fields.job_address.city', 'Vancouver')
        ->assertJsonPath('fields.appliance_type', 'refrigerator')
        ->assertJsonPath('fields.problem', 'Fridge not cooling');
});

test('the basic parser leaves the appliance empty when none is named', function () {
    $this->postJson(route('jobs.parse-message'), ['text' => 'Jane Doe 778-555-0142, 45 Oak Ave, Burnaby. Not working, makes a loud noise'])
        ->assertOk()
        ->assertJsonPath('source', 'basic')
        ->assertJsonPath('fields.first_name', 'Jane')
        ->assertJsonPath('fields.last_name', 'Doe')
        ->assertJsonPath('fields.phone', '+17785550142')
        ->assertJsonPath('fields.job_address.line1', '45 Oak Ave')
        ->assertJsonPath('fields.job_address.city', 'Burnaby')
        ->assertJsonPath('fields.appliance_type', null)
        ->assertJsonPath('fields.problem', 'Not working, makes a loud noise');
});

test('a label before the phone is not taken for a name', function () {
    $this->postJson(route('jobs.parse-message'), ['text' => 'Call 604 555 0100 washer leaking'])
        ->assertJsonPath('fields.first_name', null)
        ->assertJsonPath('fields.phone', '+16045550100')
        ->assertJsonPath('fields.problem', 'washer leaking');
});

test('a saved customer with the pasted phone but another name is offered, not picked', function () {
    $saved = Customer::factory()->for($this->company)->create(['first_name' => 'Robert', 'last_name' => 'Brown', 'company_name' => null]);
    inCompany($this->company, fn () => $saved->phones()->create(['label' => 'mobile', 'number' => '+17785550142', 'is_primary' => true]));

    $this->postJson(route('jobs.parse-message'), ['text' => 'Jane Doe 778-555-0142, 45 Oak Ave, Burnaby. Not working'])
        ->assertOk()
        ->assertJsonPath('customer', null)
        ->assertJsonPath('phone_customer.id', $saved->id)
        ->assertJsonPath('phone_customer.display_name', 'Robert Brown')
        ->assertJsonPath('fields.first_name', 'Jane')
        ->assertJsonPath('fields.job_address.line1', '45 Oak Ave');

    // The same name (or no name at all) still picks the saved customer.
    $this->postJson(route('jobs.parse-message'), ['text' => 'Robert Brown 778-555-0142 oven broken'])
        ->assertJsonPath('customer.id', $saved->id)
        ->assertJsonPath('phone_customer', null);
    $this->postJson(route('jobs.parse-message'), ['text' => '778-555-0142 oven broken'])
        ->assertJsonPath('customer.id', $saved->id)
        ->assertJsonPath('phone_customer', null);
});
