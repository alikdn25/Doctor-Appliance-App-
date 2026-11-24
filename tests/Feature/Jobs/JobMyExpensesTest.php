<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\JobProfit;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's task of November 24: "My expenses" of a job. One total without a name or several lines; each line is a
 * business expense linked to the job (the same record in Expenses & taxes). The Owner sees every line; a technician
 * assigned to the job sees and adds only their own; nobody else, and never another company.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver', 'currency' => 'CAD']);
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->otherTech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->create();
    $property = Property::factory()->for($customer)->create();
    $this->job = ServiceJob::factory()->for($property)->withVisit($this->tech)->create(['brand_id' => $brand->id]);
});

test('several lines are saved as business expenses of the job and add up', function () {
    $this->actingAs($this->owner)->post(route('jobs.my-expenses.store', $this->job), ['lines' => [
        ['description' => 'Pump', 'amount' => '60'],
        ['description' => 'Parking', 'amount' => '$10.50'],
        ['description' => '', 'amount' => ''],
    ]])->assertSessionHasNoErrors();

    $expenses = inCompany($this->company, fn () => BusinessExpense::query()->orderBy('id')->get());
    expect($expenses->pluck('description')->all())->toBe(['Pump', 'Parking'])
        ->and($expenses->pluck('amount')->all())->toBe([6000, 1050])
        ->and($expenses->pluck('service_job_id')->unique()->all())->toBe([$this->job->id])
        ->and($expenses->first()->category->name)->toBe('Job expenses')
        ->and(inCompany($this->company, fn () => JobProfit::for($this->job->fresh())['cost']))->toBe(7050);

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('myExpenses.total', 7050)
        ->where('myExpenses.lines.0.description', 'Pump')
        ->where('myExpenses.lines.1.amount', 1050));

    // The same records in Expenses & taxes: no double count.
    $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page->where('expenses.total', 2));
});

test('one total without a name gets "Expenses for job #N"', function () {
    $this->actingAs($this->owner)->post(route('jobs.my-expenses.store', $this->job), ['lines' => [['amount' => '25']]])
        ->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => BusinessExpense::query()->sole()->description))->toBe('Expenses for job #'.$this->job->number);

    $this->post(route('jobs.my-expenses.store', $this->job), ['lines' => [['description' => 'x', 'amount' => '0']]])
        ->assertSessionHasErrors('lines');
});

test('a technician on the job sees and changes only their own lines; others see nothing', function () {
    $this->actingAs($this->owner)->post(route('jobs.my-expenses.store', $this->job), ['lines' => [['description' => 'Owner part', 'amount' => '80']]]);
    $this->actingAs($this->tech)->post(route('jobs.my-expenses.store', $this->job), ['lines' => [['description' => 'Parking', 'amount' => '10']]])
        ->assertSessionHasNoErrors();

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->has('myExpenses.lines', 1)
        ->where('myExpenses.lines.0.description', 'Parking')
        ->where('myExpenses.total', 1000));

    $ownerLine = inCompany($this->company, fn () => BusinessExpense::query()->where('description', 'Owner part')->sole());
    $techLine = inCompany($this->company, fn () => BusinessExpense::query()->where('description', 'Parking')->sole());
    $this->put(route('jobs.my-expenses.update', [$this->job, $ownerLine]), ['description' => 'Mine', 'amount' => '1'])->assertForbidden();
    $this->delete(route('jobs.my-expenses.destroy', [$this->job, $ownerLine]))->assertForbidden();
    $this->put(route('jobs.my-expenses.update', [$this->job, $techLine]), ['description' => 'Parking downtown', 'amount' => '12'])->assertRedirect();
    expect($techLine->fresh()->amount)->toBe(1200);

    // The Owner sees both lines, with who entered the technician's.
    $this->actingAs($this->owner)->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->has('myExpenses.lines', 2)
        ->where('myExpenses.lines.1.by', $this->tech->name)
        ->where('myExpenses.total', 9200));

    // A technician who is not on the job: no block, no adding.
    $response = $this->actingAs($this->otherTech)->post(route('jobs.my-expenses.store', $this->job), ['lines' => [['amount' => '5']]]);
    expect($response->status())->toBeIn([403, 404])
        ->and(inCompany($this->company, fn () => BusinessExpense::query()->count()))->toBe(2);
});

test('another company never reaches the block', function () {
    $stranger = memberOf(Company::factory()->create());
    $this->actingAs($stranger)->post(route('jobs.my-expenses.store', $this->job), ['lines' => [['amount' => '5']]])->assertNotFound();
    expect(inCompany($this->company, fn () => BusinessExpense::query()->count()))->toBe(0);
});

test('a receipt photo expense chosen for a job shows in that job\'s My expenses', function () {
    $category = inCompany($this->company, fn () => BusinessExpenseCategory::query()->create(['name' => 'Parts']));
    $this->actingAs($this->tech)->post(route('expenses.store'), [
        'category_id' => $category->id, 'spent_on' => '2026-11-24', 'description' => 'Igniter', 'merchant' => 'Reliable Parts',
        'amount' => '45.00', 'service_job_id' => $this->job->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('myExpenses.lines.0.description', 'Igniter')
        ->where('myExpenses.total', 4500));
});
