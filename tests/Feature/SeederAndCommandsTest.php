<?php

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\DemoSeeder;

test('the demo seeder builds two isolated companies', function () {
    $this->seed(DemoSeeder::class);

    expect(Company::count())->toBe(2)
        ->and(User::where('email', 'admin@example.com')->sole()->is_super_admin)->toBeTrue()
        ->and(Membership::withoutCompanyScope()->where('user_id', User::where('email', 'tech@example.com')->value('id'))->count())->toBe(2);

    $this->actingAs(User::where('email', 'owner@example.com')->sole())
        ->get(route('brands.index'))
        ->assertInertia(fn ($page) => $page->has('brands', 2));
});

test('a super-admin can be created from the command line', function () {
    $this->artisan('app:create-super-admin', ['--email' => 'root@example.com', '--name' => 'Root'])
        ->expectsQuestion('Password', 'a-long-secret-password')
        ->assertSuccessful();

    $user = User::where('email', 'root@example.com')->sole();

    expect($user->is_super_admin)->toBeTrue()
        ->and($user->memberships()->count())->toBe(0);
});
