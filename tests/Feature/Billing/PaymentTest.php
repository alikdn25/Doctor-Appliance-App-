<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($property)->withVisit($this->tech)->create(['brand_id' => $brand->id]);

    $this->actingAs($this->owner)->post(route('invoices.store', $this->job), documentPayload());
    $this->invoice = inCompany($this->company, fn () => Invoice::sole());
});

function invoiceNow(Invoice $invoice): Invoice
{
    return Invoice::withoutCompanyScope()->find($invoice->id);
}

function paymentsOf(Invoice $invoice)
{
    return Payment::withoutCompanyScope()->where('invoice_id', $invoice->id)->orderBy('id')->get();
}

test('every manual method can be recorded, as partial payments', function () {
    $this->actingAs($this->tech);

    $this->post(route('payments.store', $this->invoice), ['amount' => '50', 'method' => 'cash'])->assertRedirect();
    $this->post(route('payments.store', $this->invoice), ['amount' => '50.00', 'method' => 'cheque', 'reference' => '0042'])->assertRedirect();
    $this->post(route('payments.store', $this->invoice), ['amount' => '50.00', 'method' => 'e_transfer', 'reference' => 'CA1x9Z'])->assertRedirect();
    $this->post(route('payments.store', $this->invoice), ['amount' => '100.00', 'method' => 'card_terminal', 'reference' => 'TXN-778812'])->assertRedirect();

    expect(invoiceNow($this->invoice))->status->toBe(InvoiceStatus::PartiallyPaid)->amount_paid->toBe(25000)->balance->toBe(3050);

    $this->post(route('payments.store', $this->invoice), ['amount' => '30.50', 'method' => 'other', 'note' => 'Gift card from the store'])->assertRedirect();

    $payments = paymentsOf($this->invoice);
    expect($payments->pluck('method')->all())->toBe([
        PaymentMethod::Cash, PaymentMethod::Cheque, PaymentMethod::ETransfer, PaymentMethod::CardTerminal, PaymentMethod::Other,
    ])
        ->and($payments->pluck('reference')->all())->toBe([null, '0042', 'CA1x9Z', 'TXN-778812', null])
        ->and($payments->last()->note)->toBe('Gift card from the store')
        ->and($payments->pluck('user_id')->unique()->all())->toBe([$this->tech->id])
        ->and(invoiceNow($this->invoice))
        ->status->toBe(InvoiceStatus::Paid)
        ->balance->toBe(0)
        ->paid_at->not->toBeNull();
});

test('the own terminal needs a transaction number and other needs a note', function () {
    $this->post(route('payments.store', $this->invoice), ['amount' => '10', 'method' => 'card_terminal'])->assertSessionHasErrors('reference');
    $this->post(route('payments.store', $this->invoice), ['amount' => '10', 'method' => 'other'])->assertSessionHasErrors('note');

    expect(paymentsOf($this->invoice))->toHaveCount(0);
});

test('a payment needs a positive amount, a manual method and no more than the balance', function () {
    $this->post(route('payments.store', $this->invoice), ['amount' => '0', 'method' => 'cash'])->assertSessionHasErrors('amount');
    $this->post(route('payments.store', $this->invoice), ['amount' => '1.234', 'method' => 'cash'])->assertSessionHasErrors('amount');
    $this->post(route('payments.store', $this->invoice), ['amount' => '10', 'method' => 'online'])->assertSessionHasErrors('method');
    $this->post(route('payments.store', $this->invoice), ['amount' => '280.51', 'method' => 'cash'])->assertSessionHasErrors('amount');
    $this->post(route('payments.store', $this->invoice), ['amount' => '10', 'method' => 'cash', 'received_on' => now()->addDays(2)->toDateString()])
        ->assertSessionHasErrors('received_on');

    expect(paymentsOf($this->invoice))->toHaveCount(0);
});

test('a payment received on an earlier day is dated that day', function () {
    $yesterday = CarbonImmutable::now('America/Vancouver')->subDay()->toDateString();

    $this->post(route('payments.store', $this->invoice), ['amount' => '10', 'method' => 'cheque', 'received_on' => $yesterday]);

    expect(paymentsOf($this->invoice)->sole()->received_at->setTimezone('America/Vancouver')->format('Y-m-d H:i'))
        ->toBe("{$yesterday} 12:00");
});

test('a paid invoice takes no more payments', function () {
    $this->post(route('payments.store', $this->invoice), ['amount' => '280.50', 'method' => 'cash']);

    $this->post(route('payments.store', $this->invoice), ['amount' => '1', 'method' => 'cash'])->assertForbidden();
});

test('the office voids a payment entered by mistake; it stays in the history', function () {
    $this->post(route('payments.store', $this->invoice), ['amount' => '280.50', 'method' => 'cash']);
    $payment = paymentsOf($this->invoice)->sole();

    $this->actingAs($this->tech)->post(route('payments.void', $payment))->assertForbidden();

    $this->actingAs($this->owner)->post(route('payments.void', $payment), ['reason' => 'Typed the wrong invoice'])->assertRedirect();

    expect(paymentsOf($this->invoice)->sole())
        ->voided_at->not->toBeNull()
        ->voided_by->toBe($this->owner->id)
        ->void_reason->toBe('Typed the wrong invoice')
        ->and(invoiceNow($this->invoice))->status->toBe(InvoiceStatus::Unpaid)->amount_paid->toBe(0)->balance->toBe(28050)
        ->and(inCompany($this->company, fn () => AuditLog::where('action', 'payment.voided')->sole()->changes['amount']))->toBe(28050);

    // Voiding twice changes nothing.
    $this->post(route('payments.void', $payment))->assertForbidden();
});

test('the invoice page shows payments with who took them', function () {
    $this->actingAs($this->tech)->post(route('payments.store', $this->invoice), ['amount' => '20', 'method' => 'card_terminal', 'reference' => 'T1']);

    $this->actingAs($this->owner)->get(route('invoices.show', $this->invoice))
        ->assertInertia(fn ($page) => $page
            ->has('document.payments', 1)
            ->where('document.payments.0.method_label', 'Card (own terminal)')
            ->where('document.payments.0.reference', 'T1')
            ->where('document.payments.0.user', $this->tech->name)
            ->where('document.amount_paid', 2000)
            ->where('can.voidPayments', true));
});
