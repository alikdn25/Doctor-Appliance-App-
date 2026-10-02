<?php

use App\Actions\Members\SyncMemberBrands;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\BrandAddress;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use App\Models\JobAppliance;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\JobVisitAssignee;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Support\Tenancy\MissingTenantException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->companyA = Company::factory()->create(['name' => 'Company A']);
    $this->companyB = Company::factory()->create(['name' => 'Company B']);

    $this->ownerA = memberOf($this->companyA, UserRole::Owner);
    $this->ownerB = memberOf($this->companyB, UserRole::Owner);

    $this->brandA = Brand::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Brand A']);
    $this->brandB = Brand::factory()->create(['company_id' => $this->companyB->id, 'name' => 'Brand B']);

    $this->taxA = TaxRate::factory()->create(['company_id' => $this->companyA->id]);
    $this->taxB = TaxRate::factory()->create(['company_id' => $this->companyB->id]);

    $this->customerA = Customer::factory()->for($this->companyA)->withPhone('604-555-0101')->withEmail('a@example.com')->create(['first_name' => 'Alice']);
    $this->customerB = Customer::factory()->for($this->companyB)->withPhone('604-555-0202')->withEmail('b@example.com')->create(['first_name' => 'Bella']);
    $this->propertyA = Property::factory()->for($this->customerA)->create();
    $this->propertyB = Property::factory()->for($this->customerB)->create(['line1' => '1 Secret St']);
    $this->applianceA = Appliance::factory()->for($this->propertyA)->create();
    $this->applianceB = Appliance::factory()->for($this->propertyB)->create(['type' => 'washer', 'model_number' => 'SECRETMODEL']);

    $this->techA = memberOf($this->companyA, UserRole::Technician);
    $this->techB = memberOf($this->companyB, UserRole::Technician);
    $this->jobA = ServiceJob::factory()->for($this->propertyA)->withAppliances([$this->applianceA])->withVisit($this->techA)
        ->create(['brand_id' => $this->brandA->id]);
    $this->jobB = ServiceJob::factory()->for($this->propertyB)->withAppliances([$this->applianceB])->withVisit($this->techB)
        ->create(['brand_id' => $this->brandB->id, 'description' => 'Secret job']);
    $this->visitB = JobVisit::withoutCompanyScope()->where('service_job_id', $this->jobB->id)->sole();

    foreach ([$this->jobA, $this->jobB] as $job) {
        JobStatusChange::withoutCompanyScope()->insert([
            'company_id' => $job->company_id, 'service_job_id' => $job->id, 'to_status' => 'new', 'created_at' => now(),
        ]);
    }
});

/**
 * Every tenant-owned model must be listed here. The architecture test below
 * fails if a model uses BelongsToCompany but is missing from this list.
 */
dataset('tenant models', [
    'brands' => [Brand::class],
    'brand addresses' => [BrandAddress::class],
    'memberships' => [Membership::class],
    'tax rates' => [TaxRate::class],
    'customers' => [Customer::class],
    'customer phones' => [CustomerPhone::class],
    'customer emails' => [CustomerEmail::class],
    'properties' => [Property::class],
    'appliances' => [Appliance::class],
    'jobs' => [ServiceJob::class],
    'job appliances' => [JobAppliance::class],
    'job visits' => [JobVisit::class],
    'job visit assignees' => [JobVisitAssignee::class],
    'job status changes' => [JobStatusChange::class],
]);

test('every tenant-owned model is covered by isolation tests', function () {
    $tenantModels = collect(glob(app_path('Models/*.php')))
        ->map(fn ($file) => 'App\\Models\\'.basename($file, '.php'))
        ->filter(fn ($class) => in_array(
            BelongsToCompany::class,
            class_uses_recursive($class),
            true,
        ))
        ->values()
        ->sort()
        ->all();

    expect($tenantModels)->toBe(collect([
        Appliance::class, Brand::class, BrandAddress::class, Customer::class, CustomerEmail::class,
        CustomerPhone::class, JobAppliance::class, JobStatusChange::class, JobVisit::class, JobVisitAssignee::class,
        Membership::class, Property::class, ServiceJob::class, TaxRate::class,
    ])->sort()->values()->all());
});

test('querying a tenant model without a current company fails closed', function (string $model) {
    $model::query()->get();
})->with('tenant models')->throws(MissingTenantException::class);

