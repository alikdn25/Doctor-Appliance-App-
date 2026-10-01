<?php

namespace Database\Seeders;

use App\Actions\Customers\SaveAppliance;
use App\Actions\Customers\SaveCustomer;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Membership;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local demo data. Every account uses the password "password".
 *
 *  admin@example.com   super-admin (platform)
 *  owner@example.com   Owner of Doctor Appliance Group
 *  office@example.com  Admin of Doctor Appliance Group
 *  tech@example.com    Technician in both companies (shows the company switcher)
 *  other@example.com   Owner of Coastal Repair Co
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenancy = app(CurrentCompany::class);

        $this->user('admin@example.com', 'Platform Admin', superAdmin: true);

        $group = Company::factory()->create(['name' => 'Doctor Appliance Group', 'slug' => 'doctor-appliance-group']);
        $coastal = Company::factory()->create(['name' => 'Coastal Repair Co', 'slug' => 'coastal-repair-co']);

        $owner = $this->user('owner@example.com', 'Alex Owner');
        $office = $this->user('office@example.com', 'Olivia Office');
        $tech = $this->user('tech@example.com', 'Tom Technician');
        $other = $this->user('other@example.com', 'Chris Coastal');

        $tenancy->runAs($group, function () use ($group, $owner, $office, $tech) {
            $this->member($group, $owner, UserRole::Owner);
            $this->member($group, $office, UserRole::Admin);
            $this->member($group, $tech, UserRole::Technician);

            $doctor = $this->brand('Doctor Appliance', '#0E7490', 'Surrey');
            $this->brand('Duct Works', '#B45309', 'Burnaby');

            DB::table('brand_user')->insert([
                'company_id' => $group->id, 'brand_id' => $doctor->id, 'user_id' => $tech->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true, 'sort_order' => 1]);
            TaxRate::create(['name' => 'PST', 'rate' => 7, 'sort_order' => 2]);

            $this->customers();
        });

        $tenancy->runAs($coastal, function () use ($coastal, $other, $tech) {
            $this->member($coastal, $other, UserRole::Owner);
            $this->member($coastal, $tech, UserRole::Technician);

            $this->brand('Coastal Appliance Repair', '#1D4ED8', 'Victoria');
            TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true]);

            app(SaveCustomer::class)->handle(null, ['type' => 'residential', 'first_name' => 'Victoria', 'last_name' => 'Island'],
                [['label' => 'mobile', 'number' => '250-555-0199']], [],
                ['line1' => '10 Government St', 'city' => 'Victoria', 'province' => 'BC', 'country' => 'CA']);
        });
    }

    private function user(string $email, string $name, bool $superAdmin = false): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);

        if ($superAdmin) {
            $user->forceFill(['is_super_admin' => true])->save();
        }

        return $user;
    }

    private function member(Company $company, User $user, UserRole $role): void
    {
        Membership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);
        $user->current_company_id ??= $company->id;
        $user->save();
    }

    private function brand(string $name, string $color, string $city): Brand
    {
        $brand = Brand::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'primary_color' => $color,
            'phone' => fake()->numerify('604-###-####'),
            'email' => str($name)->slug()->append('@example.com')->toString(),
            'website' => 'https://'.str($name)->slug()->append('.example.com'),
        ]);

        $brand->addresses()->create([
            'label' => 'Main office',
            'line1' => fake()->streetAddress(),
            'city' => $city,
            'province' => 'BC',
            'postal_code' => 'V3T 1A1',
            'country' => 'CA',
            'is_primary' => true,
        ]);

        return $brand;
    }

    /**
     * A few customers with properties and appliances for the current company.
     */
    private function customers(): void
    {
        $saveCustomer = app(SaveCustomer::class);
        $saveAppliance = app(SaveAppliance::class);

        $jane = $saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => 'Jane', 'last_name' => 'Cooper', 'lead_source' => 'google_business_profile', 'tags' => ['VIP']],
            [['label' => 'mobile', 'number' => '604-555-0142', 'is_primary' => true]],
            [['label' => 'personal', 'email' => 'jane.cooper@example.com', 'is_primary' => true]],
            ['line1' => '8450 128 St', 'city' => 'Surrey', 'province' => 'BC', 'postal_code' => 'V3W 4G1', 'country' => 'CA', 'gate_code' => '#1234'],
        );
        $property = $jane->properties()->first();
        $saveAppliance->handle($property, null, ['type' => 'washer', 'manufacturer' => 'LG', 'model_number' => 'WM3900HWA', 'serial_number' => '912KWPX4B123', 'warranty_expires_on' => now()->addYear()->toDateString()]);
        $saveAppliance->handle($property, null, ['type' => 'refrigerator', 'manufacturer' => 'Samsung', 'model_number' => 'RF28R7351SR', 'serial_number' => '0B4R4BAN500123']);

        $pm = $saveCustomer->handle(
            null,
            ['type' => 'property_manager', 'company_name' => 'Westside Property Management', 'first_name' => 'Mark', 'last_name' => 'Lee', 'lead_source' => 'referral'],
            [['label' => 'work', 'number' => '604-555-0177', 'is_primary' => true]],
            [['label' => 'billing', 'email' => 'ap@westside-pm.example.com', 'is_primary' => true]],
            ['label' => 'Rental on Main', 'line1' => '4120 Main St', 'unit' => '204', 'city' => 'Vancouver', 'province' => 'BC', 'postal_code' => 'V5V 3P6', 'country' => 'CA',
                'site_contact_name' => 'Sam Tenant', 'site_contact_phone' => '778-555-0110', 'access_notes' => 'Call the tenant 30 minutes before arrival.'],
        );
        $saveAppliance->handle($pm->properties()->first(), null, ['type' => 'dishwasher', 'manufacturer' => 'Bosch', 'model_number' => 'SHPM88Z75N']);

        $saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => 'Robert', 'last_name' => 'Fox', 'lead_source' => 'homestars'],
            [['label' => 'mobile', 'number' => '778-555-0123', 'is_primary' => true]],
            [],
            ['line1' => '6200 McKay Ave', 'city' => 'Burnaby', 'province' => 'BC', 'postal_code' => 'V5H 4M9', 'country' => 'CA'],
        );
    }
}
