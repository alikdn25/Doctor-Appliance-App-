<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Creates a user with a membership in the given (or a new) company.
 */
function memberOf(?Company $company = null, UserRole $role = UserRole::Owner, array $attributes = []): User
{
    return User::factory()->memberOf($company ?? Company::factory()->create(), $role)->create($attributes);
}

/**
 * Runs a callback in the tenant context of the company (for arranging/asserting data).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inCompany(Company $company, Closure $callback): mixed
{
    return app(CurrentCompany::class)->runAs($company, $callback);
}

/**
 * Estimate/invoice form payload (prices in dollars, as the form sends them).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function documentPayload(array $overrides = []): array
{
    return array_merge([
        'issued_on' => now('America/Vancouver')->toDateString(),
        'tax_rate_ids' => [],
        'items' => [
            ['description' => 'Diagnosis', 'quantity' => '1', 'unit_price' => '95.00', 'taxable' => true],
            ['description' => 'Drain pump', 'quantity' => '1', 'unit_price' => '185.50', 'taxable' => true],
        ],
    ], $overrides);
}
