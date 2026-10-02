<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Owner));

    $this->existing = Customer::factory()->for($this->company)
        ->withPhone('604-555-0142')->withEmail('jane@example.com')
        ->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
});

test('a customer with the same phone in another format is reported', function () {
    $this->getJson(route('customers.duplicates', ['phones' => ['(604) 555 0142']]))
        ->assertOk()
        ->assertJsonPath('duplicates.0.id', $this->existing->id)
        ->assertJsonPath('duplicates.0.display_name', 'Jane Cooper')
        ->assertJsonPath('duplicates.0.matches', ['+16045550142']);
});

test('a customer with the same email in another case is reported once', function () {
    $this->getJson(route('customers.duplicates', ['phones' => ['6045550142'], 'emails' => ['JANE@example.com']]))
        ->assertOk()
        ->assertJsonCount(1, 'duplicates')
        ->assertJsonPath('duplicates.0.matches', ['+16045550142', 'jane@example.com']);
});

test('the customer being edited is ignored', function () {
    $this->getJson(route('customers.duplicates', ['phones' => ['604-555-0142'], 'ignore' => $this->existing->id]))
        ->assertJsonCount(0, 'duplicates');
});

test('deleted customers and other companies are not reported', function () {
    $other = Company::factory()->create();
    Customer::factory()->for($other)->withPhone('604-555-0199')->create();
    inCompany($this->company, fn () => $this->existing->delete());

    $this->getJson(route('customers.duplicates', ['phones' => ['604-555-0142', '604-555-0199']]))
        ->assertJsonCount(0, 'duplicates');
});

test('a duplicate is only a warning and does not block saving', function () {
    $this->post(route('customers.store'), [
        'type' => 'residential',
        'first_name' => 'Jane',
        'last_name' => 'Cooper',
        'phones' => [['label' => 'mobile', 'number' => '604-555-0142']],
        'emails' => [['label' => 'personal', 'email' => 'jane@example.com']],
    ])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Customer::count()))->toBe(2);
});
