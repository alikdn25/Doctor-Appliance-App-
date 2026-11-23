<?php

use App\Enums\JobStatus;
use App\Enums\PhotoKind;
use App\Mail\DocumentMail;
use App\Models\AiRequest;
use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceAttachment;
use App\Models\JobPhoto;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Services\AI\AiResponse;
use App\Services\AI\AnthropicTransport;
use App\Services\AI\Assistant\AssistantTools;
use App\Services\AI\Assistant\InvoiceDraft;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\FakeAiTransport;

/**
 * Invoices from photos through the assistant (tool create_invoice): the AI passes the customer and the lines; the
 * customer is matched by phone or email, the company's GST/PST and the total are calculated by code, and nothing is
 * stored or sent before Confirm & send (or Edit).
 */
beforeEach(function () {
    Queue::fake();
    Mail::fake();
    Storage::fake(PrivateMedia::diskName());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/Vancouver'));

    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'prices_include_tax' => false]);
    $this->owner = memberOf($this->company);
    inCompany($this->company, function () {
        TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        TaxRate::create(['name' => 'PST', 'rate' => 7, 'is_default' => true, 'is_active' => true, 'sort_order' => 2]);
        // Not a default tax: never added by the assistant.
        TaxRate::create(['name' => 'HST', 'rate' => 13, 'is_default' => false, 'is_active' => true, 'sort_order' => 3]);
    });

    Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create();

    config(['ai.models.assistant' => 'claude-sonnet-5-5', 'ai.daily_limit' => 100]);
    $this->actingAs($this->owner);
    $this->conversation = (string) Str::uuid();
});

/** What the AI passes to create_invoice. */
function invoiceInput(array $overrides = []): array
{
    return array_replace([
        'customer_name' => 'Jane Cooper',
        'phone' => '(604) 555-0142',
        'email' => '',
        'address' => ['line1' => '', 'unit' => '', 'city' => '', 'region' => '', 'postal_code' => ''],
        'lines' => [['description' => 'Dishwasher repair', 'amount' => 100], ['description' => 'Drain pump', 'amount' => 50]],
        'amounts_include_tax' => false,
        'notes' => '',
    ], $overrides);
}

function proposeInvoice(array $input): array
{
    return inCompany(test()->company, fn () => json_decode(app(AssistantTools::class)->run('create_invoice', $input, test()->owner, test()->conversation), true));
}

/** Counts of everything Confirm creates. */
function invoiceCounts(): array
{
    return inCompany(test()->company, fn () => [
        'customers' => Customer::query()->count(),
        'jobs' => ServiceJob::query()->count(),
        'invoices' => Invoice::query()->count(),
        'messages' => Message::query()->count(),
        'attachments' => InvoiceAttachment::query()->count(),
    ]);
}

test('the existing customer is found by phone', function () {
    $result = proposeInvoice(invoiceInput(['customer_name' => 'J. Cooper']));

    $draft = inCompany($this->company, fn () => AssistantAction::query()->sole()->params['draft']);
    expect($result['proposed'])->toBeTrue()
        ->and($draft['customer']['id'])->toBe($this->customer->id)
        ->and($draft['customer']['matched_by'])->toBe('phone')
        ->and($draft['customer']['phone'])->toBe('+16045550142')
        ->and($draft['customer']['name'])->toBe('Jane Cooper');
});

test('the existing customer is found by email when the phone is new', function () {
    inCompany($this->company, fn () => $this->customer->emails()->create(['email' => 'jane@example.com', 'is_primary' => true]));

    proposeInvoice(invoiceInput(['phone' => '778 555 0199', 'email' => 'Jane@Example.com']));

    $draft = inCompany($this->company, fn () => AssistantAction::query()->sole()->params['draft']);
    expect($draft['customer']['id'])->toBe($this->customer->id)
        ->and($draft['customer']['matched_by'])->toBe('email');
});

