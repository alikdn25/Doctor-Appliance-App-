<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function newCompanyPayload(array $overrides = []): array
{
    return [
        'name' => 'Coastal Repair',
        'country' => 'CA',
        'timezone' => '',
        'currency' => 'CAD',
        'owner_name' => 'Chris',
        'owner_email' => 'chris@example.com',
        ...$overrides,
    ];
}

test('a company created without a time zone waits for the owner\'s browser', function () {
    Notification::fake();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.companies.store'), newCompanyPayload())
        ->assertRedirect();

    // Until the browser reports the zone, the company uses its country's default zone.
    expect(Company::where('name', 'Coastal Repair')->sole())
        ->timezone->toBe('America/Toronto')
        ->timezone_pending->toBeTrue();
});

test('a time zone picked by the super-admin is kept', function () {
    Notification::fake();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.companies.store'), newCompanyPayload(['timezone' => 'America/Edmonton']))
        ->assertRedirect();

    expect(Company::where('name', 'Coastal Repair')->sole())
        ->timezone->toBe('America/Edmonton')
        ->timezone_pending->toBeFalse();
});

test('the owner\'s browser sets the time zone once', function () {
    $company = Company::factory()->create(['timezone_pending' => true]);
    $owner = memberOf($company, UserRole::Owner);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.company.timezone_pending', true));

    $this->from(route('dashboard'))
        ->put(route('company.timezone.detect'), ['timezone' => 'America/Toronto'])
        ->assertRedirect(route('dashboard'));

    expect($company->fresh())->timezone->toBe('America/Toronto')->timezone_pending->toBeFalse()
        ->and(AuditLog::where('action', 'company.timezone_detected')->sole()->changes)
        ->toEqual(['before' => 'America/Vancouver', 'after' => 'America/Toronto']);

    // Later visits (another browser, another place) do not change it again.
    $this->put(route('company.timezone.detect'), ['timezone' => 'Europe/London']);

    expect($company->fresh()->timezone)->toBe('America/Toronto');

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.company.timezone_pending', false));
});

test('only the owner\'s browser is used', function () {
    $company = Company::factory()->create(['timezone_pending' => true]);
    $admin = memberOf($company, UserRole::Admin);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.company.timezone_pending', false));

    $this->put(route('company.timezone.detect'), ['timezone' => 'America/Toronto'])->assertForbidden();

    expect($company->fresh())->timezone->toBe('America/Vancouver')->timezone_pending->toBeTrue();
});

test('the browser time zone must be a real one', function () {
    $company = Company::factory()->create(['timezone_pending' => true]);

    $this->actingAs(memberOf($company, UserRole::Owner))
        ->put(route('company.timezone.detect'), ['timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors('timezone');

    expect($company->fresh()->timezone_pending)->toBeTrue();
});

test('a super-admin impersonating the owner does not set the time zone', function () {
    $company = Company::factory()->create(['timezone_pending' => true]);
    $owner = memberOf($company, UserRole::Owner);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.companies.impersonate', [$company, $owner]));

    $this->put(route('company.timezone.detect'), ['timezone' => 'Europe/Berlin'])->assertRedirect();

    expect($company->fresh())->timezone->toBe('America/Vancouver')->timezone_pending->toBeTrue();
});
