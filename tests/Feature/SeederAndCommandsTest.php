<?php

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\DemoSeeder;

test('the demo seeder builds isolated companies in Canada and the USA', function () {
    $this->seed(DemoSeeder::class);

    expect(Company::count())->toBe(3)
        ->and(Company::where('slug', 'lone-star-appliance-repair')->sole())
        ->country->toBe('US')->currency->toBe('USD')->locale->toBe('en-US')
        ->and(User::where('email', 'admin@example.com')->sole()->is_super_admin)->toBeTrue()
        ->and(Membership::withoutCompanyScope()->where('user_id', User::where('email', 'tech@example.com')->value('id'))->count())->toBe(2);

    $this->actingAs(User::where('email', 'owner@example.com')->sole())
        ->get(route('brands.index'))
        ->assertInertia(fn ($page) => $page->has('brands', 2));

    $this->actingAs(User::where('email', 'tech@example.com')->sole())
        ->get(route('jobs.mine'))
        ->assertInertia(fn ($page) => $page->has('visits', 1));

    // The US company: one sales tax, USD amounts, E.164 phones, ZIP codes.
    $this->actingAs(User::where('email', 'us@example.com')->sole())
        ->get(route('invoices.index', ['status' => 'paid']))
        ->assertInertia(fn ($page) => $page
            ->where('invoices.data.0.currency', 'USD')
            ->where('auth.company.address.postal_label', 'ZIP code'));
});

test('a super-admin can be created from the command line', function () {
    $this->artisan('app:create-super-admin', ['--email' => 'root@example.com', '--name' => 'Root'])
        ->expectsQuestion('Password', 'a-long-secret-password')
        ->assertSuccessful();

    $user = User::where('email', 'root@example.com')->sole();

    expect($user->is_super_admin)->toBeTrue()
        ->and($user->memberships()->count())->toBe(0);
});