test('a customer of another company with the same phone is never matched', function () {
    $other = Company::factory()->create();
    Customer::factory()->for($other)->withPhone('778-555-0100')->create(['first_name' => 'Secret']);

    proposeInvoice(invoiceInput(['customer_name' => 'Bob Lee', 'phone' => '778-555-0100', 'address' => ['line1' => '1 Main St', 'unit' => '', 'city' => 'Burnaby', 'region' => 'BC', 'postal_code' => '']]));

    $draft = inCompany($this->company, fn () => AssistantAction::query()->sole()->params['draft']);
    expect($draft['customer']['id'])->toBeNull()->and($draft['customer']['name'])->toBe('Bob Lee');
});

test('GST and PST and the total are calculated by code on amounts without tax', function () {
    proposeInvoice(invoiceInput());

    $draft = inCompany($this->company, fn () => AssistantAction::query()->sole()->params['draft']);
    expect($draft['subtotal'])->toBe(15000)
        ->and(array_column($draft['taxes'], 'name'))->toBe(['GST', 'PST'])
        ->and(array_column($draft['taxes'], 'amount'))->toBe([750, 1050])
        ->and($draft['tax_total'])->toBe(1800)
        ->and($draft['total'])->toBe(16800)
        ->and(array_column($draft['lines'], 'unit_price'))->toBe([10000, 5000]);
});

test('amounts given with tax: the amount without tax is worked out from the total', function () {
    proposeInvoice(invoiceInput(['lines' => [['description' => 'Washer repair', 'amount' => 112]], 'amounts_include_tax' => true]));

    $draft = inCompany($this->company, fn () => AssistantAction::query()->sole()->params['draft']);
    expect($draft['lines'][0]['unit_price'])->toBe(10000)
        ->and($draft['subtotal'])->toBe(10000)
        ->and(array_column($draft['taxes'], 'amount'))->toBe([500, 700])
        ->and($draft['total'])->toBe(11200);

    // Awkward totals still come back exactly as given, spread over the lines.
    $prices = InvoiceDraft::prices([9999, 3333], [
        ['tax_rate_id' => 1, 'name' => 'GST', 'rate' => '5', 'compound' => false],
        ['tax_rate_id' => 2, 'name' => 'PST', 'rate' => '7', 'compound' => false],
    ], true, false);
    expect(array_sum($prices))->toBe(11904);
});

test('a company with tax in its prices keeps amounts with tax as they are and adds tax to amounts without', function () {
    inCompany($this->company, fn () => $this->company->forceFill(['prices_include_tax' => true])->save());
    $this->company->refresh();

    proposeInvoice(invoiceInput(['lines' => [['description' => 'Repair', 'amount' => 112]], 'amounts_include_tax' => true]));
    $included = inCompany($this->company, fn () => AssistantAction::query()->latest('id')->first()->params['draft']);
    expect($included['total'])->toBe(11200)->and($included['tax_total'])->toBe(1200);

    proposeInvoice(invoiceInput(['lines' => [['description' => 'Repair', 'amount' => 100]], 'amounts_include_tax' => false]));
    $added = inCompany($this->company, fn () => AssistantAction::query()->latest('id')->first()->params['draft']);
    expect($added['total'])->toBe(11200)->and($added['tax_total'])->toBe(1200);
});

test('nothing is created or sent before Confirm', function () {
    $before = invoiceCounts();

    proposeInvoice(invoiceInput(['customer_name' => 'New Person', 'phone' => '778-555-0123', 'email' => 'new@example.com', 'address' => ['line1' => '5 Oak St', 'unit' => '', 'city' => 'Surrey', 'region' => 'BC', 'postal_code' => 'V3V 1A1']]));

    expect(invoiceCounts())->toBe($before)
        ->and(inCompany($this->company, fn () => AssistantAction::query()->sole()->status))->toBe('pending');
    Queue::assertNothingPushed();
    Mail::assertNothingQueued();

    // Cancel: still nothing.
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());
    $this->postJson(route('assistant.actions.cancel', $action))->assertOk()->assertJsonPath('action.status', 'cancelled');
    $this->postJson(route('assistant.actions.confirm', $action))->assertStatus(409);
    expect(invoiceCounts())->toBe($before);
});

