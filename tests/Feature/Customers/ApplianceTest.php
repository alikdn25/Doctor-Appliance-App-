<?php

use App\Enums\ApplianceType;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    $this->company = Company::factory()->create();
    $this->actingAs(memberOf($this->company, UserRole::Owner));
    $this->customer = Customer::factory()->for($this->company)->create();
    $this->property = Property::factory()->for($this->customer)->create();
});

function appliancePayload(array $overrides = []): array
{
    return array_replace([
        'type' => 'washer',
        'manufacturer' => 'LG',
        'model_number' => ' wm3900hwa ',
        'serial_number' => '912kwpx4b123',
        'purchase_date' => '2024-05-01',
        'install_date' => '2024-05-03',
        'warranty_expires_on' => now()->addMonth()->toDateString(),
        'warranty_notes' => 'Extended warranty with Sears.',
        'notes' => 'Loud spin cycle.',
    ], $overrides);
}

test('an appliance can be added to a property with a rating plate photo', function () {
    $response = $this->post(route('appliances.store', $this->property), appliancePayload([
        'rating_plate' => UploadedFile::fake()->image('plate.jpg', 800, 600),
    ]));

    $appliance = inCompany($this->company, fn () => Appliance::sole());
    $response->assertRedirect(route('appliances.show', $appliance));

    expect($appliance->property_id)->toBe($this->property->id)
        ->and($appliance->type)->toBe(ApplianceType::Washer)
        ->and($appliance->model_number)->toBe('WM3900HWA')
        ->and($appliance->serial_number)->toBe('912KWPX4B123')
        ->and($appliance->purchase_date->toDateString())->toBe('2024-05-01')
        ->and($appliance->isUnderWarranty())->toBeTrue()
        ->and($appliance->rating_plate_path)->toStartWith("companies/{$this->company->id}/appliances/{$appliance->id}/");

    Storage::disk('local')->assertExists($appliance->rating_plate_path);
});

test('the appliance card shows details, property and customer', function () {
    $appliance = Appliance::factory()->for($this->property)->create([
        'type' => 'dryer',
        'manufacturer' => 'Whirlpool',
        'warranty_expires_on' => now()->subDay()->toDateString(),
    ]);

    $this->get(route('appliances.show', $appliance))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('appliances/show')
            ->where('appliance.type_label', 'Dryer')
            ->where('appliance.under_warranty', false)
            ->where('property.id', $this->property->id)
            ->where('customer.id', $this->customer->id)
            ->where('manufacturers', fn ($list) => collect($list)->contains('Whirlpool')));
});

test('an appliance can be updated and its photo replaced or removed', function () {
    $this->post(route('appliances.store', $this->property), appliancePayload([
        'rating_plate' => UploadedFile::fake()->image('plate.jpg'),
    ]));
    $appliance = inCompany($this->company, fn () => Appliance::sole());
    $oldPath = $appliance->rating_plate_path;

    $this->post(route('appliances.update', $appliance), appliancePayload([
        '_method' => 'put',
        'type' => 'washer_dryer_combo',
        'rating_plate' => UploadedFile::fake()->image('new.png'),
    ]))->assertRedirect(route('appliances.show', $appliance));

    $appliance->refresh();
    expect($appliance->type)->toBe(ApplianceType::WasherDryerCombo)
        ->and($appliance->rating_plate_path)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);

    $this->put(route('appliances.update', $appliance), appliancePayload(['remove_rating_plate' => true]));

    expect($appliance->fresh()->rating_plate_path)->toBeNull();
});

test('appliance input is validated', function () {
    $this->post(route('appliances.store', $this->property), appliancePayload([
        'type' => 'toaster',
        'purchase_date' => 'yesterday-ish',
        'rating_plate' => UploadedFile::fake()->create('plate.pdf', 100, 'application/pdf'),
    ]))->assertSessionHasErrors(['type', 'purchase_date', 'rating_plate']);
});

test('an appliance can be deleted', function () {
    $appliance = Appliance::factory()->for($this->property)->create();

    $this->delete(route('appliances.destroy', $appliance))->assertRedirect(route('customers.show', $this->customer));

    expect($appliance->fresh()->trashed())->toBeTrue();
});
