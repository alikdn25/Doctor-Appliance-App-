<?php

namespace Database\Seeders;

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Customers\SaveAppliance;
use App\Actions\Customers\SaveCustomer;
use App\Actions\Jobs\SaveJob;
use App\Actions\Jobs\VisitWorkflow;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Membership;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
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
 *  us@example.com      Owner of Lone Star Appliance Repair (Austin, Texas: USD, sales tax, en-US)
 *
 * The BC companies show GST + PST; the US company shows a single sales tax. Both are only examples:
 * taxes, currency and formats are company settings.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenancy = app(CurrentCompany::class);

        $this->user('admin@example.com', 'Platform Admin', superAdmin: true);

        $bc = ['country' => 'CA', 'currency' => 'CAD', 'locale' => 'en-CA', 'timezone' => 'America/Vancouver'];
        $group = Company::factory()->create(['name' => 'Doctor Appliance Group', 'slug' => 'doctor-appliance-group', ...$bc]);
        $coastal = Company::factory()->create(['name' => 'Coastal Repair Co', 'slug' => 'coastal-repair-co', ...$bc]);
        $lonestar = Company::factory()->create([
            'name' => 'Lone Star Appliance Repair', 'slug' => 'lone-star-appliance-repair',
            'country' => 'US', 'currency' => 'USD', 'locale' => 'en-US', 'timezone' => 'America/Chicago',
        ]);

        $owner = $this->user('owner@example.com', 'Alex Owner');
        $office = $this->user('office@example.com', 'Olivia Office');
        $tech = $this->user('tech@example.com', 'Tom Technician');
        $other = $this->user('other@example.com', 'Chris Coastal');
        $us = $this->user('us@example.com', 'Jordan Austin');

        $tenancy->runAs($group, function () use ($group, $owner, $office, $tech) {
            $this->member($group, $owner, UserRole::Owner);
            $this->member($group, $office, UserRole::Admin);
            $this->member($group, $tech, UserRole::Technician);

            $doctor = $this->brand('Doctor Appliance', '#0E7490', $this->bcAddress('Surrey'), '604-555-0100');
            $this->brand('Duct Works', '#B45309', $this->bcAddress('Burnaby'), '604-555-0111');

            DB::table('brand_user')->insert([
                'company_id' => $group->id, 'brand_id' => $doctor->id, 'user_id' => $tech->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            ChecklistTemplate::createDefaults();
            Service::createDefaults();
            TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true, 'sort_order' => 1]);
            TaxRate::create(['name' => 'PST', 'rate' => 7, 'is_default' => true, 'sort_order' => 2]);

            $profile = GoogleProfile::create(['brand_id' => $doctor->id, 'label' => 'Surrey', 'review_url' => 'https://g.page/r/doctor-appliance-surrey/review']);
            $doctor->update(['google_profile_id' => $profile->id]);

            $this->jobs($this->bcCustomers(), $doctor, $owner, $tech);
        });

        $tenancy->runAs($coastal, function () use ($coastal, $other, $tech) {
            $this->member($coastal, $other, UserRole::Owner);
            $this->member($coastal, $tech, UserRole::Technician);

            $this->brand('Coastal Appliance Repair', '#1D4ED8', $this->bcAddress('Victoria'), '250-555-0100');
            ChecklistTemplate::createDefaults();
            Service::createDefaults();
            TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true]);

            app(SaveCustomer::class)->handle(null, ['type' => 'residential', 'first_name' => 'Victoria', 'last_name' => 'Island'],
                [['label' => 'mobile', 'number' => '250-555-0199']], [],
                ['line1' => '10 Government St', 'city' => 'Victoria', 'region' => 'BC', 'country' => 'CA']);
        });

        $tenancy->runAs($lonestar, function () use ($lonestar, $us) {
            $this->member($lonestar, $us, UserRole::Owner);

            $brand = $this->brand('Lone Star Appliance Repair', '#9A3412',
                ['line1' => '500 W 2nd St', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701', 'country' => 'US'],
                '512-555-0100');
            ChecklistTemplate::createDefaults();
            Service::createDefaults();
            // Austin: Texas 6.25% + local 2% = 8.25%, entered as one combined rate.
            TaxRate::create(['name' => 'Sales tax', 'rate' => 8.25, 'is_default' => true]);

            $profile = GoogleProfile::create(['label' => 'Austin', 'review_url' => 'https://g.page/r/lone-star-austin/review']);
            $brand->update(['google_profile_id' => $profile->id]);

            $this->jobs($this->usCustomers(), $brand, $us, $us);
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

    /**
     * @return array<string, string>
     */
    private function bcAddress(string $city): array
    {
        return ['line1' => '100 King George Blvd', 'city' => $city, 'region' => 'BC', 'postal_code' => 'V3T 1A1', 'country' => 'CA'];
    }

    /**
     * @param  array<string, string>  $address
     */
    private function brand(string $name, string $color, array $address, string $phone): Brand
    {
        $brand = Brand::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'primary_color' => $color,
            'phone' => $phone,
            'email' => str($name)->slug()->append('@example.com')->toString(),
            'website' => 'https://'.str($name)->slug()->append('.example.com'),
        ]);

        $brand->addresses()->create(['label' => 'Main office', ...$address, 'is_primary' => true]);

        return $brand;
    }

    /**
     * A few customers with properties and appliances for the current company.
     *
     * @return list<Customer>
     */
    private function bcCustomers(): array
    {
        $saveCustomer = app(SaveCustomer::class);
        $saveAppliance = app(SaveAppliance::class);

        $jane = $saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => 'Jane', 'last_name' => 'Cooper', 'lead_source' => 'google_business_profile', 'tags' => ['VIP']],
            [['label' => 'mobile', 'number' => '604-555-0142', 'is_primary' => true]],
            [['label' => 'personal', 'email' => 'jane.cooper@example.com', 'is_primary' => true]],
            ['line1' => '8450 128 St', 'city' => 'Surrey', 'region' => 'BC', 'postal_code' => 'V3W 4G1', 'country' => 'CA', 'gate_code' => '#1234'],
        );
        $property = $jane->properties()->first();
        $saveAppliance->handle($property, null, ['type' => 'washer', 'manufacturer' => 'LG', 'model_number' => 'WM3900HWA', 'serial_number' => '912KWPX4B123', 'warranty_expires_on' => now()->addYear()->toDateString()]);
        $saveAppliance->handle($property, null, ['type' => 'refrigerator', 'manufacturer' => 'Samsung', 'model_number' => 'RF28R7351SR', 'serial_number' => '0B4R4BAN500123']);

        $pm = $saveCustomer->handle(
            null,
            ['type' => 'property_manager', 'company_name' => 'Westside Property Management', 'first_name' => 'Mark', 'last_name' => 'Lee', 'lead_source' => 'referral', 'payment_terms' => 'net_30'],
            [['label' => 'work', 'number' => '604-555-0177', 'is_primary' => true]],
            [['label' => 'billing', 'email' => 'ap@westside-pm.example.com', 'is_primary' => true]],
            ['label' => 'Rental on Main', 'line1' => '4120 Main St', 'unit' => '204', 'city' => 'Vancouver', 'region' => 'BC', 'postal_code' => 'V5V 3P6', 'country' => 'CA',
                'site_contact_name' => 'Sam Tenant', 'site_contact_phone' => '778-555-0110', 'access_notes' => 'Call the tenant 30 minutes before arrival.'],
        );
        $saveAppliance->handle($pm->properties()->first(), null, ['type' => 'dishwasher', 'manufacturer' => 'Bosch', 'model_number' => 'SHPM88Z75N']);

        $robert = $saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => 'Robert', 'last_name' => 'Fox', 'lead_source' => 'directory'],
            [['label' => 'mobile', 'number' => '778-555-0123', 'is_primary' => true]],
            [],
            ['line1' => '6200 McKay Ave', 'city' => 'Burnaby', 'region' => 'BC', 'postal_code' => 'V5H 4M9', 'country' => 'CA'],
        );

        return [$jane, $pm, $robert];
    }

    /**
     * Customers of the US demo company (Austin, Texas).
     *
     * @return list<Customer>
     */
    private function usCustomers(): array
    {
        $saveCustomer = app(SaveCustomer::class);
        $saveAppliance = app(SaveAppliance::class);

        $maria = $saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => 'Maria', 'last_name' => 'Garcia', 'lead_source' => 'google_business_profile'],
            [['label' => 'mobile', 'number' => '(512) 555-0142', 'is_primary' => true]],
            [['label' => 'personal', 'email' => 'maria.garcia@example.com', 'is_primary' => true]],
            ['line1' => '2200 S Lamar Blvd', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78704', 'country' => 'US'],
        );
        $saveAppliance->handle($maria->properties()->first(), null, ['type' => 'washer', 'manufacturer' => 'Whirlpool', 'model_number' => 'WTW5000DW1', 'serial_number' => 'C81204567']);

        $hoa = $saveCustomer->handle(
            null,
            ['type' => 'strata', 'company_name' => 'Barton Creek HOA', 'first_name' => 'Dana', 'last_name' => 'Brooks', 'lead_source' => 'property_manager', 'payment_terms' => 'net_30'],
            [['label' => 'work', 'number' => '512-555-0177', 'is_primary' => true]],
            [['label' => 'billing', 'email' => 'billing@bartoncreek-hoa.example.com', 'is_primary' => true]],
            ['label' => 'Clubhouse', 'line1' => '3600 Barton Creek Blvd', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78735', 'country' => 'US'],
        );
        $saveAppliance->handle($hoa->properties()->first(), null, ['type' => 'dishwasher', 'manufacturer' => 'KitchenAid', 'model_number' => 'KDTM404KPS']);

        $kevin = $saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => 'Kevin', 'last_name' => 'Nguyen', 'lead_source' => 'directory'],
            [['label' => 'mobile', 'number' => '737-555-0123', 'is_primary' => true]],
            [],
            ['line1' => '1100 E 6th St', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78702', 'country' => 'US'],
        );

        return [$maria, $hoa, $kevin];
    }

    /**
     * Jobs in different states: today's visit for the technician, one waiting for parts, one not scheduled.
     *
     * @param  list<Customer>  $customers
     */
    private function jobs(array $customers, Brand $brand, User $owner, User $tech): void
    {
        [$jane, $pm, $robert] = $customers;
        $saveJob = app(SaveJob::class);
        $today = CarbonImmutable::now(currentCompany()->timezone)->startOfDay();
        $visit = fn (CarbonImmutable $start, int $hours, array $people) => [
            'attributes' => ['scheduled_start' => $start->utc(), 'scheduled_end' => $start->addHours($hours)->utc(), 'estimated_duration_minutes' => 60],
            'assignee_ids' => array_map(fn (User $u) => $u->id, $people),
        ];
        $job = fn (Customer $customer, array $attributes, ?array $visitData) => $saveJob->create(
            $customer,
            ['brand_id' => $brand->id, 'property_id' => $customer->properties()->value('id'), 'job_type' => 'repair', ...$attributes],
            $customer->appliances()->pluck('appliances.id')->take(1)->all(),
            [],
            null,
            $visitData,
            $owner,
        );

        $job($jane, ['lead_source' => 'google_business_profile', 'description' => 'Washer stops mid-cycle with OE error.', 'notes' => 'Customer works from home.'],
            $visit($today->setTime(9, 0), 2, [$tech]));

        $parts = $job($pm, ['lead_source' => 'referral', 'description' => 'Dishwasher not draining.'],
            $visit($today->subDays(2)->setTime(13, 0), 2, [$tech]));
        $workflow = app(VisitWorkflow::class);
        $first = $parts->visits()->first();
        $workflow->start($first, $tech);
        $workflow->finish($first, $tech, JobStatus::WaitingForParts, 'Drain pump ordered.');
        $parts->update(['tech_notes' => 'Drain pump seized. Ordered replacement pump.']);

        // Diagnosis paid on the first visit, the repair quoted for when the part arrives.
        $billing = app(SaveBillingDocument::class);
        $taxIds = TaxRate::query()->where('is_default', true)->pluck('id')->all();
        $diagnosis = $billing->createInvoice($parts, [
            'issued_on' => $today->subDays(2)->toDateString(),
            'due_on' => $today->subDays(2)->toDateString(),
            'tax_rate_ids' => $taxIds,
            'items' => [['description' => 'Diagnostic service call', 'quantity' => '1', 'unit_price' => 9500, 'taxable' => true]],
        ], $tech);
        app(RecordPayment::class)->manual($diagnosis, $diagnosis->total, PaymentMethod::CardTerminal, 'TXN-104233', null, now()->subDays(2), $tech);
        $billing->createEstimate($parts, [
            'issued_on' => $today->subDays(2)->toDateString(),
            'valid_until' => $today->addDays(28)->toDateString(),
            'tax_rate_ids' => $taxIds,
            'notes' => 'Diagnostic fee credited when the repair is done.',
            'items' => [
                ['description' => 'Drain pump (OEM)', 'quantity' => '1', 'unit_price' => 18950, 'taxable' => true],
                ['description' => 'Labour: replace drain pump', 'quantity' => '1', 'unit_price' => 14000, 'taxable' => true],
                ['description' => 'Diagnostic fee credit', 'quantity' => '1', 'unit_price' => -9500, 'taxable' => true],
            ],
        ], $tech);

        $job($robert, ['lead_source' => 'directory', 'job_type' => 'installation', 'description' => 'Install new range.'], null);

        // The owner goes on calls too.
        $job($jane, ['lead_source' => 'repeat_customer', 'description' => 'Fridge not cooling.'],
            $visit($today->setTime(13, 0), 2, [$owner]));
    }
}
