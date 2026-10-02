<?php

use App\Actions\Billing\RecordPayment;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Payments\PaymentLink;
use App\Payments\PaymentProvider;
use App\Payments\PaymentProviders;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Stands in for Square (next task) to prove invoices work through the provider interface only.
 */
class FakePaymentProvider implements PaymentProvider
{
    public function key(): string
    {
        return 'fakepay';
    }

    public function label(): string
    {
        return 'FakePay';
    }

    public function isConnected(Company $company): bool
    {
        return true;
    }

    public function createPaymentLink(Invoice $invoice, int $amount): PaymentLink
    {
        return new PaymentLink("https://pay.example.test/{$invoice->number}/{$amount}", "link-{$invoice->id}");
    }
}

beforeEach(function () {
    config(['payments.providers' => [FakePaymentProvider::class]]);

    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id, 'status' => 'completed']);

    $this->actingAs($this->owner);
});

test('no provider is installed yet: companies record payments by hand', function () {
    config(['payments.providers' => []]);

    expect(app(PaymentProviders::class)->options())->toBe([])
        ->and(app(PaymentProviders::class)->forCompany($this->company))->toBeNull();
});

test('a company picks an installed provider in settings', function () {
    $this->get(route('company.settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('paymentProviders', [['value' => 'fakepay', 'label' => 'FakePay']]));

    $payload = [
        'name' => 'Doctor Appliance', 'timezone' => 'America/Vancouver', 'currency' => 'CAD',
        'invoice_prefix' => 'INV-', 'invoice_next_number' => 1, 'estimate_prefix' => 'EST-', 'estimate_next_number' => 1,
        'business_hours' => Company::defaultBusinessHours(), 'travel_buffer_minutes' => 30,
    ];

    $this->put(route('company.settings.update'), [...$payload, 'payment_provider' => 'square'])->assertSessionHasErrors('payment_provider');
    $this->put(route('company.settings.update'), [...$payload, 'payment_provider' => 'fakepay'])->assertSessionHasNoErrors();

    $company = $this->company->fresh();
    expect($company->payment_provider)->toBe('fakepay')
        ->and(app(PaymentProviders::class)->forCompany($company))->toBeInstanceOf(FakePaymentProvider::class);

    $this->put(route('company.settings.update'), [...$payload, 'payment_provider' => null]);
    expect($this->company->fresh()->payment_provider)->toBeNull();
});

test('a payment reported by a provider is recorded once and pays the invoice', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = inCompany($this->company, fn () => Invoice::sole());

    inCompany($this->company, function () use ($invoice) {
        $record = app(RecordPayment::class);
        $record->fromProvider($invoice, 'fakepay', 'pay_123', 28050, now(), 'Visa •••• 4242');
        // The same webhook delivered again.
        $record->fromProvider($invoice, 'fakepay', 'pay_123', 28050, now());
    });

    $payment = Payment::withoutCompanyScope()->sole();
    expect($payment)
        ->method->toBe(PaymentMethod::Online)
        ->provider->toBe('fakepay')
        ->provider_payment_id->toBe('pay_123')
        ->user_id->toBeNull()
        ->and(Invoice::withoutCompanyScope()->find($invoice->id)->status)->toBe(InvoiceStatus::Paid)
        ->and(ServiceJob::withoutCompanyScope()->find($this->job->id)->status->value)->toBe('paid');

    // Online payments are refunded at the provider, not voided here.
    $this->post(route('payments.void', $payment))->assertForbidden();
});

test('the provider creates a payment link for an invoice amount', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = inCompany($this->company, fn () => Invoice::sole());

    $link = app(PaymentProviders::class)->find('fakepay')->createPaymentLink($invoice, $invoice->balance);

    expect($link->url)->toBe("https://pay.example.test/{$invoice->number}/28050");
});
