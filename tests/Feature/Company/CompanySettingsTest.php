<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;

beforeEach(function () {
    $this->company = Company::factory()->create(['name' => 'Old Name']);
    $this->actingAs(memberOf($this->company, UserRole::Owner));
});

function settingsPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Doctor Appliance Group',
        'timezone' => 'America/Vancouver',
        'currency' => 'CAD',
        'invoice_prefix' => 'DA-',
        'invoice_next_number' => 1001,
        'estimate_prefix' => 'EST-',
        'estimate_next_number' => 50,
        'business_hours' => Company::defaultBusinessHours(),
        'travel_buffer_minutes' => 20,
    ], $overrides);
}

test('the owner can update company settings', function () {
    $this->put(route('company.settings.update'), settingsPayload([
        'business_hours' => ['sat' => ['closed' => false, 'open' => '09:00', 'close' => '13:00']],
    ]))->assertRedirect(route('company.settings.edit'));

    $company = $this->company->fresh();

    expect($company)
        ->name->toBe('Doctor Appliance Group')
        ->invoice_prefix->toBe('DA-')
        ->invoice_next_number->toBe(1001)
        ->travel_buffer_minutes->toBe(20)
        ->and($company->business_hours['sat'])->toEqual(['closed' => false, 'open' => '09:00', 'close' => '13:00'])
        ->and($company->business_hours['sun'])->toEqual(['closed' => true, 'open' => null, 'close' => null])
        ->and(AuditLog::where('action', 'company.settings_updated')->exists())->toBeTrue();
});

test('company settings are validated', function () {
    $this->put(route('company.settings.update'), settingsPayload([
        'timezone' => 'Mars/Olympus',
        'currency' => 'EUR',
        'invoice_next_number' => 0,
        'business_hours' => ['mon' => ['closed' => false, 'open' => '17:00', 'close' => '08:00']],
    ]))->assertSessionHasErrors(['timezone', 'currency', 'invoice_next_number', 'business_hours.mon.close']);

    expect($this->company->fresh()->name)->toBe('Old Name');
});

test('the travel buffer must be between 0 and 240 minutes', function () {
    $this->put(route('company.settings.update'), settingsPayload(['travel_buffer_minutes' => 300]))
        ->assertSessionHasErrors('travel_buffer_minutes');
    $this->put(route('company.settings.update'), settingsPayload(['travel_buffer_minutes' => -5]))
        ->assertSessionHasErrors('travel_buffer_minutes');
});

test('saving settings ends time zone detection from the browser', function () {
    $this->company->update(['timezone_pending' => true]);

    $this->put(route('company.settings.update'), settingsPayload(['timezone' => 'America/Edmonton']));

    expect($this->company->fresh())->timezone->toBe('America/Edmonton')->timezone_pending->toBeFalse();
});
