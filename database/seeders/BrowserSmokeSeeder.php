<?php

namespace Database\Seeders;

use App\Actions\Billing\SaveBillingDocument;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Billing\PublicDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use LogicException;

/** Deterministic, isolated browser fixtures. Never run against a live installation. */
class BrowserSmokeSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Browser fixtures require APP_ENV=testing.');
        }

        $company = Company::factory()->create([
            'name' => 'Browser Appliance', 'slug' => 'browser-appliance', 'timezone' => 'UTC',
            'sms_mode' => 'automatic', 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '00:00',
        ]);
        $owner = User::factory()->memberOf($company)->create([
            'name' => 'Browser Owner', 'email' => 'browser-owner@example.com',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['browser-recovery-only'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $tech = User::factory()->memberOf($company, UserRole::Technician)->create([
            'name' => 'Browser Technician', 'email' => 'browser-tech@example.com',
        ]);
        $fixture = app(CurrentCompany::class)->runAs($company, function () use ($company, $owner, $tech) {
            $brand = Brand::factory()->create(['company_id' => $company->id, 'name' => 'Browser Appliance']);
            $customer = Customer::factory()->for($company)->withPhone('+16045550142')->create([
                'first_name' => 'Jane', 'last_name' => 'Browser', 'notes' => 'Please text before arriving. Baby naps after 1pm.',
            ]);
            $customer->phones()->create(['number' => '+16045550143']);
            $property = Property::factory()->for($customer)->create([
                'line1' => '123 Browser Street', 'city' => 'Vancouver', 'postal_code' => 'V6B 1A1',
            ]);
            $job = ServiceJob::factory()->for($property)->withVisit($tech, [
                'scheduled_start' => now()->startOfDay()->setTime(13, 0),
                'scheduled_end' => now()->startOfDay()->setTime(15, 0),
            ])->create(['brand_id' => $brand->id, 'description' => 'Browser dishwasher repair']);
            $waiting = ServiceJob::factory()->for($property)->create([
                'brand_id' => $brand->id, 'status' => JobStatus::WaitingForParts,
                'description' => 'Old repair still waiting for parts', 'created_at' => now()->subDays(60),
            ]);
            $gst = TaxRate::create(['name' => 'GST', 'rate' => '5', 'is_default' => true]);
            $pst = TaxRate::create(['name' => 'PST', 'rate' => '7', 'is_default' => true, 'sort_order' => 1]);
            $invoice = app(SaveBillingDocument::class)->createInvoice($job, [
                'issued_on' => now()->toDateString(), 'tax_rate_ids' => [$gst->id, $pst->id],
                'items' => [
                    ['description' => 'Labour GST only', 'quantity' => '1', 'unit_price' => 10000, 'taxable' => true, 'tax_rate_ids' => [$gst->id]],
                    ['description' => 'Part GST and PST', 'quantity' => '1', 'unit_price' => 5000, 'taxable' => true, 'tax_rate_ids' => [$gst->id, $pst->id]],
                ],
            ], $owner);
            $category = BusinessExpenseCategory::create(['name' => 'Tools']);
            $category->forceFill(['created_by' => $owner->id])->save();
            $expense = new BusinessExpense([
                'category_id' => $category->id, 'spent_on' => now()->toDateString(),
                'description' => 'Technician drill', 'amount' => 8000, 'tax_amount' => 960,
            ]);
            $expense->forceFill(['currency' => 'CAD', 'created_by' => $tech->id])->save();
            SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACbrowser', 'auth_token' => 'browser-test-token', 'phone_number' => '+16045550100']);
            foreach (['+16045550142', '+16045550143'] as $phone) {
                Message::create([
                    'customer_id' => $customer->id, 'service_job_id' => $job->id,
                    'direction' => 'inbound', 'channel' => 'sms', 'kind' => MessageKind::Reply,
                    'from' => $phone, 'to' => '+16045550100', 'body' => 'Can you text before arriving?',
                    'status' => 'received', 'sent_at' => now(),
                ]);
            }

            return [
                'customer_id' => $customer->id, 'job_id' => $job->id, 'waiting_job_id' => $waiting->id,
                'invoice_id' => $invoice->id, 'public_token' => PublicDocument::token($invoice),
                'gst_id' => $gst->id, 'pst_id' => $pst->id, 'today' => now()->toDateString(),
            ];
        });
        $foreign = Company::factory()->create(['name' => 'Foreign Browser Company']);
        $fixture['foreign_customer_id'] = app(CurrentCompany::class)->runAs($foreign, fn () => Customer::factory()->for($foreign)->create()->id);
        File::ensureDirectoryExists(storage_path('framework/testing'));
        File::put(storage_path('framework/testing/browser-fixture.json'), json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
