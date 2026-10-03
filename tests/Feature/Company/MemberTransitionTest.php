<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Previous Technician']);
    $this->otherTech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))
        ->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);
});

test('unfinished visits move to another technician while completed visits keep history', function () {
    $oldVisit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();
    $completed = JobVisit::factory()->create(['company_id' => $this->company->id, 'service_job_id' => $this->job->id, 'status' => VisitStatus::Completed]);
    inCompany($this->company, fn () => $completed->assignees()->attach($this->tech));
    $this->post(route('team.transfer-jobs', $this->tech->membershipFor($this->company)), ['replacement_id' => $this->otherTech->membershipFor($this->company)->id])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $oldVisit->fresh()->isAssigned($this->otherTech)))->toBeTrue()
        ->and(inCompany($this->company, fn () => $oldVisit->fresh()->isAssigned($this->tech)))->toBeFalse()
        ->and(inCompany($this->company, fn () => $completed->fresh()->isAssigned($this->tech)))->toBeTrue();
});

test('replacement keeps the login with a new password and preserves the previous person and their completed history', function () {
    $email = $this->tech->email;
    $oldVisit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();
    $completed = JobVisit::factory()->create(['company_id' => $this->company->id, 'service_job_id' => $this->job->id, 'status' => VisitStatus::Completed]);
    inCompany($this->company, fn () => $completed->assignees()->attach($this->tech));
    DB::table('sessions')->insert(['id' => 'former-tech-session', 'user_id' => $this->tech->id, 'payload' => '', 'last_activity' => time()]);
    $this->post(route('team.replace', $this->tech->membershipFor($this->company)), ['name' => 'New Technician', 'password' => 'New-technician-password-123!', 'password_confirmation' => 'New-technician-password-123!'])->assertSessionHasNoErrors();
    $replacement = User::where('email', $email)->sole();
    expect($replacement->id)->not->toBe($this->tech->id)
        ->and(Hash::check('New-technician-password-123!', $replacement->password))->toBeTrue()
        ->and($replacement->hasVerifiedEmail())->toBeTrue()
        ->and($this->tech->fresh()->name)->toBe('Previous Technician')
        ->and($this->tech->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'former-tech-session')->exists())->toBeFalse()
        ->and(inCompany($this->company, fn () => $oldVisit->fresh()->isAssigned($replacement)))->toBeTrue()
        ->and(inCompany($this->company, fn () => $completed->fresh()->isAssigned($this->tech)))->toBeTrue();
    auth()->logout();
    $this->post(route('login.store'), ['email' => $email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->post(route('login.store'), ['email' => $email, 'password' => 'New-technician-password-123!'])->assertRedirect(route('jobs.mine', absolute: false));
});

test('a foreign replacement and a shared account cannot be used to change another company', function () {
    $foreign = memberOf();
    $this->post(route('team.transfer-jobs', $this->tech->membershipFor($this->company)), ['replacement_id' => $foreign->memberships()->sole()->id])->assertSessionHasErrors('replacement_id');
    Membership::factory()->create(['company_id' => Company::factory()->create()->id, 'user_id' => $this->tech->id]);
    $this->post(route('team.replace', $this->tech->membershipFor($this->company)), ['name' => 'New', 'password' => 'Secret-password-123!', 'password_confirmation' => 'Secret-password-123!'])->assertSessionHasErrors('name');
    expect($this->tech->fresh()->is_active)->toBeTrue();
});

test('technicians cannot transfer other people or replace themselves', function () {
    $membership = $this->tech->membershipFor($this->company);
    $this->actingAs($this->tech)->post(route('team.transfer-jobs', $membership), ['replacement_id' => $this->otherTech->membershipFor($this->company)->id])->assertForbidden();
    $this->post(route('team.replace', $membership), [])->assertForbidden();
});

test('brand restrictions prevent a partial transfer and completed jobs stay untouched', function () {
    $differentBrand = Brand::factory()->create(['company_id' => $this->company->id]);
    inCompany($this->company, fn () => $this->otherTech->brands()->attach($differentBrand, ['company_id' => $this->company->id]));
    $this->post(route('team.transfer-jobs', $this->tech->membershipFor($this->company)), ['replacement_id' => $this->otherTech->membershipFor($this->company)->id])->assertSessionHasErrors('replacement_id');
    $this->job->update(['status' => JobStatus::Completed]);
    $this->post(route('team.transfer-jobs', $this->tech->membershipFor($this->company)), ['replacement_id' => $this->otherTech->membershipFor($this->company)->id])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $this->job->fresh()->isAssigned($this->tech)))->toBeTrue();
});
