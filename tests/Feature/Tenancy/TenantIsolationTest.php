<?php

use App\Actions\Members\SyncMemberBrands;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\BrandAddress;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Membership;
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

    expect($tenantModels)->toBe(collect([Brand::class, BrandAddress::class, Membership::class, TaxRate::class])->sort()->all());
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
            ->has('members', 1)
            ->where('members.0.email', $this->ownerA->email)
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