test('a missing phone or amount makes the assistant ask instead of inventing', function () {
    $noPhone = proposeInvoice(invoiceInput(['phone' => '']));
    $noAmount = proposeInvoice(invoiceInput(['lines' => [['description' => 'Repair', 'amount' => 0]]]));
    $noLines = proposeInvoice(invoiceInput(['lines' => []]));

    expect($noPhone['error'])->toContain('Ask the user')
        ->and($noAmount['error'])->toContain('Ask the user')
        ->and($noLines['error'])->toContain('Ask the user')
        ->and(inCompany($this->company, fn () => AssistantAction::query()->count()))->toBe(0);
});

test('Confirm & send: an existing customer\'s open job gets the invoice, emailed only (never texted)', function () {
    config(['mail.default' => 'smtp']);
    inCompany($this->company, fn () => $this->customer->emails()->create(['email' => 'jane@example.com', 'is_primary' => true]));
    $job = ServiceJob::factory()->for($this->property)->create(['status' => JobStatus::Completed]);

    proposeInvoice(invoiceInput());
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());

    $this->postJson(route('assistant.actions.confirm', $action), ['mode' => 'send'])
        ->assertOk()
        ->assertJsonPath('action.status', 'confirmed')
        ->assertJsonPath('redirect', null);

    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    expect($invoice->service_job_id)->toBe($job->id)
        ->and($invoice->customer_id)->toBe($this->customer->id)
        ->and($invoice->total)->toBe(16800)
        ->and($invoice->tax_total)->toBe(1800)
        ->and(inCompany($this->company, fn () => Customer::query()->count()))->toBe(1);

    expect(inCompany($this->company, fn () => Message::query()->where('channel', 'sms')->count()))->toBe(0);
    Mail::assertQueued(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('jane@example.com'));
});

test('Confirm & send for a new customer creates the customer and a Completed job for today', function () {
    proposeInvoice(invoiceInput(['customer_name' => 'Bob Lee', 'phone' => '778-555-0123', 'email' => 'bob@example.com', 'address' => ['line1' => '5 Oak St', 'unit' => '201', 'city' => 'Surrey', 'region' => 'BC', 'postal_code' => 'V3V 1A1']]));
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());

    $this->postJson(route('assistant.actions.confirm', $action))->assertOk()->assertJsonPath('action.status', 'confirmed');

    inCompany($this->company, function () {
        $customer = Customer::query()->where('first_name', 'Bob')->sole();
        expect($customer->last_name)->toBe('Lee')
            ->and($customer->primaryPhone->number)->toBe('+17785550123')
            ->and($customer->primaryEmail->email)->toBe('bob@example.com')
            ->and($customer->properties()->sole()->line1)->toBe('5 Oak St');

        $job = ServiceJob::query()->where('customer_id', $customer->id)->sole();
        $visit = $job->visits()->sole();
        expect($visit->status->value)->toBe('completed')
            ->and($visit->scheduled_start->setTimezone('America/Vancouver')->toDateString())->toBe('2026-11-16')
            ->and(Invoice::query()->sole()->service_job_id)->toBe($job->id)
            // Invoiced by the new invoice, after being Completed.
            ->and($job->statusChanges()->pluck('to_status')->map->value->all())->toContain('completed');
    });

    // Mail is not set up in tests (array mailer) and invoices never go by SMS: nothing is sent.
    Mail::assertNothingQueued();
    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
});

test('Edit saves the customer and the invoice without sending and opens the editor', function () {
    config(['mail.default' => 'smtp']);
    proposeInvoice(invoiceInput(['customer_name' => 'Bob Lee', 'phone' => '778-555-0123', 'email' => 'bob@example.com', 'address' => ['line1' => '5 Oak St', 'unit' => '', 'city' => 'Surrey', 'region' => 'BC', 'postal_code' => '']]));
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());

    $response = $this->postJson(route('assistant.actions.confirm', $action), ['mode' => 'edit'])->assertOk();

    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    $response->assertJsonPath('redirect', route('invoices.edit', $invoice));
    expect($invoice->sent_at)->toBeNull()
        ->and(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
    Mail::assertNothingQueued();
});

