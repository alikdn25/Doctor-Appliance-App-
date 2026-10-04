<?php

use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    $this->office = memberOf($this->company, UserRole::Admin);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create();
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $this->membership = inCompany($this->company, fn () => Membership::query()->where('user_id', $this->office->id)->sole());
});

/** The Owner leaves the Office member only these areas. */
function allowOnly(array $permissions): void
{
    test()->actingAs(test()->owner)->put(route('team.update', test()->membership), [
        'role' => 'admin', 'is_active' => true, 'permissions' => array_map(fn (OfficePermission $p) => $p->value, $permissions),
    ])->assertSessionHasNoErrors();
    // A fresh model, as on a real request: the membership is read again.
    test()->office = test()->office->fresh();
    test()->actingAs(test()->office);
}

test('a new Office member has every office area until the Owner changes it', function () {
    expect($this->membership->permissions)->toBeNull()
        ->and($this->membership->officePermissions())->toBe(OfficePermission::values());

    $this->actingAs($this->owner)->get(route('team.index'))->assertInertia(fn (Assert $page) => $page
        ->has('permissions', count(OfficePermission::cases()))
        ->where('members', fn ($members) => collect($members)->firstWhere('role', 'admin')['permissions'] === OfficePermission::values()));

    $this->actingAs($this->office)->get(route('calendar'))->assertOk();
    $this->get(route('invoices.index'))->assertOk();
    $this->get(route('messages.index'))->assertOk();
    $this->get(route('reports.index'))->assertOk();
});

test('without scheduling the Office member views the calendar and jobs but cannot book, edit or dispatch', function () {
    allowOnly([OfficePermission::Invoices]);

    $this->get(route('calendar'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canSchedule', false)
        ->where('visits', fn ($visits) => collect($visits)->every(fn ($visit) => $visit['movable'] === false)));
    $this->get(route('jobs.create'))->assertForbidden();
    $this->get(route('jobs.index'))->assertOk();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.update', false)->where('can.work', false)->where('can.estimate', false)->where('can.invoice', true)
        ->where('auth.can.viewCalendar', true)->where('auth.can.createJobs', false));
    $visit = $this->job->visits()->first();
    $this->put(route('visits.move', $visit), ['date' => now()->addDay()->toDateString(), 'start_time' => '10:00'])->assertForbidden();
    $this->get(route('home'))->assertRedirect(route('calendar', absolute: false));
});

test('a view-only Office member opens and reads everything but changes nothing; Invoices adds invoicing', function () {
    allowOnly([]);
    $invoice = inCompany($this->company, fn () => Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => $this->company->currency, 'balance' => 10000, 'total' => 10000]));

    // Opens and reads: calendar, jobs, customers (to call them), invoices.
    $this->get(route('calendar'))->assertOk();
    $this->get(route('jobs.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
    $this->get(route('jobs.show', $this->job))->assertOk();
    $this->get(route('customers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
    $this->get(route('customers.show', $this->customer))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
    $this->get(route('invoices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
    $this->get(route('invoices.show', $invoice))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false)->where('can.recordPayment', false));

    // Changes nothing.
    $this->put(route('jobs.status', $this->job), ['status' => 'on_hold'])->assertForbidden();
    $this->put(route('customers.update', $this->customer), ['type' => 'residential', 'first_name' => 'Changed'])->assertForbidden();
    $this->get(route('invoices.start'))->assertForbidden();
    $this->post(route('invoices.store', $this->job), documentPayload())->assertForbidden();
    $this->post(route('payments.store', $invoice), ['amount' => '100.00', 'method' => 'cash'])->assertForbidden();
    expect($this->job->fresh()->status->value)->toBe('scheduled');

    // The Owner ticks Invoices and payments: the same person now invoices and takes payment.
    allowOnly([OfficePermission::Invoices]);
    $this->get(route('invoices.start'))->assertOk();
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $this->post(route('payments.store', $invoice), ['amount' => '100.00', 'method' => 'cash'])->assertSessionHasNoErrors();
});

test('estimates and invoices each need their own permission', function () {
    allowOnly([OfficePermission::Schedule, OfficePermission::Estimates]);

    $this->post(route('estimates.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $this->post(route('invoices.store', $this->job), documentPayload())->assertForbidden();
    $this->get(route('invoices.index'))->assertOk();

    $invoice = inCompany($this->company, fn () => Invoice::factory()->create(['service_job_id' => $this->job->id, 'currency' => $this->company->currency, 'balance' => 10000, 'total' => 10000]));
    $this->post(route('payments.store', $invoice), ['amount' => '100.00', 'method' => 'cash'])->assertForbidden();
    $this->post(route('invoices.send', $invoice), ['email' => 'a@example.com', 'message' => 'x'])->assertForbidden();
    // The technician on the job still invoices and takes payment on site.
    $this->actingAs($this->tech)->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
});

test('customers, inbox, reports, expenses, catalog and team follow their permissions', function () {
    allowOnly([OfficePermission::Schedule]);

    $this->get(route('customers.index'))->assertOk();
    $this->post(route('customers.store'), ['type' => 'residential', 'first_name' => 'New'])->assertForbidden();
    $this->get(route('messages.index'))->assertForbidden();
    $this->get(route('reports.index'))->assertForbidden();
    $this->get(route('cash.index'))->assertForbidden();
    $this->get(route('company.services.edit'))->assertForbidden();
    $this->get(route('company.checklists.edit'))->assertForbidden();
    $this->get(route('team.index'))->assertForbidden();
    $this->get(route('expenses.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('companyView', false));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.viewMessageInbox', false)->where('auth.can.viewReports', false)
        ->where('auth.can.manageTeam', false)->where('auth.can.manageChecklists', false));
});

test('purchase costs, profit and bookkeeper exports stay with the Owner', function () {
    $this->actingAs($this->office)->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('costs', null));
    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('showProfit', false)->where('totals', null));
    $this->get(route('reports.expenses'))->assertForbidden();
    $this->get(route('reports.receipts'))->assertForbidden();

    // An Office member's invoice never stores a purchase price.
    $this->post(route('invoices.store', $this->job), documentPayload(['items' => [
        ['description' => 'Pump', 'quantity' => '1', 'unit_price' => '100.00', 'taxable' => true, 'kind' => 'part', 'unit_cost' => '40.00'],
    ]]))->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => InvoiceItem::sole()->unit_cost))->toBeNull();

    $this->actingAs($this->owner)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page->where('showProfit', true)->has('totals'));
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->whereNot('costs', null));
});

