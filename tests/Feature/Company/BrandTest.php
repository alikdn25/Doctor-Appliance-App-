<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Owner));
});

function brandPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Duct Works',
        'primary_color' => '#B45309',
        'website' => 'https://ductworks.example.com',
        'email' => 'hello@ductworks.example.com',
        'phone' => '604-555-0100',
        'tax_number' => '123456789RT0001',
        'is_active' => true,
        'addresses' => [
            ['label' => 'Main', 'line1' => '1 Main St', 'city' => 'Burnaby', 'region' => 'BC', 'postal_code' => 'V5H 1A1', 'country' => 'CA', 'is_primary' => true],
            ['label' => 'Warehouse', 'line1' => '2 Side St', 'city' => 'Surrey', 'region' => 'BC', 'postal_code' => 'V3T 1A1', 'country' => 'CA', 'is_primary' => false],
        ],
    ], $overrides);
}

test('an owner can create a brand with addresses and a logo', function () {
    $this->post(route('brands.store'), brandPayload([
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ]))->assertRedirect();

    $brand = inCompany($this->company, fn () => Brand::with('addresses')->where('name', 'Duct Works')->sole());

    expect($brand->slug)->toBe('duct-works')
        ->and($brand->addresses)->toHaveCount(2)
        ->and($brand->addresses->where('is_primary', true)->sole()->city)->toBe('Burnaby')
        ->and($brand->logo_path)->toStartWith("companies/{$this->company->id}/brands/{$brand->id}/")
        ->and(AuditLog::where('action', 'brand.created')->exists())->toBeTrue();

    Storage::disk('public')->assertExists($brand->logo_path);
});

test('updating a brand syncs its addresses and keeps exactly one primary', function () {
    $this->post(route('brands.store'), brandPayload());
    $brand = inCompany($this->company, fn () => Brand::with('addresses')->sole());
    $keep = $brand->addresses->firstWhere('label', 'Warehouse');

    $this->put(route('brands.update', $brand), brandPayload([
        'name' => 'Duct Works BC',
        'addresses' => [
            ['id' => $keep->id, 'label' => 'Warehouse', 'line1' => '2 Side St', 'city' => 'Surrey', 'country' => 'CA', 'is_primary' => true],
            ['label' => 'New', 'line1' => '3 New St', 'city' => 'Langley', 'country' => 'CA', 'is_primary' => true],
        ],
    ]))->assertRedirect(route('brands.edit', $brand));

    $brand = inCompany($this->company, fn () => $brand->fresh('addresses'));

    expect($brand->name)->toBe('Duct Works BC')
        ->and($brand->slug)->toBe('duct-works')
        ->and($brand->addresses->pluck('label')->sort()->values()->all())->toBe(['New', 'Warehouse'])
        ->and($brand->addresses->where('is_primary', true))->toHaveCount(1)
        ->and($brand->addresses->firstWhere('is_primary', true)->id)->toBe($keep->id);
});

test('the first address becomes primary when none is marked', function () {
    $payload = brandPayload();
    $payload['addresses'][0]['is_primary'] = false;

    $this->post(route('brands.store'), $payload);

    $primary = inCompany($this->company, fn () => Brand::sole()->addresses()->where('is_primary', true)->sole());
    expect($primary->label)->toBe('Main');
});

test('the logo can be replaced and removed', function () {
    $this->post(route('brands.store'), brandPayload(['logo' => UploadedFile::fake()->image('a.png')]));
    $brand = inCompany($this->company, fn () => Brand::sole());
    $old = $brand->logo_path;

    $this->put(route('brands.update', $brand), brandPayload(['logo' => UploadedFile::fake()->image('b.png')]));
    $brand = inCompany($this->company, fn () => $brand->fresh());
    Storage::disk('public')->assertMissing($old);
    Storage::disk('public')->assertExists($brand->logo_path);

    $this->put(route('brands.update', $brand), brandPayload(['remove_logo' => true]));
    expect(inCompany($this->company, fn () => $brand->fresh()->logo_path))->toBeNull();
});

test('brand input is validated', function () {
    $this->post(route('brands.store'), brandPayload([
        'name' => '',
        'primary_color' => 'red',
        'website' => 'not-a-url',
        'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        'addresses' => [['line1' => '', 'city' => '', 'country' => 'CAN']],
    ]))->assertSessionHasErrors([
        'name', 'primary_color', 'website', 'logo',
        'addresses.0.line1', 'addresses.0.city', 'addresses.0.country',
    ]);
});

test('brand slugs are unique per company only', function () {
    Brand::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Duct Works', 'slug' => 'duct-works']);

    $this->post(route('brands.store'), brandPayload());
    $this->post(route('brands.store'), brandPayload());

    expect(inCompany($this->company, fn () => Brand::orderBy('id')->pluck('slug')->all()))
        ->toBe(['duct-works', 'duct-works-2']);
});

test('an owner can delete a brand', function () {
    $this->post(route('brands.store'), brandPayload());
    $brand = inCompany($this->company, fn () => Brand::sole());

    $this->delete(route('brands.destroy', $brand))->assertRedirect(route('brands.index'));

    expect(Brand::withoutCompanyScope()->withTrashed()->find($brand->id)->trashed())->toBeTrue()
        ->and(AuditLog::where('action', 'brand.deleted')->exists())->toBeTrue();
});