test('photos go to the AI as images and become the invoice attachments; the request counts in the daily limit', function () {
    $outsider = memberOf();
    $fake = FakeAiTransport::install(function (array $request) {
        $last = end($request['messages']);

        return ($last['content'][0]['type'] ?? null) === 'tool_result'
            ? new AiResponse([['type' => 'text', 'text' => 'Check the invoice card.']], 'end_turn')
            : new AiResponse([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'create_invoice', 'input' => invoiceInput()]], 'tool_use');
    });

    $this->post(route('assistant.ask'), [
        'question' => 'Make an invoice from this',
        'conversation' => $this->conversation,
        'photos' => [UploadedFile::fake()->image('order.jpg', 1600, 1200), UploadedFile::fake()->image('note.png', 800, 600)],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('items.0.text', 'Make an invoice from this');

    // Stored as photo blocks next to the text; the transport turns them into base64 images.
    $content = $fake->requests[0]['messages'][0]['content'];
    expect(array_column($content, 'type'))->toBe(['image', 'image', 'text'])
        ->and($fake->requests[0]['model'])->toBe('claude-sonnet-5-5');
    $api = AnthropicTransport::messages([$fake->requests[0]['messages'][0]])[0]['content'];
    expect($api[0]['type'])->toBe('image')
        ->and($api[0]['source']['type'])->toBe('base64')
        ->and($api[0]['source']['mediaType'])->toBe('image/jpeg')
        ->and(base64_decode($api[0]['source']['data']))->not->toBe('')
        ->and($api[1]['source']['mediaType'])->toBe('image/png');

    expect(AiRequest::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(2);

    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());
    expect($action->params['photos'])->toHaveCount(2);
    $this->get(route('assistant.index'))->assertOk();

    $this->postJson(route('assistant.actions.confirm', $action))->assertOk()->assertJsonPath('action.status', 'confirmed');

    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    $attachments = inCompany($this->company, fn () => InvoiceAttachment::query()->where('invoice_id', $invoice->id)->get());
    expect($attachments)->toHaveCount(2);
    Storage::disk(PrivateMedia::diskName())->assertExists($attachments[0]->path);
    // The job gets them too, in Files → Attachments (never among Before).
    $jobPhotos = inCompany($this->company, fn () => JobPhoto::query()->where('service_job_id', $invoice->service_job_id)->get());
    expect($jobPhotos)->toHaveCount(2)
        ->and($jobPhotos->pluck('kind')->unique()->all())->toBe([PhotoKind::Attachment]);
    Storage::disk(PrivateMedia::diskName())->assertExists($jobPhotos[0]->path);
    $this->get(route('invoices.attachment', [$invoice, $attachments[0]]))->assertOk();

    // The sender sees their photo; another company's owner does not.
    $message = inCompany($this->company, fn () => AssistantMessage::query()->where('role', 'user')->orderBy('id')->first());
    $this->get(route('assistant.photo', ['message' => $message->id, 'index' => 0]))->assertOk();
    $this->actingAs($outsider)->get(route('assistant.photo', ['message' => $message->id, 'index' => 0]))->assertNotFound();
});

test('a question with photos is refused over the daily limit', function () {
    FakeAiTransport::install('Hi');
    config(['ai.daily_limit' => 0]);

    $this->post(route('assistant.ask'), [
        'question' => '',
        'conversation' => $this->conversation,
        'photos' => [UploadedFile::fake()->image('order.jpg', 100, 100)],
    ], ['Accept' => 'application/json'])->assertStatus(429);
});

test('more than 5 photos are refused', function () {
    FakeAiTransport::install('Hi');

    $this->post(route('assistant.ask'), [
        'question' => 'x',
        'conversation' => $this->conversation,
        'photos' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 50, 50), range(1, 6)),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

test('a new customer without an address still gets the invoice on a job with an empty address', function () {
    proposeInvoice(invoiceInput(['customer_name' => 'No Address', 'phone' => '778-555-0177']));
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());

    $this->postJson(route('assistant.actions.confirm', $action))->assertOk()->assertJsonPath('action.status', 'confirmed');

    inCompany($this->company, function () {
        $customer = Customer::query()->where('first_name', 'No')->sole();
        $job = ServiceJob::query()->where('customer_id', $customer->id)->sole();
        expect($job->property->line1)->toBeNull()
            ->and(Invoice::query()->sole()->service_job_id)->toBe($job->id);
    });
});
