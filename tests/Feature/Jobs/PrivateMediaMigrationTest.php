<?php

use App\Models\Appliance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Facades\Storage;

test('the migration moves rating plates, photos and signatures off the public disk', function () {
    Storage::fake('public');
    Storage::fake('local');

    $company = Company::factory()->create();
    $property = Property::factory()->for(Customer::factory()->for($company))->create();
    $appliance = Appliance::factory()->for($property)->create();
    Appliance::withoutCompanyScope()->whereKey($appliance->id)->update(['rating_plate_path' => 'companies/1/appliances/1/plate.jpg']);
    Storage::disk('public')->put('companies/1/appliances/1/plate.jpg', 'plate');
    Storage::disk('public')->put('companies/1/logo.png', 'logo');

    $migration = require database_path('migrations/2026_10_06_000100_move_private_media_off_public_disk.php');
    $migration->up();

    Storage::disk('public')->assertMissing('companies/1/appliances/1/plate.jpg');
    Storage::disk('public')->assertExists('companies/1/logo.png');
    expect(Storage::disk('local')->get('companies/1/appliances/1/plate.jpg'))->toBe('plate');
});
