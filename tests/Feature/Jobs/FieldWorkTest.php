<?php

use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobChecklistItem;
use App\Models\JobPhoto;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');

    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tom Tech']);
    $this->otherTech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->appliance = Appliance::factory()->for($this->property)->create();
    $this->job = ServiceJob::factory()->for($this->property)->withAppliances([$this->appliance])->withVisit($this->tech)
        ->create(['brand_id' => $this->brand->id]);
    $this->visit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();

    $this->actingAs($this->tech);
});

function uploadPhoto(array $overrides = []): TestResponse
{
    return test()->post(route('jobs.photos.store', test()->job), [
        'photo' => UploadedFile::fake()->image('before.jpg', 1600, 1200),
        'kind' => 'before',
        'client_uuid' => (string) Str::uuid(),
        ...$overrides,
    ], ['Accept' => 'application/json']);
}

test('a technician uploads a before photo for their visit', function () {
    $uuid = (string) Str::uuid();

    uploadPhoto(['client_uuid' => $uuid, 'visit_id' => $this->visit->id, 'taken_at' => '2030-06-12T10:15:00Z'])
        ->assertCreated()
        ->assertJsonStructure(['photo' => ['id']]);

    $photo = JobPhoto::withoutCompanyScope()->sole();

    expect($photo)
        ->kind->value->toBe('before')
        ->service_job_id->toBe($this->job->id)
        ->job_visit_id->toBe($this->visit->id)
        ->user_id->toBe($this->tech->id)
        ->company_id->toBe($this->company->id)
        ->path->toBe("companies/{$this->company->id}/jobs/{$this->job->id}/photos/{$uuid}.jpg")
        ->and($photo->taken_at->utc()->toDateTimeString())->toBe('2030-06-12 10:15:00');

    Storage::disk('local')->assertExists($photo->path);
});