test('queries only return rows of the current company', function (string $model) {
    BrandAddress::withoutCompanyScope()->insert([
        ['company_id' => $this->companyA->id, 'brand_id' => $this->brandA->id, 'line1' => 'A St', 'city' => 'Surrey', 'country' => 'CA', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $this->companyB->id, 'brand_id' => $this->brandB->id, 'line1' => 'B St', 'city' => 'Burnaby', 'country' => 'CA', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $companyIds = inCompany($this->companyA, fn () => $model::query()->pluck('company_id')->unique()->values()->all());

    expect($companyIds)->toBe([$this->companyA->id]);
    expect($model::withoutCompanyScope()->where('company_id', $this->companyB->id)->exists())->toBeTrue();
})->with('tenant models');

test('new records get the current company automatically', function () {
    $brand = inCompany($this->companyA, fn () => Brand::create(['name' => 'Auto', 'slug' => 'auto']));

    expect($brand->company_id)->toBe($this->companyA->id);
});

test('writing a record of another company is blocked', function () {
    inCompany($this->companyA, function () {
        $foreign = Brand::withoutCompanyScope()->find($this->brandB->id);
        $foreign->name = 'Hijacked';
        $foreign->save();
    });
})->throws(MissingTenantException::class);

test('the brand list only shows brands of the current company', function () {
    $this->actingAs($this->ownerA)
        ->get(route('brands.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('brands/index')
            ->has('brands', 1)
            ->where('brands.0.name', 'Brand A'));
});

test('another company\'s brand cannot be opened, updated or deleted', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('brands.edit', $this->brandB))->assertNotFound();
    $this->put(route('brands.update', $this->brandB), ['name' => 'X'])->assertNotFound();
    $this->delete(route('brands.destroy', $this->brandB))->assertNotFound();

    expect(Brand::withoutCompanyScope()->find($this->brandB->id))
        ->name->toBe('Brand B')
        ->trashed()->toBeFalse();
});

test('another company\'s members cannot be changed or removed', function () {
    $membershipB = Membership::withoutCompanyScope()->where('user_id', $this->ownerB->id)->first();

    $this->actingAs($this->ownerA);

    $this->put(route('team.update', $membershipB), ['role' => 'technician', 'is_active' => false])->assertNotFound();
    $this->delete(route('team.destroy', $membershipB))->assertNotFound();
    $this->post(route('team.resend-invitation', $membershipB))->assertNotFound();

    expect($membershipB->fresh())->role->toBe(UserRole::Owner)->is_active->toBeTrue();
});

test('another company\'s tax rates cannot be changed or deleted', function () {
    $this->actingAs($this->ownerA);

    $this->put(route('taxes.update', $this->taxB), ['name' => 'X', 'rate' => 1])->assertNotFound();
    $this->delete(route('taxes.destroy', $this->taxB))->assertNotFound();

    expect(TaxRate::withoutCompanyScope()->whereKey($this->taxB->id)->exists())->toBeTrue();
});

test('the team page lists only members of the current company', function () {
    $this->actingAs($this->ownerA)
        ->get(route('team.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->pluck('email')->sort()->values()->all()
                === collect([$this->ownerA->email, $this->techA->email])->sort()->values()->all())
            ->has('brands', 1));
});

test('a company_id sent in the request is ignored', function () {
    $this->actingAs($this->ownerA)
        ->post(route('brands.store'), ['name' => 'Sneaky', 'company_id' => $this->companyB->id])
        ->assertRedirect();

    $brand = Brand::withoutCompanyScope()->where('name', 'Sneaky')->sole();
    expect($brand->company_id)->toBe($this->companyA->id);
});

test('brands of another company cannot be assigned to a member', function () {
    $this->actingAs($this->ownerA)
        ->post(route('team.store'), [
            'name' => 'New Tech',
            'email' => 'newtech@example.com',
            'role' => 'technician',
            'brand_ids' => [$this->brandB->id],
        ])
        ->assertSessionHasErrors('brand_ids.0');
});

test('syncing member brands never touches another company\'s rows', function () {
    $shared = memberOf($this->companyA, UserRole::Technician);
    Membership::factory()->create(['company_id' => $this->companyB->id, 'user_id' => $shared->id, 'role' => UserRole::Technician]);

    DB::table('brand_user')->insert([
        'company_id' => $this->companyB->id, 'brand_id' => $this->brandB->id, 'user_id' => $shared->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    inCompany($this->companyA, function () use ($shared) {
        $membership = Membership::where('user_id', $shared->id)->sole();
        app(SyncMemberBrands::class)->handle($membership, [$this->brandA->id]);
        app(SyncMemberBrands::class)->handle($membership, []);
    });

    expect(DB::table('brand_user')->where('user_id', $shared->id)->pluck('brand_id')->all())
        ->toBe([$this->brandB->id]);
});

test('audit logs written in a company are attributed to that company', function () {
    $this->actingAs($this->ownerA)->post(route('taxes.store'), ['name' => 'GST', 'rate' => 5]);

    expect(AuditLog::where('action', 'tax_rate.created')->sole()->company_id)
        ->toBe($this->companyA->id);
});

test('the customer list and search only show customers of the current company', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('customers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers.total', 1)
            ->where('customers.data.0.id', $this->customerA->id));

    foreach (['Bella', '604-555-0202', 'b@example.com', 'Secret St', 'SECRETMODEL'] as $term) {
        $this->get(route('customers.index', ['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('customers.total', 0));
    }
});

test('another company\'s customers, properties and appliances cannot be opened or changed', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('customers.show', $this->customerB))->assertNotFound();
    $this->get(route('customers.edit', $this->customerB))->assertNotFound();
    $this->put(route('customers.update', $this->customerB), ['type' => 'residential', 'first_name' => 'X'])->assertNotFound();
    $this->delete(route('customers.destroy', $this->customerB))->assertNotFound();

    $this->post(route('properties.store', $this->customerB), ['line1' => 'X', 'city' => 'Y', 'country' => 'CA'])->assertNotFound();
    $this->put(route('properties.update', $this->propertyB), ['line1' => 'X', 'city' => 'Y', 'country' => 'CA'])->assertNotFound();
    $this->delete(route('properties.destroy', $this->propertyB))->assertNotFound();

    $this->post(route('appliances.store', $this->propertyB), ['type' => 'washer'])->assertNotFound();
    $this->get(route('appliances.show', $this->applianceB))->assertNotFound();
    $this->put(route('appliances.update', $this->applianceB), ['type' => 'dryer'])->assertNotFound();
    $this->delete(route('appliances.destroy', $this->applianceB))->assertNotFound();

    expect(Customer::withoutCompanyScope()->find($this->customerB->id)->first_name)->toBe('Bella')
        ->and(Property::withoutCompanyScope()->find($this->propertyB->id)->line1)->toBe('1 Secret St')
        ->and(Appliance::withoutCompanyScope()->find($this->applianceB->id)->type->value)->toBe('washer')
        ->and(Property::withoutCompanyScope()->where('customer_id', $this->customerB->id)->count())->toBe(1)
        ->and(Appliance::withoutCompanyScope()->where('property_id', $this->propertyB->id)->count())->toBe(1);
});

test('phones and emails of another company cannot be taken over through a customer update', function () {
    $phoneB = CustomerPhone::withoutCompanyScope()->where('customer_id', $this->customerB->id)->sole();
    $emailB = CustomerEmail::withoutCompanyScope()->where('customer_id', $this->customerB->id)->sole();

    $this->actingAs($this->ownerA)
        ->put(route('customers.update', $this->customerA), [
            'type' => 'residential',
            'first_name' => 'Alice',
            'phones' => [['id' => $phoneB->id, 'label' => 'mobile', 'number' => '604-555-9999']],
            'emails' => [['id' => $emailB->id, 'label' => 'personal', 'email' => 'x@example.com']],
        ])
        ->assertRedirect();

    expect($phoneB->fresh())->number->toBe('604-555-0202')->customer_id->toBe($this->customerB->id)
        ->and($emailB->fresh())->email->toBe('b@example.com')->customer_id->toBe($this->customerB->id);
});

test('duplicate detection never reveals another company\'s customers', function () {
    $this->actingAs($this->ownerA)
        ->getJson(route('customers.duplicates', ['phones' => ['604-555-0202'], 'emails' => ['b@example.com']]))
        ->assertOk()
        ->assertJsonCount(0, 'duplicates');
});

test('manufacturer and tag suggestions only come from the current company', function () {
    inCompany($this->companyB, function () {
        $this->applianceB->update(['manufacturer' => 'SecretBrand']);
        $this->customerB->update(['tags' => ['secret-tag']]);
    });

    $this->actingAs($this->ownerA)
        ->get(route('customers.show', $this->customerA))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manufacturers', fn ($list) => ! collect($list)->contains('SecretBrand')));

    $this->get(route('customers.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tags', []));
});

test('the job list, search and my jobs only show jobs of the current company', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.total', 1)
            ->where('jobs.data.0.id', $this->jobA->id)
            ->where('brands', fn ($brands) => collect($brands)->pluck('label')->all() === ['Brand A'])
            ->where('technicians', fn ($people) => ! collect($people)->pluck('id')->contains($this->techB->id)));

    foreach ([(string) $this->jobB->number, 'Bella', '604-555-0202', 'Secret St', 'SECRETMODEL'] as $term) {
        $this->get(route('jobs.index', ['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('jobs.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($this->jobB->id)));
    }

    $this->actingAs($this->techA)
        ->get(route('jobs.mine', ['tab' => 'upcoming']))
        ->assertInertia(fn (Assert $page) => $page->has('visits', 1)->where('visits.0.job.id', $this->jobA->id));
});

test('another company\'s jobs and visits cannot be opened or changed', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('jobs.show', $this->jobB))->assertNotFound();
    $this->get(route('jobs.edit', $this->jobB))->assertNotFound();
    $this->put(route('jobs.update', $this->jobB), ['brand_id' => $this->brandA->id])->assertNotFound();
    $this->put(route('jobs.status', $this->jobB), ['status' => 'cancelled'])->assertNotFound();
    $this->put(route('jobs.tech-notes', $this->jobB), ['tech_notes' => 'X'])->assertNotFound();
    $this->post(route('jobs.appliances.store', $this->jobB), ['type' => 'dryer'])->assertNotFound();
    $this->put(route('jobs.appliances.update', [$this->jobB, $this->applianceB]), ['model_number' => 'X'])->assertNotFound();
    $this->post(route('visits.store', $this->jobB), ['date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '10:00'])->assertNotFound();
    $this->put(route('visits.update', $this->visitB), ['date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '10:00'])->assertNotFound();
    $this->post(route('visits.on-my-way', $this->visitB))->assertNotFound();
    $this->post(route('visits.start', $this->visitB))->assertNotFound();
    $this->post(route('visits.finish', $this->visitB), ['outcome' => 'completed'])->assertNotFound();
    $this->delete(route('visits.destroy', $this->visitB))->assertNotFound();
    $this->delete(route('jobs.destroy', $this->jobB))->assertNotFound();

    $jobB = ServiceJob::withoutCompanyScope()->find($this->jobB->id);
    expect($jobB->status->value)->toBe('scheduled')
        ->and($jobB->trashed())->toBeFalse()
        ->and($jobB->tech_notes)->toBeNull()
        ->and(JobVisit::withoutCompanyScope()->find($this->visitB->id)->status->value)->toBe('scheduled')
        ->and(Appliance::withoutCompanyScope()->where('property_id', $this->propertyB->id)->count())->toBe(1);
});

test('a job cannot use another company\'s customer, property, appliance, brand or team member', function () {
    $this->actingAs($this->ownerA);
    $visit = ['add_visit' => true, 'visit' => ['date' => '2030-01-01', 'start_time' => '09:00', 'end_time' => '10:00']];
    $base = [
        'brand_id' => $this->brandA->id, 'job_type' => 'repair',
        'customer_id' => $this->customerA->id, 'property_id' => $this->propertyA->id,
    ];

    $this->post(route('jobs.store'), [...$base, 'customer_id' => $this->customerB->id, 'property_id' => $this->propertyB->id])
        ->assertSessionHasErrors('customer_id');
    $this->post(route('jobs.store'), [...$base, 'property_id' => $this->propertyB->id])
        ->assertSessionHasErrors('property_id');
    $this->post(route('jobs.store'), [...$base, 'appliance_ids' => [$this->applianceB->id]])
        ->assertSessionHasErrors('appliance_ids');
    $this->post(route('jobs.store'), [...$base, 'brand_id' => $this->brandB->id])
        ->assertSessionHasErrors('brand_id');
    $this->post(route('jobs.store'), [...$base, ...$visit, 'visit' => [...$visit['visit'], 'assignee_ids' => [$this->techB->id]]])
        ->assertSessionHasErrors('visit.assignee_ids.0');
    $this->post(route('visits.store', $this->jobA), [...$visit['visit'], 'assignee_ids' => [$this->techB->id]])
        ->assertSessionHasErrors('assignee_ids.0');
    $this->post(route('jobs.appliances.store', $this->jobA), ['appliance_id' => $this->applianceB->id])
        ->assertNotFound();

    expect(ServiceJob::withoutCompanyScope()->where('company_id', $this->companyA->id)->count())->toBe(1);
});

test('the job customer lookup never returns another company\'s customers', function () {
    $this->actingAs($this->ownerA)
        ->getJson(route('jobs.lookup', ['search' => 'Bella']))
        ->assertOk()
        ->assertJsonCount(0, 'customers');
});

test('job numbers are counted per company', function () {
    $this->actingAs($this->ownerB)->post(route('jobs.store'), [
        'brand_id' => $this->brandB->id, 'job_type' => 'repair',
        'customer_id' => $this->customerB->id, 'property_id' => $this->propertyB->id,
    ])->assertRedirect();

    expect(ServiceJob::withoutCompanyScope()->where('company_id', $this->companyB->id)->orderBy('number')->pluck('number')->all())
        ->toBe([1001, 1002])
        ->and(Company::find($this->companyA->id)->job_next_number)->toBe(1002);
});