test('only the Owner sets office permissions, and only within their company', function () {
    $other = Company::factory()->create();
    $foreign = memberOf($other, UserRole::Admin);
    $foreignMembership = inCompany($other, fn () => Membership::query()->where('user_id', $foreign->id)->sole());
    // An Office member with the team area manages technicians but cannot hand out office access.
    $this->actingAs($this->office)->put(route('team.update', inCompany($this->company, fn () => Membership::query()->where('user_id', $this->tech->id)->sole())), [
        'role' => 'technician', 'is_active' => true, 'permissions' => ['reports'],
    ])->assertSessionHasErrors('permissions');

    $this->actingAs($this->owner)->put(route('team.update', $this->membership), [
        'role' => 'admin', 'is_active' => true, 'permissions' => ['reports', 'unknown'],
    ])->assertSessionHasErrors('permissions.1');

    $this->actingAs($this->owner)->put(route('team.update', $foreignMembership), [
        'role' => 'admin', 'is_active' => true, 'permissions' => [],
    ])->assertNotFound();
    expect($foreignMembership->fresh()->permissions)->toBeNull();

    // Switching an Office member to Technician clears office access; back to Office starts with everything.
    allowOnly([OfficePermission::Reports]);
    $this->actingAs($this->owner)->put(route('team.update', $this->membership), ['role' => 'technician', 'is_active' => true])->assertSessionHasNoErrors();
    expect($this->membership->fresh()->permissions)->toBeNull();
});

test('a new Office member can be added with chosen areas', function () {
    $this->actingAs($this->owner)->post(route('team.store'), [
        'name' => 'Dispatcher', 'email' => 'dispatch@example.com', 'role' => 'admin',
        'password' => 'Secret-password-123', 'password_confirmation' => 'Secret-password-123',
        'permissions' => ['schedule', 'customers'],
    ])->assertSessionHasNoErrors();

    $membership = inCompany($this->company, fn () => Membership::query()->whereHas('user', fn ($q) => $q->where('email', 'dispatch@example.com'))->sole());
    expect($membership->permissions)->toBe(['schedule', 'customers'])
        ->and($membership->allows(OfficePermission::Invoices))->toBeFalse();
    expect(User::where('email', 'dispatch@example.com')->exists())->toBeTrue();
});

test('an Office member who only invoices can text the invoice link and the review request from a phone', function () {
    allowOnly([OfficePermission::Invoices]);
    $this->post(route('jobs.messages.opened', $this->job), ['kind' => 'invoice_link', 'to' => '+16045550142', 'body' => 'Your invoice'])->assertRedirect();
    $this->post(route('jobs.messages.opened', $this->job), ['kind' => 'general', 'to' => '+16045550142', 'body' => 'Hi'])->assertForbidden();
    $this->post(route('jobs.messages.opened', $this->job), ['kind' => 'estimate_link', 'to' => '+16045550142', 'body' => 'Estimate'])->assertForbidden();
});
