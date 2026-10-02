<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\TaxRate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Owner));
});

test('an owner can add BC taxes as settings', function () {
    $this->post(route('taxes.store'), ['name' => 'GST', 'rate' => '5', 'is_default' => true])->assertRedirect(route('taxes.index'));
    $this->post(route('taxes.store'), ['name' => 'PST', 'rate' => '7.0000'])->assertRedirect();

    $rates = inCompany($this->company, fn () => TaxRate::orderBy('name')->get());

    expect($rates->pluck('name')->all())->toBe(['GST', 'PST'])
        ->and($rates->pluck('rate')->all())->toBe(['5.0000', '7.0000'])
        ->and($rates->firstWhere('name', 'GST')->is_default)->toBeTrue();

    $this->get(route('taxes.index'))->assertInertia(fn (Assert $page) => $page->has('taxRates', 2)->where('canManage', true));
});

test('several taxes can apply by default (GST and PST in BC)', function () {
    $this->post(route('taxes.store'), ['name' => 'GST', 'rate' => 5, 'is_default' => true]);
    $this->post(route('taxes.store'), ['name' => 'PST', 'rate' => 7, 'is_default' => true]);

    expect(inCompany($this->company, fn () => TaxRate::where('is_default', true)->orderBy('name')->pluck('name')->all()))->toBe(['GST', 'PST']);
});

test('a tax rate can be updated and deleted', function () {
    $tax = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5]);

    $this->put(route('taxes.update', $tax), ['name' => 'GST', 'rate' => 6, 'is_active' => false])->assertRedirect();
    expect($tax->fresh())->rate->toBe('6.0000')->is_active->toBeFalse();

    $this->delete(route('taxes.destroy', $tax))->assertRedirect();
    expect(TaxRate::withoutCompanyScope()->find($tax->id))->toBeNull();
});

test('tax rates are validated', function (array $input, string $error) {
    $this->post(route('taxes.store'), $input)->assertSessionHasErrors($error);
})->with([
    'missing name' => [['rate' => 5], 'name'],
    'negative rate' => [['name' => 'X', 'rate' => -1], 'rate'],
    'over 100' => [['name' => 'X', 'rate' => 101], 'rate'],
    'too precise' => [['name' => 'X', 'rate' => '5.12345'], 'rate'],
]);
