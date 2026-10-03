<?php

use App\Enums\CustomerType;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\NameAvatar;
use Inertia\Testing\AssertableInertia as Assert;

test('name suggestions support Latin names and leave ambiguous or unknown names neutral', function () {
    expect(NameAvatar::suggest('James'))->toBe('man')
        ->and(NameAvatar::suggest(' Maria '))->toBe('woman')
        ->and(NameAvatar::suggest('José'))->toBe('man')
        ->and(NameAvatar::suggest('Alex'))->toBe('neutral')
        ->and(NameAvatar::suggest('Kim'))->toBe('neutral')
        ->and(NameAvatar::suggest('NewUnknownName'))->toBe('neutral')
        ->and(NameAvatar::suggest(null))->toBe('neutral')
        ->and(NameAvatar::suggest('Иван'))->toBe('neutral');
});

test('saved icon choices survive a rename and edits that omit the icon', function () {
    $company = Company::factory()->create();
    $this->actingAs(memberOf($company, UserRole::Owner));
    $customer = Customer::factory()->for($company)->create(['first_name' => 'James', 'avatar_style' => 'neutral']);

    $this->get(route('customers.edit', $customer))->assertInertia(fn (Assert $page) => $page
        ->where('customer.avatar_style', 'neutral')->where('customer.avatar_icon', 'neutral'));
    $this->put(route('customers.update', $customer), ['type' => 'residential', 'first_name' => 'Maria', 'phones' => [], 'emails' => [], 'notes' => 'Do not call: baby sleeping.'])
        ->assertSessionHasNoErrors();
    expect($customer->fresh()->avatarIcon())->toBe('neutral');
    $this->put(route('customers.update', $customer), ['type' => 'residential', 'first_name' => 'Maria', 'avatar_style' => 'man', 'phones' => [], 'emails' => []])
        ->assertSessionHasNoErrors();
    expect($customer->fresh()->avatarIcon())->toBe('man');
    $customer->forceFill(['type' => CustomerType::Commercial])->save();
    expect($customer->fresh()->avatarIcon())->toBe('business');
});

test('the suggestion endpoint requires office access and validates input', function () {
    $company = Company::factory()->create();
    $this->actingAs(memberOf($company, UserRole::Owner));
    $this->getJson(route('customers.avatar', ['first_name' => 'Maria']))->assertOk()->assertJsonPath('icon', 'woman');
    $this->getJson(route('customers.avatar', ['first_name' => str_repeat('a', 101)]))->assertUnprocessable();
    $this->actingAs(memberOf($company, UserRole::Technician));
    $this->getJson(route('customers.avatar', ['first_name' => 'Maria']))->assertForbidden();
});

test('customer context follows booking and assigned jobs without exposing another company', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, UserRole::Owner);
    $tech = memberOf($company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $company->id]);
    $customer = Customer::factory()->for($company)->withPhone()->create(['first_name' => 'Maria', 'notes' => 'Text only. Please arrive on time.']);
    $property = Property::factory()->for($customer)->create();
    $job = ServiceJob::factory()->for($property)->withVisit($tech)->create(['brand_id' => $brand->id]);
    $this->actingAs($owner);
    $this->get(route('jobs.create', ['customer_id' => $customer->id]))->assertInertia(fn (Assert $page) => $page
        ->where('customer.notes', 'Text only. Please arrive on time.')->where('customer.avatar_icon', 'woman')->has('customer.jobs', 1));
    $this->getJson(route('jobs.lookup', ['search' => 'Maria']))->assertJsonPath('customers.0.notes', 'Text only. Please arrive on time.');
    $this->actingAs($tech);
    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('job.customer.notes', 'Text only. Please arrive on time.')->where('job.customer.avatar_icon', 'woman'));
    $this->get(route('customers.show', $customer))->assertInertia(fn (Assert $page) => $page->where('customer.notes', 'Text only. Please arrive on time.'));
    $foreign = Company::factory()->create();
    $this->actingAs(memberOf($foreign, UserRole::Owner));
    $this->get(route('customers.show', $customer))->assertNotFound();
    $this->get(route('jobs.show', $job))->assertNotFound();
    $this->getJson(route('jobs.lookup', ['search' => 'Maria']))->assertJsonPath('customers', []);
});
