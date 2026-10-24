<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Support\Ai\AiProvider;
use App\Support\Ai\AiUnavailable;
use App\Support\Ai\JsonExtractor;

/**
 * "Paste from message" on Book customer: the text is split into the form's fields by the AI provider, or by the
 * basic parser when there is none (or it fails).
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA']);
    $this->owner = memberOf($this->company);
    $this->actingAs($this->owner);
    config(['services.ai.provider' => null]);
});

function fakeAi(?Closure $answer): void
{
    app()->instance(AiProvider::class, new class($answer) extends AiProvider
    {
        public function __construct(private readonly ?Closure $answer) {}

        public function for(Company $company): ?JsonExtractor
        {
            return $this->answer === null ? null : new class($this->answer) implements JsonExtractor
            {
                public function __construct(private readonly Closure $answer) {}

                public function extract(string $instructions, string $text, array $schema): array
                {
                    return ($this->answer)($text, $schema);
                }
            };
        }
    });
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
