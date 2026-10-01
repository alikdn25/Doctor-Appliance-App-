<?php

namespace Database\Seeders;

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
        });

        $tenancy->runAs($coastal, function () use ($coastal, $other, $tech) {
            $this->member($coastal, $other, UserRole::Owner);
            $this->member($coastal, $tech, UserRole::Technician);

            $this->brand('Coastal Appliance Repair', '#1D4ED8', 'Victoria');
            TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true]);
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
}
