<?php

use App\Enums\UserRole;
use App\Mail\DocumentMail;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\PublicDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->inUnitedStates()->create(['name' => 'Lone Star Group']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create([
        'company_id' => $this->company->id, 'name' => 'Lone Star Appliance', 'phone' => '512-555-0100',
        'tax_number' => 'EIN 12-3456789', 'invoice_terms' => 'Payment due as per terms.', 'invoice_footer' => 'Thank you for your business!',
        'sender_name' => 'Lone Star Billing', 'email' => 'office@lonestar.example.com',
    ]);
    $customer = Customer::factory()->for($this->company)->withPhone('512-555-0142')->withEmail('maria@example.com')
        ->create(['first_name' => 'Maria', 'last_name' => 'Garcia']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer)->state(['country' => 'US', 'region' => 'TX', 'postal_code' => '78704']))
        ->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);

    $this->post(route('invoices.store', $this->job), documentPayload());
    $this->invoice = inCompany($this->company, fn () => Invoice::sole());
});

test('the printed document is branded and formatted for the company', function () {
    $data = inCompany($this->company, fn () => DocumentPrint::data($this->invoice->fresh()));

    expect($data)
        ->title->toBe('Invoice')
        ->total->toBe('$280.50')
        ->balance->toBe('$280.50')
        ->terms->toBe('Payment due as per terms.')
        ->footer->toBe('Thank you for your business!')
        ->and($data['brand'])->name->toBe('Lone Star Appliance')->phone->toBe('(512) 555-0100')->tax_number->toBe('EIN 12-3456789')
        ->and($data['customer'])->name->toBe('Maria Garcia')->phone->toBe('(512) 555-0142')
        ->and($data['items'][1])->unit_price->toBe('$185.50')->quantity->toBe('1')
        ->and($data['issued_on'])->toMatch('/^[A-Z][a-z]{2} \d{1,2}, \d{4}$/');
});

test('staff download the PDF of an invoice and an estimate', function () {
    $this->get(route('invoices.pdf', $this->invoice))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee('%PDF', false);

    $this->post(route('estimates.store', $this->job), documentPayload());
    $estimate = inCompany($this->company, fn () => Estimate::sole());
    $this->get(route('estimates.pdf', $estimate))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('another company cannot open the PDF', function () {
    app(CurrentCompany::class)->forget();
    $this->actingAs(memberOf(Company::factory()->create()));

    $this->get(route('invoices.pdf', $this->invoice))->assertNotFound();
});

test('a technician who is not on the job cannot open or send it', function () {
    $this->actingAs(memberOf($this->company, UserRole::Technician));

    $this->get(route('invoices.pdf', $this->invoice))->assertForbidden();
    $this->post(route('invoices.send', $this->invoice), ['email' => 'x@example.com', 'message' => 'Hi'])->assertForbidden();
});

test('the invoice is emailed with the PDF and a link to its online page', function () {
    Mail::fake();

    $this->get(route('invoices.show', $this->invoice))->assertInertia(fn (Assert $page) => $page
        ->where('delivery.email', 'maria@example.com')
        ->where('delivery.sent_at', null)
        ->where('delivery.message', fn (string $message) => str_contains($message, 'Hi Maria') && str_contains($message, '$280.50')));

    $this->post(route('invoices.send', $this->invoice), ['email' => 'maria@example.com', 'message' => 'Hi Maria, here is your invoice.'])
        ->assertSessionHasNoErrors();

    $invoice = $this->invoice->fresh();
    expect($invoice->sent_to)->toBe('maria@example.com')
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($invoice->public_token)->toStartWith('i')->toHaveLength(48)
        ->and(AuditLog::where('action', 'invoice.sent')->exists())->toBeTrue();

    Mail::assertQueued(DocumentMail::class, function (DocumentMail $mail) use ($invoice) {
        $mail->assertHasSubject("Invoice {$invoice->number} from Lone Star Appliance");
        $mail->assertFrom(config('mail.from.address'), 'Lone Star Billing');
        $mail->assertHasReplyTo('office@lonestar.example.com');
        $mail->assertSeeInHtml('Hi Maria, here is your invoice.');
        $mail->assertSeeInHtml(route('documents.public', $invoice->public_token));

        return $mail->hasTo('maria@example.com') && count($mail->attachments()) === 1;
    });
});

test('the queued email renders without a tenant', function () {
    $mail = new DocumentMail($this->invoice, 'Hello');
    app(CurrentCompany::class)->forget();

    $html = $mail->render();
    $pdf = $mail->attachments()[0];

    expect($html)->toContain('Hello')->toContain('/d/i')
        ->and($pdf->as)->toBe("{$this->invoice->number}.pdf");
});

test('sending needs an email address and a message', function () {
    Mail::fake();

    $this->post(route('invoices.send', $this->invoice), ['email' => 'not-an-email', 'message' => ''])
        ->assertSessionHasErrors(['email', 'message']);

    Mail::assertNothingQueued();
});

test('the customer opens the online page without logging in', function () {
    $token = inCompany($this->company, fn () => PublicDocument::token($this->invoice));
    auth()->logout();

    $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
        ->component('public/document')
        ->where('document.number', $this->invoice->number)
        ->where('document.brand.name', 'Lone Star Appliance')
        ->where('document.balance', '$280.50')
        ->where('canPay', false));

    expect($this->invoice->fresh()->viewed_at)->not->toBeNull();

    $this->get(route('documents.public.pdf', $token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('documents.public', 'i'.str_repeat('x', 47)))->assertNotFound();
    $this->get(route('documents.public', 'nonsense'))->assertNotFound();
});

test('the customer pays the balance online from the invoice page', function () {
    config(['services.square' => [
        'environment' => 'sandbox', 'application_id' => 'app', 'application_secret' => 'secret',
        'webhook_signature_key' => 'k', 'webhook_url' => 'https://x.test/w', 'api_version' => '2025-10-16',
    ]]);
    Http::fake(['*/v2/online-checkout/payment-links' => Http::response(['payment_link' => [
        'id' => 'PL1', 'url' => 'https://sandbox.square.link/u/PAY', 'order_id' => 'ORDER1',
    ]])]);
    $this->company->update(['payment_provider' => 'square']);
    inCompany($this->company, fn () => PaymentProviderConnection::create([
        'provider' => 'square', 'account_id' => 'M1', 'location_id' => 'LOC1', 'currency' => 'USD',
        'access_token' => 'tok', 'refresh_token' => 'ref', 'token_expires_at' => now()->addDays(30),
    ]));
    $token = inCompany($this->company, fn () => PublicDocument::token($this->invoice));
    auth()->logout();

    $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page->where('canPay', true));

    $this->post(route('documents.public.pay', $token))->assertRedirect('https://sandbox.square.link/u/PAY');
    Http::assertSent(fn ($r) => $r['quick_pay']['price_money']['amount'] === 28050);
});

test('a voided invoice is shown as cancelled and cannot be sent', function () {
    $this->post(route('invoices.void', $this->invoice), ['reason' => 'Duplicate']);
    $token = inCompany($this->company, fn () => PublicDocument::token($this->invoice->fresh()));

    $this->post(route('invoices.send', $this->invoice), ['email' => 'maria@example.com', 'message' => 'Hi'])->assertStatus(422);

    auth()->logout();
    $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
        ->where('document.status', 'void')->where('canPay', false));
});
