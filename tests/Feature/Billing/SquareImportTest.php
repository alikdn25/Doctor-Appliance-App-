<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PaymentProviderConnection;
use App\Models\SquareImport;
use App\Models\SquareInvoice;
use App\Support\Reports\TaxReserve;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Import from Square (faked Square API): duplicates by Square id, phone and email; merge; a repeated import without
 * duplicates; imported invoices left out of the tax reserve; Undo to Deleted items; Owner only; nothing sent.
 */
const SQ = 'https://connect.squareupsandbox.com';

beforeEach(function () {
    Mail::fake();
    config(['services.square' => [
        'environment' => 'sandbox', 'application_id' => 'a', 'application_secret' => 's',
        'webhook_signature_key' => 'k', 'webhook_url' => 'https://app.example.test/webhooks/payments/square', 'api_version' => '2025-10-16',
    ]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['country' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    inCompany($this->company, fn () => PaymentProviderConnection::create([
        'provider' => 'square', 'account_id' => 'M1', 'account_name' => 'Doctor Appliance', 'location_id' => 'L1', 'currency' => 'CAD',
        'access_token' => 'tok', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(30),
    ]));

    // Already in the book: Bob (same phone as a Square customer) and Carol (same email).
    $this->bob = Customer::factory()->for($this->company)->withPhone('604-555-0101')->create(['first_name' => 'Bob', 'last_name' => null, 'notes' => null]);
    $this->carol = Customer::factory()->for($this->company)->withEmail('carol@example.com')->create(['first_name' => 'Carol', 'last_name' => 'King']);

    $this->squareCustomers = [
        ['id' => 'SQ-ANNA', 'given_name' => 'Anna', 'family_name' => 'Kim', 'phone_number' => '(604) 555-0199', 'email_address' => 'Anna@Example.com',
            'address' => ['address_line_1' => '123 Main St', 'address_line_2' => 'Unit 4', 'locality' => 'Surrey', 'administrative_district_level_1' => 'BC', 'postal_code' => 'V3T 1A1', 'country' => 'CA'],
            'note' => 'Gate code 1234', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['id' => 'SQ-BOB', 'given_name' => 'Bob', 'family_name' => 'Stone', 'phone_number' => '+1 604 555 0101', 'note' => 'Prefers mornings', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['id' => 'SQ-CAROL', 'given_name' => 'Carol', 'family_name' => 'King', 'email_address' => 'carol@example.com', 'updated_at' => '2026-01-01T00:00:00Z'],
        ['id' => 'SQ-NOCONTACT', 'company_name' => 'Walk-in', 'updated_at' => '2026-01-01T00:00:00Z'],
    ];
    $this->squareInvoices = [
        ['id' => 'INV-A', 'invoice_number' => '000101', 'status' => 'PAID', 'order_id' => 'ORD-A', 'primary_recipient' => ['customer_id' => 'SQ-ANNA'],
            'payment_requests' => [['computed_amount_money' => ['amount' => 11200, 'currency' => 'CAD'], 'total_completed_amount_money' => ['amount' => 11200, 'currency' => 'CAD'], 'due_date' => '2026-03-01']],
            'sale_or_service_date' => '2026-02-15', 'created_at' => '2026-02-15T18:00:00Z', 'updated_at' => '2026-02-20T00:00:00Z'],
        ['id' => 'INV-B', 'invoice_number' => '000102', 'status' => 'UNPAID', 'order_id' => 'ORD-B', 'primary_recipient' => ['customer_id' => 'SQ-BOB'],
            'payment_requests' => [['computed_amount_money' => ['amount' => 5000, 'currency' => 'CAD'], 'due_date' => '2026-11-01']],
            'created_at' => '2026-10-15T18:00:00Z', 'updated_at' => '2026-10-15T18:00:00Z'],
        ['id' => 'INV-DRAFT', 'status' => 'DRAFT', 'order_id' => 'ORD-C', 'updated_at' => '2026-10-15T18:00:00Z'],
    ];
    $this->scopes = ['MERCHANT_PROFILE_READ', 'PAYMENTS_READ', 'PAYMENTS_WRITE', 'ORDERS_READ', 'ORDERS_WRITE', 'CUSTOMERS_READ', 'INVOICES_READ'];
    $this->fakeSquare = function () {
        Http::fake([
            SQ.'/oauth2/token/status' => fn () => Http::response(['scopes' => $this->scopes]),
            SQ.'/v2/customers*' => fn () => Http::response(['customers' => $this->squareCustomers]),
            SQ.'/v2/locations' => Http::response(['locations' => [['id' => 'L1', 'name' => 'Main']]]),
            SQ.'/v2/invoices*' => fn () => Http::response(['invoices' => $this->squareInvoices]),
            SQ.'/v2/orders/batch-retrieve' => fn ($request) => Http::response(['orders' => array_values(array_filter([
                ['id' => 'ORD-A', 'total_money' => ['amount' => 11200, 'currency' => 'CAD'], 'total_tax_money' => ['amount' => 1200, 'currency' => 'CAD'],
                    'line_items' => [['name' => 'Washer repair', 'quantity' => '1', 'total_money' => ['amount' => 11200, 'currency' => 'CAD'], 'total_tax_money' => ['amount' => 1200, 'currency' => 'CAD']]],
                    'taxes' => [['name' => 'GST', 'percentage' => '5', 'applied_money' => ['amount' => 500, 'currency' => 'CAD']], ['name' => 'PST', 'percentage' => '7', 'applied_money' => ['amount' => 700, 'currency' => 'CAD']]],
                    'tenders' => [['type' => 'CARD', 'amount_money' => ['amount' => 11200, 'currency' => 'CAD'], 'created_at' => '2026-02-20T00:00:00Z']]],
                ['id' => 'ORD-B', 'total_money' => ['amount' => 5000, 'currency' => 'CAD'], 'line_items' => [['name' => 'Service call', 'quantity' => '1', 'total_money' => ['amount' => 5000, 'currency' => 'CAD']]]],
            ], fn ($o) => in_array($o['id'], $request['order_ids'], true)))]),
        ]);
    };
    ($this->fakeSquare)();
    $this->actingAs($this->owner);
});

/** Reads Square and returns the import waiting for review (the queue runs at once in tests). */
function scanSquare(): SquareImport
{
    test()->post(route('square-import.start'))->assertRedirect(route('square-import.show'));

    return inCompany(test()->company, fn () => SquareImport::query()->latest('id')->firstOrFail());
}

function importSquare(SquareImport $import, array $data = []): SquareImport
{
    test()->post(route('square-import.run', $import), $data)->assertRedirect(route('square-import.show'));

    return inCompany(test()->company, fn () => $import->fresh());
}

test('duplicates are found by phone and email, and the review shows the counts', function () {
    $import = scanSquare();

    expect($import->status)->toBe('review')
        ->and($import->counts)->toMatchArray(['new' => 2, 'matched' => 2, 'linked' => 0, 'no_contact' => 1, 'total' => 4]);

    $this->get(route('square-import.show'))->assertInertia(fn ($page) => $page
        ->component('company/square-import')
        ->has('matches', 2)
        ->where('matches.0.customer.id', $this->bob->id)
        ->where('matches.0.matched_by', 'phone')
        ->where('matches.1.customer.id', $this->carol->id)
        ->where('matches.1.matched_by', 'email'));

    // Nothing is written before Import.
    expect(Customer::withoutCompanyScope()->count())->toBe(2);
});

test('Import merges matches, adds new customers with phones in E.164, and the invoice history', function () {
    $import = importSquare(scanSquare());

    expect($import->status)->toBe('done')
        ->and($import->counts)->toMatchArray(['customers_created' => 2, 'customers_merged' => 2, 'invoices_created' => 2]);

    inCompany($this->company, function () {
        $anna = Customer::query()->where('square_customer_id', 'SQ-ANNA')->sole();
        expect($anna->display_name)->toBe('Anna Kim')
            ->and($anna->primaryPhone->number)->toBe('+16045550199')
            ->and($anna->primaryEmail->email)->toBe('anna@example.com')
            ->and($anna->properties()->sole()->only(['line1', 'unit', 'city', 'region']))->toBe(['line1' => '123 Main St', 'unit' => 'Unit 4', 'city' => 'Surrey', 'region' => 'BC'])
            ->and($anna->notes)->toBe('Gate code 1234');

        // Merge fills only what was missing: Bob keeps his name, gets the last name and the note.
        $bob = $this->bob->fresh();
        expect($bob->first_name)->toBe('Bob')->and($bob->last_name)->toBe('Stone')->and($bob->notes)->toBe('Prefers mornings')
            ->and($bob->square_customer_id)->toBe('SQ-BOB')->and($bob->phones()->count())->toBe(1);

        $invoice = SquareInvoice::query()->where('square_invoice_id', 'INV-A')->sole();
        expect($invoice->customer_id)->toBe($anna->id)
            ->and($invoice->only(['number', 'status', 'currency', 'total', 'tax_total', 'subtotal', 'amount_paid']))
            ->toBe(['number' => '000101', 'status' => 'paid', 'currency' => 'CAD', 'total' => 11200, 'tax_total' => 1200, 'subtotal' => 10000, 'amount_paid' => 11200])
            ->and($invoice->issued_on->toDateString())->toBe('2026-02-15')
            ->and($invoice->taxes[0]['name'])->toBe('GST')
            ->and($invoice->payments[0]['method'])->toBe('card')
            ->and(SquareInvoice::query()->where('square_invoice_id', 'INV-B')->sole()->customer_id)->toBe($bob->id)
            ->and(SquareInvoice::query()->where('square_invoice_id', 'INV-DRAFT')->exists())->toBeFalse();
    });

    $log = AuditLog::query()->where('action', 'square_import.done')->sole();
    expect($log->user_id)->toBe($this->owner->id)->and($log->changes['customers'])->toBe(4)->and($log->changes['invoices'])->toBe(2);

    // The customer card shows the imported invoices in the history.
    $anna = Customer::withoutCompanyScope()->where('square_customer_id', 'SQ-ANNA')->sole();
    $this->get(route('customers.show', $anna))->assertInertia(fn ($page) => $page->where('importedInvoices.0.number', '000101'));
});

test('skip and create new are respected', function () {
    $import = scanSquare();
    $rows = inCompany($this->company, fn () => $import->rows()->where('kind', 'match')->orderBy('id')->pluck('id')->all());

    importSquare($import, ['decisions' => [$rows[0] => 'skip', $rows[1] => 'new']]);

    inCompany($this->company, function () {
        expect($this->bob->fresh()->square_customer_id)->toBeNull()
            ->and(Customer::query()->where('square_customer_id', 'SQ-BOB')->exists())->toBeFalse()
            ->and(Customer::query()->where('first_name', 'Carol')->count())->toBe(2);
    });
});

test('a repeated import brings only new and changed records, never duplicates', function () {
    importSquare(scanSquare());
    $customers = Customer::withoutCompanyScope()->count();

    // At Square: Anna changed, one new customer, one invoice changed.
    $this->squareCustomers[0]['updated_at'] = '2026-11-01T00:00:00Z';
    $this->squareCustomers[0]['email_address'] = 'anna.kim@example.com';
    $this->squareCustomers[] = ['id' => 'SQ-NEW', 'given_name' => 'Dan', 'phone_number' => '604-555-0177', 'updated_at' => '2026-11-01T00:00:00Z'];
    $this->squareInvoices[1]['status'] = 'PAID';
    $this->squareInvoices[1]['updated_at'] = '2026-11-02T00:00:00Z';
    ($this->fakeSquare)();

    $second = scanSquare();
    expect($second->counts)->toMatchArray(['new' => 1, 'matched' => 0, 'linked' => 4]);

    $second = importSquare($second);
    expect($second->counts)->toMatchArray(['customers_created' => 1, 'customers_updated' => 1, 'invoices_created' => 0, 'invoices_updated' => 1]);

    inCompany($this->company, function () use ($customers) {
        expect(Customer::query()->count())->toBe($customers + 1)
            ->and(SquareInvoice::query()->count())->toBe(2)
            ->and(SquareInvoice::query()->where('square_invoice_id', 'INV-B')->sole()->status)->toBe('paid')
            ->and(Customer::query()->where('square_customer_id', 'SQ-ANNA')->sole()->emails()->pluck('email')->sort()->values()->all())
            ->toBe(['anna.kim@example.com', 'anna@example.com']);
    });
});

test('imported invoices are left out of the tax reserve', function () {
    $this->company->update(['tax_reserve_basis' => 'issued']);
    $before = inCompany($this->company, fn () => TaxReserve::month(CarbonImmutable::parse('2026-02-01'), $this->owner));

    importSquare(scanSquare());

    $after = inCompany($this->company, fn () => TaxReserve::month(CarbonImmutable::parse('2026-02-01'), $this->owner));
    expect($after['taxes'])->toBe($before['taxes'])->and($after['total'])->toBe($before['total']);
});

test('nothing is sent to imported customers', function () {
    importSquare(scanSquare());

    expect(Message::withoutCompanyScope()->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('Undo sends the import to Deleted items and takes back what a merge added', function () {
    $import = importSquare(scanSquare());

    $this->post(route('square-import.undo', $import))->assertRedirect(route('square-import.show'));

    inCompany($this->company, function () use ($import) {
        expect($import->fresh()->status)->toBe('undone')
            ->and(Customer::query()->where('square_import_id', $import->id)->count())->toBe(0)
            ->and(Customer::onlyTrashed()->where('square_import_id', $import->id)->count())->toBe(2)
            ->and(SquareInvoice::query()->count())->toBe(0);

        $bob = $this->bob->fresh();
        expect($bob->last_name)->toBeNull()->and($bob->notes)->toBeNull()->and($bob->square_customer_id)->toBeNull();
        expect($this->carol->fresh()->exists)->toBeTrue();
    });

    expect(AuditLog::query()->where('action', 'square_import.undone')->exists())->toBeTrue();

    // In Deleted items for 30 days; restoring Anna brings her imported invoice back.
    $anna = Customer::withoutCompanyScope()->onlyTrashed()->where('square_customer_id', 'SQ-ANNA')->sole();
    $this->get(route('trash.index'))->assertInertia(fn ($page) => $page->where('items', fn ($items) => collect($items)->contains(fn ($i) => $i['type'] === 'customer' && $i['id'] === $anna->id)));
    $this->post(route('trash.restore', ['type' => 'customer', 'id' => $anna->id]))->assertRedirect();
    expect(SquareInvoice::withoutCompanyScope()->where('customer_id', $anna->id)->count())->toBe(1);
});

test('missing read permissions are asked for when Import is opened', function () {
    $this->scopes = ['MERCHANT_PROFILE_READ', 'PAYMENTS_READ', 'PAYMENTS_WRITE', 'ORDERS_READ', 'ORDERS_WRITE'];
    ($this->fakeSquare)();

    $this->get(route('square-import.show'))->assertInertia(fn ($page) => $page->where('missingScopes', ['CUSTOMERS_READ', 'INVOICES_READ']));

    $redirect = $this->get(route('payment-providers.connect', ['provider' => 'square', 'for' => 'import']))->headers->get('X-Inertia-Location') ?? '';
    $location = $redirect ?: $this->get(route('payment-providers.connect', ['provider' => 'square', 'for' => 'import']))->headers->get('Location');
    expect(urldecode((string) $location))->toContain('CUSTOMERS_READ')->toContain('INVOICES_READ');
});

test('only the Owner can import', function () {
    foreach ([UserRole::Admin, UserRole::Technician] as $role) {
        $user = memberOf($this->company, $role);
        $this->actingAs($user)->get(route('square-import.show'))->assertForbidden();
        $this->actingAs($user)->post(route('square-import.start'))->assertForbidden();
    }

    expect(SquareImport::withoutCompanyScope()->count())->toBe(0);
});

test('another company cannot see or run this company\'s import', function () {
    $other = memberOf(Company::factory()->create());
    $import = scanSquare();

    $this->actingAs($other)->post(route('square-import.run', $import))->assertNotFound();
    $this->actingAs($other)->get(route('square-import.status', $import))->assertNotFound();
});

test('without a Square connection the import screen offers to connect Square first', function () {
    inCompany($this->company, fn () => PaymentProviderConnection::query()->delete());

    $this->get(route('square-import.show'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('company/square-import')->where('connected', false)->where('canConnect', true));
    $this->post(route('square-import.start'))->assertStatus(422);

    // Square not set up on this installation: still shown, without a connect button.
    config(['services.square.application_id' => '']);
    $this->get(route('square-import.show'))->assertInertia(fn ($page) => $page->where('connected', false)->where('canConnect', false));
});
