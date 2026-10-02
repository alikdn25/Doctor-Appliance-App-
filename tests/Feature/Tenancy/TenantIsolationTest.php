<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Members\SyncMemberBrands;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\BrandAddress;
use App\Models\CashMovement;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePaymentLink;
use App\Models\JobAppliance;
use App\Models\JobBringItem;
use App\Models\JobChecklistItem;
use App\Models\JobCostItem;
use App\Models\JobPhoto;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\JobVisitAssignee;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ReviewRequest;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use App\Models\SupplierReceipt;
use App\Models\SupplierReceiptLink;
use App\Models\TaxRate;
use App\Support\Tenancy\MissingTenantException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        JobPhoto::withoutCompanyScope()->insert([
            'company_id' => $job->company_id, 'service_job_id' => $job->id, 'kind' => 'before',
            'path' => "companies/{$job->company_id}/jobs/{$job->id}/photos/x.jpg", 'client_uuid' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        JobChecklistItem::withoutCompanyScope()->insert([
            'company_id' => $job->company_id, 'service_job_id' => $job->id, 'position' => 0, 'label' => 'Check',
            'is_done' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobBringItem::withoutCompanyScope()->insert([
            'company_id' => $job->company_id, 'service_job_id' => $job->id, 'position' => 0, 'description' => 'Drain pump',
            'quantity' => 1, 'is_checked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        ChecklistTemplate::withoutCompanyScope()->insert([
            'company_id' => $job->company_id, 'job_type' => 'repair', 'items' => json_encode(["Item {$job->company_id}"]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    foreach ([[$this->companyA, $this->jobA, $this->ownerA, $this->taxA], [$this->companyB, $this->jobB, $this->ownerB, $this->taxB]] as [$company, $job, $owner, $tax]) {
        inCompany($company, function () use ($job, $owner, $tax) {
            Service::createDefaults();
            $document = [
                'issued_on' => now()->toDateString(),
                'tax_rate_ids' => [$tax->id],
                'items' => [['description' => 'Secret repair', 'quantity' => '1', 'unit_price' => 20000, 'taxable' => true]],
            ];
            $save = app(SaveBillingDocument::class);
            $save->createEstimate($job, $document, $owner);
            $invoice = $save->createInvoice($job, $document, $owner);
            app(RecordPayment::class)->manual($invoice, 5000, PaymentMethod::Cash, null, null, now(), $owner);
            PaymentProviderConnection::create([
                'provider' => 'square', 'account_id' => 'MERCHANT_'.currentCompany()->id, 'access_token' => 'secret-'.currentCompany()->id,
            ]);
            $link = new InvoicePaymentLink([
                'provider' => 'square', 'provider_link_id' => 'LINK_'.$invoice->id, 'provider_order_id' => 'ORDER_'.$invoice->id,
                'url' => 'https://square.link/u/'.$invoice->id, 'amount' => $invoice->balance, 'currency' => $invoice->currency,
            ]);
            $link->invoice_id = $invoice->id;
            $link->save();
            $cost = new JobCostItem(['description' => 'Secret part', 'quantity' => 1, 'unit_cost' => 1234]);
            $cost->service_job_id = $job->id;
            $cost->currency = $invoice->currency;
            $cost->save();
            $receipt = SupplierReceipt::create(['path' => 'x/receipt-'.$job->id.'.pdf', 'original_name' => 'r.pdf', 'mime' => 'application/pdf', 'size' => 10]);
            SupplierReceiptLink::create(['supplier_receipt_id' => $receipt->id, 'service_job_id' => $job->id]);
            CashMovement::create(['user_id' => $owner->id, 'type' => 'collected', 'amount' => 5000, 'currency' => $invoice->currency, 'occurred_on' => now()->toDateString()]);
            $company = currentCompany();
            SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'AC'.$company->id, 'auth_token' => 'token-'.$company->id, 'phone_number' => '+1604555'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT)]);
            SmsRegistration::create(['business' => ['legal_name' => $company->name]]);
            $profile = GoogleProfile::create(['label' => 'Main', 'review_url' => 'https://g.page/r/'.$company->id.'/review']);
            $message = Message::create([
                'customer_id' => $job->customer_id, 'service_job_id' => $job->id, 'direction' => 'outbound', 'channel' => 'sms',
                'kind' => 'general', 'to' => '+16045550000', 'body' => 'Secret text', 'status' => 'sent',
            ]);
            ReviewRequest::create([
                'customer_id' => $job->customer_id, 'service_job_id' => $job->id, 'google_profile_id' => $profile->id,
                'status' => 'sent', 'sent_at' => now(), 'message_id' => $message->id,
            ]);
        });
    }
    $this->estimateB = Estimate::withoutCompanyScope()->where('company_id', $this->companyB->id)->sole();
    $this->invoiceB = Invoice::withoutCompanyScope()->where('company_id', $this->companyB->id)->sole();
    $this->paymentB = Payment::withoutCompanyScope()->where('company_id', $this->companyB->id)->sole();

    $this->photoB = JobPhoto::withoutCompanyScope()->where('service_job_id', $this->jobB->id)->sole();
    $this->itemB = JobChecklistItem::withoutCompanyScope()->where('service_job_id', $this->jobB->id)->sole();
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
    'job photos' => [JobPhoto::class],
    'job checklist items' => [JobChecklistItem::class],
    'job bring items' => [JobBringItem::class],
    'job cost items' => [JobCostItem::class],
    'supplier receipts' => [SupplierReceipt::class],
    'supplier receipt links' => [SupplierReceiptLink::class],
    'cash movements' => [CashMovement::class],
    'checklist templates' => [ChecklistTemplate::class],
    'estimates' => [Estimate::class],
    'estimate items' => [EstimateItem::class],
    'invoices' => [Invoice::class],
    'invoice items' => [InvoiceItem::class],
    'payments' => [Payment::class],
    'services' => [Service::class],
    'payment provider connections' => [PaymentProviderConnection::class],
    'invoice payment links' => [InvoicePaymentLink::class],
    'sms accounts' => [SmsAccount::class],
    'sms registrations' => [SmsRegistration::class],
    'messages' => [Message::class],
    'google profiles' => [GoogleProfile::class],
    'review requests' => [ReviewRequest::class],
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
        Appliance::class, Brand::class, BrandAddress::class, ChecklistTemplate::class, Customer::class, CustomerEmail::class, GoogleProfile::class, Message::class, ReviewRequest::class, SmsAccount::class, SmsRegistration::class,
        CustomerPhone::class, Estimate::class, EstimateItem::class, Invoice::class, InvoiceItem::class, InvoicePaymentLink::class, Payment::class,
        PaymentProviderConnection::class,
        JobAppliance::class, JobBringItem::class, JobChecklistItem::class, JobCostItem::class, SupplierReceipt::class, SupplierReceiptLink::class, CashMovement::class, JobPhoto::class, JobStatusChange::class,
        JobVisit::class, JobVisitAssignee::class,
        Membership::class, Property::class, Service::class, ServiceJob::class, TaxRate::class,
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

    expect($phoneB->fresh())->number->toBe('+16045550202')->customer_id->toBe($this->customerB->id)
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

test('the calendar only shows the current company\'s visits, people and jobs to schedule', function () {
    ServiceJob::factory()->for($this->propertyB)->create(['brand_id' => $this->brandB->id]);
    $visitA = JobVisit::withoutCompanyScope()->where('service_job_id', $this->jobA->id)->sole();
    $date = $visitA->scheduled_start->setTimezone($this->companyA->timezone)->format('Y-m-d');

    $props = $this->actingAs($this->ownerA)->get(route('calendar', ['date' => $date]))->viewData('page')['props'];

    expect(collect($props['visits'])->pluck('id')->all())->toBe([$visitA->id])
        ->and(collect($props['lanes'])->pluck('id')->filter()->sort()->values()->all())
        ->toBe(collect([$this->ownerA->id, $this->techA->id])->sort()->values()->all())
        ->and($props['unscheduled'])->toBe([]);
});

test('another company\'s visit cannot be moved or assigned through the calendar', function () {
    $this->actingAs($this->ownerA);

    $this->put(route('visits.move', $this->visitB), ['date' => '2030-01-01', 'start_time' => '09:00'])->assertNotFound();

    $visitA = JobVisit::withoutCompanyScope()->where('service_job_id', $this->jobA->id)->sole();
    $this->put(route('visits.move', $visitA), ['date' => '2030-01-01', 'start_time' => '09:00', 'to_user_id' => $this->techB->id])
        ->assertSessionHasErrors('to_user_id');

    expect(JobVisit::withoutCompanyScope()->find($this->visitB->id)->scheduled_start->toDateTimeString())
        ->toBe($this->visitB->scheduled_start->toDateTimeString());
});

test('another company\'s photos, checklist and signature cannot be seen or changed', function () {
    $this->actingAs($this->ownerA);
    $jpeg = UploadedFile::fake()->image('p.jpg');

    $this->get(route('jobs.photos.show', [$this->jobB, $this->photoB]))->assertNotFound();
    $this->delete(route('jobs.photos.destroy', [$this->jobB, $this->photoB]))->assertNotFound();
    $this->post(route('jobs.photos.store', $this->jobB), ['photo' => $jpeg, 'kind' => 'before', 'client_uuid' => (string) Str::uuid()])->assertNotFound();
    $this->put(route('jobs.checklist.toggle', [$this->jobB, $this->itemB]), ['is_done' => true])->assertNotFound();
    $this->post(route('jobs.signature.store', $this->jobB), ['signature' => UploadedFile::fake()->image('s.png'), 'signer_name' => 'X'])->assertNotFound();
    $this->get(route('jobs.signature.show', $this->jobB))->assertNotFound();
    $this->post(route('jobs.appliances.rating-plate', [$this->jobB, $this->applianceB]), ['rating_plate' => $jpeg])->assertNotFound();

    // Another company's photo or item through the company's own job.
    $this->get(route('jobs.photos.show', [$this->jobA, $this->photoB]))->assertNotFound();
    $this->put(route('jobs.checklist.toggle', [$this->jobA, $this->itemB]), ['is_done' => true])->assertNotFound();

    expect(JobPhoto::withoutCompanyScope()->whereKey($this->photoB->id)->exists())->toBeTrue()
        ->and($this->itemB->fresh()->is_done)->toBeFalse()
        ->and(ServiceJob::withoutCompanyScope()->find($this->jobB->id)->signature_path)->toBeNull();
});

test('another company\'s rating plate photo cannot be opened', function () {
    Storage::fake('local');
    Storage::disk('local')->put('companies/b/plate.jpg', 'secret');
    Appliance::withoutCompanyScope()->whereKey($this->applianceB->id)->update(['rating_plate_path' => 'companies/b/plate.jpg']);

    // Owner and technician of company A: the appliance does not exist for them.
    $this->actingAs($this->ownerA)->get(route('appliances.rating-plate', $this->applianceB))->assertNotFound();
    $this->actingAs($this->techA)->get(route('appliances.rating-plate', $this->applianceB))->assertNotFound();

    // A member of both companies, working in company A, cannot read it either.
    $both = memberOf($this->companyA, UserRole::Owner);
    inCompany($this->companyB, fn () => Membership::factory()->create(['company_id' => $this->companyB->id, 'user_id' => $both->id, 'role' => UserRole::Owner]));
    $both->forceFill(['current_company_id' => $this->companyA->id])->save();
    $this->actingAs($both)->get(route('appliances.rating-plate', $this->applianceB))->assertNotFound();

    // The file is not on the public disk, so there is no direct URL to it.
    $this->actingAs($this->ownerB)->get(route('appliances.rating-plate', $this->applianceB))->assertOk();
    expect(Storage::disk('public')->exists('companies/b/plate.jpg'))->toBeFalse();
});

test('a photo uuid used by another company does not collide', function () {
    Storage::fake('local');

    $this->actingAs($this->ownerA)
        ->post(route('jobs.photos.store', $this->jobA), [
            'photo' => UploadedFile::fake()->image('p.jpg'), 'kind' => 'after', 'client_uuid' => $this->photoB->client_uuid,
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect(JobPhoto::withoutCompanyScope()->where('client_uuid', $this->photoB->client_uuid)->count())->toBe(2);
});

test('the checklist settings only show the current company\'s checklists', function () {
    $this->actingAs($this->ownerA)
        ->get(route('company.checklists.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('templates.repair', ["Item {$this->companyA->id}"]));
});

test('another company\'s estimates cannot be seen or changed', function () {
    $this->actingAs($this->ownerA);
    $document = ['issued_on' => now()->toDateString(), 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => '1.00']]];

    $this->get(route('estimates.show', $this->estimateB))->assertNotFound();
    $this->get(route('estimates.edit', $this->estimateB))->assertNotFound();
    $this->put(route('estimates.update', $this->estimateB), $document)->assertNotFound();
    $this->put(route('estimates.decide', $this->estimateB), ['approved' => true])->assertNotFound();
    $this->post(route('estimates.convert', $this->estimateB))->assertNotFound();
    $this->delete(route('estimates.destroy', $this->estimateB))->assertNotFound();
    $this->get(route('estimates.create', $this->jobB))->assertNotFound();
    $this->post(route('estimates.store', $this->jobB), $document)->assertNotFound();

    $estimate = Estimate::withoutCompanyScope()->find($this->estimateB->id);
    expect($estimate->status->value)->toBe('draft')
        ->and($estimate->total)->toBe($this->estimateB->total)
        ->and($estimate->trashed())->toBeFalse()
        ->and(Estimate::withoutCompanyScope()->where('service_job_id', $this->jobB->id)->count())->toBe(1);
});

test('another company\'s invoices and payments cannot be seen or changed', function () {
    $this->actingAs($this->ownerA);
    $document = ['issued_on' => now()->toDateString(), 'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => '1.00']]];

    $this->get(route('invoices.show', $this->invoiceB))->assertNotFound();
    $this->get(route('invoices.edit', $this->invoiceB))->assertNotFound();
    $this->put(route('invoices.update', $this->invoiceB), $document)->assertNotFound();
    $this->post(route('invoices.void', $this->invoiceB))->assertNotFound();
    $this->post(route('payments.store', $this->invoiceB), ['amount' => '10.00', 'method' => 'cash'])->assertNotFound();
    $this->post(route('payments.void', $this->paymentB))->assertNotFound();
    $this->get(route('invoices.create', $this->jobB))->assertNotFound();
    $this->post(route('invoices.store', $this->jobB), $document)->assertNotFound();

    $this->actingAs($this->techA)->get(route('invoices.show', $this->invoiceB))->assertNotFound();

    $invoice = Invoice::withoutCompanyScope()->find($this->invoiceB->id);
    expect($invoice->status->value)->toBe('partially_paid')
        ->and($invoice->amount_paid)->toBe(5000)
        ->and(Payment::withoutCompanyScope()->find($this->paymentB->id)->voided_at)->toBeNull()
        ->and(Invoice::withoutCompanyScope()->where('service_job_id', $this->jobB->id)->count())->toBe(1);
});

test('lists of invoices and estimates only show the current company\'s', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('invoices.index', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.job_id', $this->jobA->id)
            ->where('outstandingTotals', [['currency' => 'CAD', 'amount' => Invoice::withoutCompanyScope()->where('company_id', $this->companyA->id)->sole()->balance]]));

    $this->get(route('invoices.index', ['status' => 'all', 'search' => 'Bella']))
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));

    $this->get(route('customers.show', $this->customerA))
        ->assertInertia(fn (Assert $page) => $page->has('estimates', 1)->has('invoices', 1));

    $this->get(route('jobs.show', $this->jobA))
        ->assertInertia(fn (Assert $page) => $page->has('job.estimates', 1)->has('job.invoices', 1));
});

test('another company\'s tax rate cannot be put on an estimate', function () {
    $this->actingAs($this->ownerA)
        ->post(route('estimates.store', $this->jobA), [
            'issued_on' => now()->toDateString(),
            'tax_rate_ids' => [$this->taxB->id],
            'items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => '10.00']],
        ])
        ->assertSessionHasErrors('tax_rate_ids.0');
});

test('document numbers are counted per company', function () {
    expect(Invoice::withoutCompanyScope()->pluck('number')->all())->toBe(['INV-1', 'INV-1'])
        ->and(Company::find($this->companyA->id)->invoice_next_number)->toBe(2);
});

test('messages, review requests and SMS settings of another company stay hidden', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('customers.show', $this->customerA))->assertInertia(fn (Assert $page) => $page
        ->has('messaging.messages', 1)
        ->where('messaging.messages.0.body', 'Secret text'));
    $this->get(route('customers.show', $this->customerB))->assertNotFound();
    $this->post(route('jobs.messages.opened', $this->jobB), ['kind' => 'general', 'to' => '+1', 'body' => 'x'])->assertNotFound();
    $this->put(route('jobs.ask-for-review', $this->jobB), ['ask' => false])->assertNotFound();

    // Profile ids of company B cannot be taken over through the list editor.
    $foreign = GoogleProfile::withoutCompanyScope()->where('company_id', $this->companyB->id)->sole();
    $this->put(route('company.google-profiles.update'), ['profiles' => [
        ['id' => $foreign->id, 'label' => 'Hijacked', 'review_url' => 'https://example.com/r'],
    ]])->assertRedirect();
    expect($foreign->fresh()->label)->toBe('Main');
});
