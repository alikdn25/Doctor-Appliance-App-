<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['name' => 'Doctor Appliance']);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Owner', 'two_factor_confirmed_at' => null]);
    $this->admin = memberOf($this->company, UserRole::Admin);
    $this->tech1 = memberOf($this->company, UserRole::Technician, ['name' => 'Tom Tech']);
    $this->tech2 = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();

    $this->job1 = ServiceJob::factory()->for($property)->withVisit($this->tech1)->create(['brand_id' => $brand->id]);
    $this->job2 = ServiceJob::factory()->for($property)->withVisit($this->tech2)->create(['brand_id' => $brand->id]);
    $this->job3 = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id]);

    $other = Company::factory()->create();
    $this->foreignCustomer = Customer::factory()->for($other)->create();
    $this->foreignJob = ServiceJob::factory()->for(Property::factory()->for($this->foreignCustomer))->create();
});

function jobIdsOn($test, $user): array
{
    $ids = [];
    $test->actingAs($user)->get(route('jobs.index', ['tab' => 'all']))->assertInertia(function (Assert $page) use (&$ids) {
        $ids = collect($page->toArray()['props']['jobs']['data'])->pluck('id')->sort()->values()->all();
    });

    return $ids;
}

test('each role sees exactly its own data', function () {
    $all = collect([$this->job1->id, $this->job2->id, $this->job3->id])->sort()->values()->all();

    expect(jobIdsOn($this, $this->owner))->toBe($all)
        ->and(jobIdsOn($this, $this->admin))->toBe($all);

    // Technicians: only the jobs they are on.
    $this->actingAs($this->tech1)->get(route('jobs.index'))->assertRedirect(route('jobs.mine'));
    $this->actingAs($this->tech1)->get(route('jobs.show', $this->job1))->assertOk();
    $this->actingAs($this->tech1)->get(route('jobs.show', $this->job2))->assertForbidden();
    $this->actingAs($this->tech1)->get(route('jobs.show', $this->job3))->assertForbidden();
    $this->actingAs($this->tech1)->get(route('customers.index'))->assertForbidden();

    // Nobody sees another company's records.
    foreach ([$this->owner, $this->admin, $this->tech1] as $user) {
        $this->actingAs($user)->get(route('jobs.show', $this->foreignJob))->assertNotFound();
        $this->actingAs($user)->get(route('customers.show', $this->foreignCustomer))->assertNotFound();
    }
});

test('signing out and in as someone else shows only the new person\'s data', function () {
    $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect();
    expect(Auth::id())->toBe($this->owner->id);
    expect(jobIdsOn($this, Auth::user()))->toHaveCount(3);

    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => $this->tech1->email, 'password' => 'password'])->assertRedirect();
    expect(Auth::id())->toBe($this->tech1->id);

    $this->get(route('jobs.index'))->assertRedirect(route('jobs.mine'));
    $this->get(route('jobs.show', $this->job2))->assertForbidden();
});

test('a visitor who is not signed in is never signed in automatically', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get(route('jobs.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('signing in without "remember me" drops someone else\'s remember cookie and clears the page history', function () {
    $recaller = Auth::guard('web')->getRecallerName();

    $response = $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password']);

    $response->assertCookieExpired($recaller);
    expect(session('inertia.clear_history'))->toBeTrue();
});

test('pages of a signed-in person are never cached by the browser', function () {
    $this->actingAs($this->owner)->get(route('jobs.index'))
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('the menu always says who is signed in and in which company', function () {
    $this->actingAs($this->tech1)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.name', 'Tom Tech')
        ->where('auth.user.email', $this->tech1->email)
        ->where('auth.company.name', 'Doctor Appliance')
        ->where('auth.role.value', 'technician'));
});
