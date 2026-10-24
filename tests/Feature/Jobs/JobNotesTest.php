<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobNote;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The job page's Notes tab (owner's task, Oct 24): "About the customer" and the team's notes on the job, each with
 * its author and time. Messages to the customer are on their own Messages tab.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('+16045550101')->create(['notes' => 'Dog in the yard']);
    $this->property = Property::factory()->for($this->customer)->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $this->notes = fn () => inCompany($this->company, fn () => JobNote::query()->get());
});

test('the technician on the job adds a note; the job page lists it with the author and About the customer', function () {
    $this->actingAs($this->tech)->post(route('jobs.notes.store', $this->job), ['body' => '  Part ordered from Marcone  '])
        ->assertSessionHasNoErrors();

    $note = ($this->notes)()->sole();
    expect($note->body)->toBe('Part ordered from Marcone')->and($note->user_id)->toBe($this->tech->id);

    $this->actingAs($this->owner)->get(route('jobs.show', $this->job))
        ->assertInertia(fn (Assert $page) => $page
            ->where('job.customer.notes', 'Dog in the yard')
            ->has('job.team_notes', 1)
            ->where('job.team_notes.0.body', 'Part ordered from Marcone')
            ->where('job.team_notes.0.author', $this->tech->name)
            ->where('job.team_notes.0.can_delete', true)
            ->has('messaging'));
});

test('an empty note is refused', function () {
    $this->actingAs($this->owner)->post(route('jobs.notes.store', $this->job), ['body' => ' '])->assertSessionHasErrors('body');
    expect(($this->notes)())->toHaveCount(0);
});

test('the author or the office deletes a note; another technician cannot', function () {
    $this->actingAs($this->owner)->post(route('jobs.notes.store', $this->job), ['body' => 'Office note']);
    $note = ($this->notes)()->sole();

    // A technician not on the job does not see it.
    $outsider = memberOf($this->company, UserRole::Technician);
    $this->actingAs($outsider)->post(route('jobs.notes.store', $this->job), ['body' => 'x'])->assertForbidden();
    $this->delete(route('jobs.notes.destroy', $note))->assertForbidden();

    // On the job, but not the author nor the office.
    $this->actingAs($this->tech)->delete(route('jobs.notes.destroy', $note))->assertForbidden();
    expect($note->fresh()->trashed())->toBeFalse();

    $this->actingAs($this->owner)->delete(route('jobs.notes.destroy', $note));
    expect($note->fresh()->trashed())->toBeTrue();
});

test('another company\'s job notes are not found', function () {
    $stranger = memberOf(Company::factory()->create());
    $this->actingAs($this->owner)->post(route('jobs.notes.store', $this->job), ['body' => 'Secret']);
    $note = ($this->notes)()->sole();

    $this->actingAs($stranger)->post(route('jobs.notes.store', $this->job), ['body' => 'x'])->assertNotFound();
    $this->delete(route('jobs.notes.destroy', $note))->assertNotFound();
    expect(($this->notes)())->toHaveCount(1);
});