test('a retried upload does not create a second photo', function () {
    $uuid = (string) Str::uuid();

    $first = uploadPhoto(['client_uuid' => $uuid])->assertCreated()->json('photo.id');
    $second = uploadPhoto(['client_uuid' => $uuid])->assertOk()->json('photo.id');

    expect($second)->toBe($first)
        ->and(JobPhoto::withoutCompanyScope()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

test('a client uuid already used on another job is refused', function () {
    $uuid = (string) Str::uuid();
    uploadPhoto(['client_uuid' => $uuid]);
    $other = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);

    $this->post(route('jobs.photos.store', $other), [
        'photo' => UploadedFile::fake()->image('x.jpg'), 'kind' => 'after', 'client_uuid' => $uuid,
    ], ['Accept' => 'application/json'])->assertStatus(409);
});

test('photos must be images with a kind and an id', function () {
    uploadPhoto(['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertJsonValidationErrors('photo');
    uploadPhoto(['kind' => 'during'])->assertJsonValidationErrors('kind');
    uploadPhoto(['client_uuid' => 'abc'])->assertJsonValidationErrors('client_uuid');
    uploadPhoto(['photo' => UploadedFile::fake()->image('huge.jpg')->size(20000)])->assertJsonValidationErrors('photo');

    expect(JobPhoto::withoutCompanyScope()->count())->toBe(0);
});

test('a visit of another job is not linked to the photo', function () {
    $other = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $otherVisit = JobVisit::withoutCompanyScope()->where('service_job_id', $other->id)->sole();

    uploadPhoto(['visit_id' => $otherVisit->id])->assertCreated();

    expect(JobPhoto::withoutCompanyScope()->sole()->job_visit_id)->toBeNull();
});

test('photos are served only to people who can see the job', function () {
    uploadPhoto();
    $photo = JobPhoto::withoutCompanyScope()->sole();

    $this->get(route('jobs.photos.show', [$this->job, $photo]))->assertOk();
    $this->actingAs($this->owner)->get(route('jobs.photos.show', [$this->job, $photo]))->assertOk();
    $this->actingAs($this->otherTech)->get(route('jobs.photos.show', [$this->job, $photo]))->assertForbidden();
    $this->post(route('jobs.photos.store', $this->job), ['kind' => 'before'])->assertForbidden();
});

test('the photographer or the office can delete a photo, other technicians cannot', function () {
    $helper = memberOf($this->company, UserRole::Technician);
    JobVisit::factory()->for($this->job, 'job')->assignedTo($helper)->create();
    uploadPhoto();
    $photo = JobPhoto::withoutCompanyScope()->sole();

    $this->actingAs($helper)->delete(route('jobs.photos.destroy', [$this->job, $photo]))->assertForbidden();
    $this->actingAs($this->tech)->delete(route('jobs.photos.destroy', [$this->job, $photo]))->assertRedirect();

    expect(JobPhoto::withoutCompanyScope()->count())->toBe(0);
    Storage::disk('local')->assertMissing($photo->path);

    uploadPhoto();
    $this->actingAs($this->owner)
        ->delete(route('jobs.photos.destroy', [$this->job, JobPhoto::withoutCompanyScope()->sole()]))
        ->assertRedirect();

    expect(JobPhoto::withoutCompanyScope()->count())->toBe(0);
});

test('a technician photographs the rating plate of an appliance on their job', function () {
    $this->post(route('jobs.appliances.rating-plate', [$this->job, $this->appliance]), [
        'rating_plate' => UploadedFile::fake()->image('plate.jpg'),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('appliance.id', $this->appliance->id);

    $first = $this->appliance->fresh()->rating_plate_path;
    Storage::disk('local')->assertExists($first);

    $this->post(route('jobs.appliances.rating-plate', [$this->job, $this->appliance]), [
        'rating_plate' => UploadedFile::fake()->image('plate2.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($this->appliance->fresh()->rating_plate_path);
});

test('rating plates can only be added to appliances on the technician\'s own job', function () {
    $spare = Appliance::factory()->for($this->property)->create();

    $this->post(route('jobs.appliances.rating-plate', [$this->job, $spare]), ['rating_plate' => UploadedFile::fake()->image('p.jpg')])
        ->assertNotFound();
    $this->actingAs($this->otherTech)
        ->post(route('jobs.appliances.rating-plate', [$this->job, $this->appliance]), ['rating_plate' => UploadedFile::fake()->image('p.jpg')])
        ->assertForbidden();

    expect($spare->fresh()->rating_plate_path)->toBeNull()
        ->and($this->appliance->fresh()->rating_plate_path)->toBeNull();
});

test('rating plate photos are private and served only to people with access to the job', function () {
    $this->post(route('jobs.appliances.rating-plate', [$this->job, $this->appliance]), [
        'rating_plate' => UploadedFile::fake()->image('plate.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    $appliance = $this->appliance->fresh();
    Storage::disk('public')->assertMissing($appliance->rating_plate_path);
    expect($appliance->rating_plate_url)->toStartWith(route('appliances.rating-plate', $appliance));

    // Assigned technician and the office can see it.
    $this->get($appliance->rating_plate_url)->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
    $this->actingAs($this->owner)->get($appliance->rating_plate_url)->assertOk();

    // A technician of the same company without a job at this property cannot.
    $this->actingAs($this->otherTech)->get($appliance->rating_plate_url)->assertForbidden();

    // Nor can a guest.
    auth()->logout();
    $this->get($appliance->rating_plate_url)->assertRedirect(route('login'));
});

test('an appliance without a rating plate photo has no plate to show', function () {
    $this->actingAs($this->owner)->get(route('appliances.rating-plate', $this->appliance))->assertNotFound();
});

test('a new job gets a copy of the checklist for its type', function () {
    inCompany($this->company, fn () => ChecklistTemplate::create(['job_type' => 'repair', 'items' => ['Check error codes', 'Test run']]));

    $this->actingAs($this->owner)->post(route('jobs.store'), [
        'brand_id' => $this->brand->id, 'job_type' => 'repair',
        'customer_id' => $this->property->customer_id, 'property_id' => $this->property->id,
    ])->assertRedirect();

    $job = ServiceJob::withoutCompanyScope()->latest('id')->first();

    expect(JobChecklistItem::withoutCompanyScope()->where('service_job_id', $job->id)->orderBy('position')->pluck('label')->all())
        ->toBe(['Check error codes', 'Test run']);

    // Template changes do not touch existing jobs.
    inCompany($this->company, fn () => ChecklistTemplate::where('job_type', 'repair')->update(['items' => json_encode(['Other'])]));

    expect(JobChecklistItem::withoutCompanyScope()->where('service_job_id', $job->id)->count())->toBe(2);
});

test('changing the job type swaps the checklist until something is ticked', function () {
    inCompany($this->company, function () {
        ChecklistTemplate::create(['job_type' => 'repair', 'items' => ['Repair item']]);
        ChecklistTemplate::create(['job_type' => 'installation', 'items' => ['Level it', 'Check leaks']]);
        $this->job->applyChecklistTemplate();
    });

    $update = fn (string $type) => $this->actingAs($this->owner)->put(route('jobs.update', $this->job), [
        'brand_id' => $this->brand->id, 'job_type' => $type, 'property_id' => $this->property->id,
        'appliance_ids' => [$this->appliance->id],
    ])->assertRedirect();
    $labels = fn () => JobChecklistItem::withoutCompanyScope()->where('service_job_id', $this->job->id)->orderBy('position')->pluck('label')->all();

    $update('installation');
    expect($labels())->toBe(['Level it', 'Check leaks']);

    JobChecklistItem::withoutCompanyScope()->where('service_job_id', $this->job->id)->first()->forceFill(['is_done' => true])->saveQuietly();

    $update('repair');
    expect($labels())->toBe(['Level it', 'Check leaks']);
});

test('a technician ticks checklist items on their job', function () {
    $item = inCompany($this->company, fn () => $this->job->checklistItems()->create(['position' => 0, 'label' => 'Test run']));

    $this->travelTo('2030-06-12 18:00:00');
    $this->put(route('jobs.checklist.toggle', [$this->job, $item]), ['is_done' => true])->assertRedirect();

    expect($item->fresh())
        ->is_done->toBeTrue()
        ->done_by->toBe($this->tech->id)
        ->and($item->fresh()->done_at->toDateTimeString())->toBe('2030-06-12 18:00:00');

    $this->put(route('jobs.checklist.toggle', [$this->job, $item]), ['is_done' => false]);
    expect($item->fresh())->is_done->toBeFalse()->done_by->toBeNull()->done_at->toBeNull();

    $this->actingAs($this->otherTech)
        ->put(route('jobs.checklist.toggle', [$this->job, $item]), ['is_done' => true])
        ->assertForbidden();
});

test('the customer signs on the technician\'s phone', function () {
    $this->post(route('jobs.signature.store', $this->job), [
        'signature' => UploadedFile::fake()->image('signature.png', 600, 200),
        'signer_name' => 'Jane Cooper',
    ], ['Accept' => 'application/json'])->assertCreated();

    $job = $this->job->fresh();
    $first = $job->signature_path;

    expect($job)
        ->signature_name->toBe('Jane Cooper')
        ->signed_by->toBe($this->tech->id)
        ->signed_at->not->toBeNull();
    Storage::disk('local')->assertExists($first);

    $this->get(route('jobs.signature.show', $this->job))->assertOk();

    // Signing again replaces the old signature.
    $this->post(route('jobs.signature.store', $this->job), [
        'signature' => UploadedFile::fake()->image('signature.png'),
        'signer_name' => 'Jane C.',
    ], ['Accept' => 'application/json'])->assertCreated();

    Storage::disk('local')->assertMissing($first);
    expect($this->job->fresh()->signature_name)->toBe('Jane C.');
});

test('a signature must be a PNG with the signer\'s name', function () {
    $this->post(route('jobs.signature.store', $this->job), [
        'signature' => UploadedFile::fake()->image('s.jpg'),
    ], ['Accept' => 'application/json'])->assertJsonValidationErrors(['signature', 'signer_name']);

    $this->actingAs($this->otherTech)
        ->post(route('jobs.signature.store', $this->job), ['signature' => UploadedFile::fake()->image('s.png'), 'signer_name' => 'X'])
        ->assertForbidden();

    $this->get(route('jobs.signature.show', $this->job))->assertForbidden();
    $this->actingAs($this->owner)->get(route('jobs.signature.show', $this->job))->assertNotFound();
});

test('the job page lists photos, checklist and signature', function () {
    uploadPhoto();
    inCompany($this->company, fn () => $this->job->checklistItems()->create(['position' => 0, 'label' => 'Test run']));
    $this->post(route('jobs.signature.store', $this->job), [
        'signature' => UploadedFile::fake()->image('s.png'), 'signer_name' => 'Jane',
    ], ['Accept' => 'application/json']);

    $this->get(route('jobs.show', $this->job))
        ->assertInertia(fn (Assert $page) => $page
            ->where('job.photos.0.kind', 'before')
            ->where('job.photos.0.user', 'Tom Tech')
            ->where('job.photos.0.can_delete', true)
            ->where('job.checklist.0.label', 'Test run')
            ->where('job.signature.name', 'Jane')
            ->where('job.signature.by', 'Tom Tech')
            ->has('photoKinds', 2));
});

test('the office edits checklists per job type', function () {
    $this->actingAs($this->owner);

    $this->get(route('company.checklists.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('company/checklists')->has('jobTypes', 6));

    $this->put(route('company.checklists.update'), ['templates' => [
        'repair' => ['  Check error codes ', '', 'Test run'],
        'installation' => [],
    ]])->assertRedirect(route('company.checklists.edit'));

    inCompany($this->company, function () {
        expect(ChecklistTemplate::where('job_type', 'repair')->sole()->items)->toBe(['Check error codes', 'Test run'])
            ->and(ChecklistTemplate::where('job_type', 'installation')->sole()->items)->toBe([]);
    });

    $this->put(route('company.checklists.update'), ['templates' => ['repair' => [str_repeat('x', 201)]]])
        ->assertSessionHasErrors('templates.repair.0');
    $this->put(route('company.checklists.update'), ['templates' => ['painting' => ['x']]])
        ->assertSessionHasErrors('templates');
});

test('technicians cannot edit checklists', function () {
    $this->get(route('company.checklists.edit'))->assertForbidden();
    $this->put(route('company.checklists.update'), ['templates' => ['repair' => ['x']]])->assertForbidden();
});

test('a new company starts with the default checklists', function () {
    Notification::fake();

    $this->actingAs(User::factory()->superAdmin()->create())->post(route('admin.companies.store'), [
        'name' => 'Fresh Co', 'timezone' => 'America/Vancouver', 'currency' => 'CAD',
        'owner_name' => 'Fay', 'owner_email' => 'fay@example.com',
    ])->assertRedirect();

    $company = Company::where('name', 'Fresh Co')->sole();

    expect(ChecklistTemplate::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(6);
});

test('the app can be installed on a phone', function () {
    $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('start_url', '/my-jobs')
        ->assertJsonCount(3, 'icons');

    foreach (['sw.js', 'offline.html', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/maskable-512.png'] as $file) {
        expect(file_exists(public_path($file)))->toBeTrue();
    }
});
