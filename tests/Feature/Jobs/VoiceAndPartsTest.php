<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Jobs\ChangeJobStatus;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\JobPart;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\TaxRate;
use App\Services\AI\VoiceCloser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's task of November 18, part 2: On my way with the drive time, "Send a review request?" after payment, close by
 * voice, quick edit of a booking by voice, the part a job waits for and "Part arrived". Nothing is created, changed or
 * sent before Confirm / Save / Send.
 */
beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201)]);
    Mail::fake();
    // Wednesday morning in Vancouver.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'prices_include_tax' => false, 'business_hours' => Company::defaultBusinessHours()]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Tom Fixer']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '123 Main St', 'city' => 'Vancouver']);
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-14 11:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-14 13:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id, 'description' => 'Dryer not heating']);
    inCompany($this->company, function () {
        SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100']);
        TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        TaxRate::create(['name' => 'PST', 'rate' => 7, 'is_default' => true, 'is_active' => true, 'sort_order' => 2]);
    });
    $this->visit = inCompany($this->company, fn () => JobVisit::query()->where('service_job_id', $this->job->id)->sole());
    config(['ai.key' => '']);
    $this->actingAs($this->owner);
});

function outbound(): int
{
    return inCompany(test()->company, fn () => Message::query()->where('direction', 'outbound')->count());
}

// ——— On my way ———

test('On my way texts the drive time from the phone\'s position at once', function () {
    config(['services.google_maps.server_key' => 'maps-key']);
    Http::fake([
        'https://maps.googleapis.com/*' => Http::response(['status' => 'OK', 'rows' => [['elements' => [['status' => 'OK', 'duration' => ['value' => 1500], 'duration_in_traffic' => ['value' => 1060]]]]]]),
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
    ]);

    $this->post(route('visits.on-my-way', $this->visit), ['lat' => 49.25, 'lng' => -123.1])->assertRedirect();

    $text = inCompany($this->company, fn () => Message::query()->where('kind', MessageKind::OnMyWayEta)->sole());
    expect($text->body)->toContain('On my way, ETA about 18 min')->and($text->status)->toBe('sent');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'distancematrix') && $request['origins'] === '49.25,-123.1');
});

test('without a position the text goes without the time', function () {
    config(['services.google_maps.server_key' => 'maps-key']);
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);

    $this->post(route('visits.on-my-way', $this->visit))->assertRedirect();

    $text = inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->sole());
    expect($text->kind)->toBe(MessageKind::OnMyWay)->and($text->body)->not->toContain('ETA');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'distancematrix'));
});

test('the ETA text is a template the company can edit', function () {
    config(['services.google_maps.server_key' => 'maps-key']);
    Http::fake([
        'https://maps.googleapis.com/*' => Http::response(['status' => 'OK', 'rows' => [['elements' => [['status' => 'OK', 'duration' => ['value' => 600]]]]]]),
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
    ]);
    $this->company->update(['message_templates' => ['on_my_way_eta' => '{tech_name} is {eta_minutes} min away']]);

    $this->post(route('visits.on-my-way', $this->visit), ['lat' => 49.25, 'lng' => -123.1])->assertRedirect();

    expect(inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->sole()->body))->toBe('Tom is 10 min away');
});

// ——— Review request after payment ———

function paidInvoice(): Invoice
{
    return inCompany(test()->company, function () {
        $invoice = app(SaveBillingDocument::class)->createInvoice(test()->job, documentPayload(['tax_rate_ids' => []]), test()->owner);
        app(RecordPayment::class)->manual($invoice, $invoice->balance, PaymentMethod::Cash, null, null, now(), test()->owner);

        return $invoice->refresh();
    });
}

test('"Send a review request?" is offered 2 hours after payment and nothing is sent by itself', function () {
    inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Vancouver', 'review_url' => 'https://g.page/r/x']));
    $invoice = paidInvoice();
    expect($invoice->status->value)->toBe('paid');
    $before = outbound();

    $this->travelTo(now()->addMinutes(119));
    Artisan::call('invoices:paid-followup');
    expect($this->owner->notifications()->count())->toBe(0);

    $this->travelTo(now()->addMinutes(2));
    Artisan::call('invoices:paid-followup');
    Artisan::call('invoices:paid-followup');
    $alerts = $this->owner->notifications()->get();
    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->data['kind'])->toBe('review_offer')
        ->and($alerts->first()->data['url'])->toContain('review=1');
    // Nothing went to the customer.
    expect(outbound())->toBe($before)
        ->and(inCompany($this->company, fn () => ReviewRequest::query()->count()))->toBe(0);

    $this->get(route('invoices.show', ['invoice' => $invoice, 'review' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('openReview', true)->has('reviewPrompt.profiles', 1));
});

// ——— Close by voice ———

function voiceAnswer(array $overrides = []): array
{
    return [...[
        'note' => 'Replaced the thermal fuse; tested, heats fine.',
        'lines' => [['description' => 'Dryer repair', 'amount' => 150, 'kind' => 'service'], ['description' => 'Thermal fuse', 'amount' => 50, 'kind' => 'part']],
        'amounts_include_tax' => false,
        'payment' => 'card',
        'status' => 'completed',
        'part_name' => '',
    ], ...$overrides];
}

test('close by voice: the preview creates nothing; Confirm saves note, invoice, payment and status', function () {
    $fake = FakeAiTransport::install(voiceAnswer());
    config(['ai.models.assistant' => 'claude-sonnet-5-5']);
    $this->post(route('visits.start', $this->visit))->assertRedirect();

    $response = $this->postJson(route('jobs.voice-close.read', $this->job), ['text' => 'Поменял термопредохранитель, 150 работа и 50 деталь, оплатили картой'])
        ->assertOk();
    // Each line has only the first company tax (GST) ticked; PST is ticked in the preview.
    $gst = inCompany($this->company, fn () => TaxRate::query()->where('name', 'GST')->value('id'));
    $pst = inCompany($this->company, fn () => TaxRate::query()->where('name', 'PST')->value('id'));
    expect($fake->requests[0]['model'])->toBe('claude-sonnet-5-5')
        ->and(array_column($response->json('draft.lines'), 'tax_rate_ids'))->toBe([[$gst], [$gst]])
        ->and(array_column($response->json('taxes'), 'name'))->toBe(['GST', 'PST'])
        ->and($response->json('money.subtotal'))->toBe(20000)
        ->and(array_column($response->json('money.taxes'), 'amount'))->toBe([1000])
        ->and($response->json('money.total'))->toBe(21000);

    $draft = $response->json('draft');
    $draft['lines'][1]['tax_rate_ids'] = [$gst, $pst];
    $this->postJson(route('jobs.voice-close.preview', $this->job), ['lines' => $draft['lines'], 'amounts_include_tax' => false])
        ->assertOk()
        ->assertJsonPath('money.taxes.0.amount', 1000)
        ->assertJsonPath('money.taxes.1.name', 'PST')
        ->assertJsonPath('money.taxes.1.amount', 350)
        ->assertJsonPath('money.total', 21350);

    // Nothing stored yet.
    $job = $this->job->fresh();
    expect($job->tech_notes)->toBeNull()->and($job->status)->toBe(JobStatus::InProgress)
        ->and(inCompany($this->company, fn () => Invoice::query()->count()))->toBe(0);

    $this->post(route('jobs.voice-close', $this->job), $draft)->assertRedirect();

    $job = $this->job->fresh();
    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    expect($job->tech_notes)->toBe('Replaced the thermal fuse; tested, heats fine.')
        ->and($invoice->total)->toBe(21350)
        ->and($invoice->items->pluck('tax_rate_ids')->all())->toBe([[$gst], [$gst, $pst]])
        ->and($invoice->status->value)->toBe('paid')
        ->and(inCompany($this->company, fn () => Payment::query()->sole()->method))->toBe(PaymentMethod::CardTerminal)
        ->and($job->status)->toBe(JobStatus::Paid)
        ->and($this->visit->fresh()->status)->toBe(VisitStatus::Completed);
});

test('close by voice with amounts that include tax: code works out the amount without tax', function () {
    $money = inCompany($this->company, fn () => VoiceCloser::totals(voiceAnswer(['lines' => [['description' => 'Repair', 'amount' => 112, 'kind' => 'service']], 'amounts_include_tax' => true]), $this->company));

    expect($money['subtotal'])->toBe(10000)
        ->and(array_column($money['taxes'], 'name'))->toBe(['GST', 'PST'])
        ->and(array_column($money['taxes'], 'amount'))->toBe([500, 700])
        ->and($money['total'])->toBe(11200);
});

test('close by voice: need to order parts, not paid', function () {
    $this->post(route('visits.start', $this->visit))->assertRedirect();

    $this->post(route('jobs.voice-close', $this->job), voiceAnswer(['lines' => [], 'payment' => 'unpaid', 'status' => 'need_to_order_parts', 'part_name' => 'Heating element']))
        ->assertRedirect();

    $job = $this->job->fresh();
    // The part named goes to the Parts page (Order list).
    $parts = inCompany($this->company, fn () => JobPart::query()->where('service_job_id', $job->id)->get());
    expect($job->status)->toBe(JobStatus::NeedToOrderParts)
        ->and($parts->pluck('name')->all())->toBe(['Heating element'])
        ->and($parts->first()->status)->toBe(JobPart::TO_ORDER)
        ->and(inCompany($this->company, fn () => Invoice::query()->count()))->toBe(0);
});

// ——— Quick edit by voice ———

test('quick edit returns only the named fields and saves nothing', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'America/Vancouver'));
    $fake = FakeAiTransport::install([
        'changed_fields' => ['date', 'arrival'],
        'date' => 'thursday', 'arrival_start' => '13:00', 'arrival_end' => '15:00', 'arrival_now' => false,
        // Values for fields that were not named are ignored.
        'technician' => '', 'first_name' => 'Robert', 'last_name' => '', 'phone' => '', 'email' => '',
        'address' => '', 'problem' => 'Something else', 'notes' => '',
    ]);
    config(['ai.models.booking' => 'claude-haiku-5-5']);

    $this->postJson(route('jobs.quick-edit', $this->job), [
        'text' => 'перенеси на четверг после часа',
        'current' => ['date' => '2026-10-14', 'start_time' => '11:00', 'end_time' => '13:00', 'first_name' => 'Jane', 'problem' => 'Dryer not heating'],
    ])->assertOk()->assertExactJson(['changes' => ['date' => '2026-10-15', 'start_time' => '13:00', 'end_time' => '15:00']]);

    expect($fake->requests[0]['model'])->toBe('claude-haiku-5-5')
        ->and($this->visit->fresh()->scheduled_start->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-14 11:00')
        ->and($this->customer->fresh()->first_name)->toBe('Jane');
});

test('quick edit: not understood leaves the form alone', function () {
    FakeAiTransport::install(['changed_fields' => [], 'date' => '', 'arrival_start' => '', 'arrival_end' => '', 'arrival_now' => false,
        'technician' => '', 'first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '', 'address' => '', 'problem' => '', 'notes' => '']);

    $this->postJson(route('jobs.quick-edit', $this->job), ['text' => 'hmm', 'current' => ['date' => '2026-10-14']])
        ->assertOk()->assertJsonPath('changes', [])->assertJsonPath('message', __('automation.quick_edit.not_understood'));
});

test('quick edit understands a technician, a phone and "now"', function () {
    $mike = memberOf($this->company, UserRole::Technician, ['name' => 'Mike Stone']);
    FakeAiTransport::install(['changed_fields' => ['technician', 'phone', 'arrival'], 'date' => '', 'arrival_start' => '', 'arrival_end' => '', 'arrival_now' => true,
        'technician' => 'Mike', 'first_name' => '', 'last_name' => '', 'phone' => '604 555 0199', 'email' => '', 'address' => '', 'problem' => '', 'notes' => '']);

    $changes = $this->postJson(route('jobs.quick-edit', $this->job), ['text' => 'Mike goes now, phone 604 555 0199', 'current' => []])->assertOk()->json('changes');

    expect($changes)->toMatchArray(['technician_id' => (string) $mike->id, 'phone' => '+16045550199', 'date' => '2026-10-14', 'start_time' => '10:15', 'now' => true]);
});

test('Save of the edit sheet stores phone, email, problem and notes', function () {
    $this->put(route('jobs.details', $this->job), [
        'phone' => '604 555 0199', 'email' => 'Jane@Example.com', 'description' => 'Dryer squeaks', 'notes' => 'Side door',
    ])->assertSessionHasNoErrors();

    $customer = $this->customer->fresh();
    expect($customer->primaryPhone()->value('number_normalized'))->toBe('+16045550199')
        ->and($customer->primaryEmail()->value('email'))->toBe('jane@example.com')
        ->and($this->job->fresh()->description)->toBe('Dryer squeaks')
        ->and($this->job->fresh()->notes)->toBe('Side door');
});

// ——— Parts ———

test('Need to order parts: the part is listed and the AI suggests its number, marked check until confirmed', function () {
    FakeAiTransport::install(['part_number' => 'wp3387747', 'description' => 'Heating element']);

    inCompany($this->company, fn () => app(ChangeJobStatus::class)->handle($this->job->fresh(), JobStatus::NeedToOrderParts, $this->owner));

    $part = inCompany($this->company, fn () => JobPart::query()->where('service_job_id', $this->job->id)->sole());
    expect($part->part_number)->toBe('WP3387747')->and($part->number_unverified)->toBeTrue();

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('job.parts.0.part_number', 'WP3387747')->where('job.parts.0.number_unverified', true));

    $this->put(route('parts.update', $part), ['confirm_number' => true])->assertRedirect();
    expect($part->fresh()->number_unverified)->toBeFalse()->and($part->fresh()->part_number)->toBe('WP3387747');
});

test('Part arrived: a text with the three nearest free windows, sent only on Send', function () {
    $this->job->forceFill(['status' => JobStatus::WaitingForParts])->save();
    $this->visit->forceFill(['status' => VisitStatus::Completed])->save();
    $before = outbound();

    $text = $this->getJson(route('jobs.part-arrived', $this->job))->assertOk()->json('text');
    $windows = $this->getJson(route('jobs.part-arrived', $this->job))->json('windows');
    expect($windows)->toHaveCount(3)
        ->and($windows[0]['date'])->toBe('2026-10-15')->and($windows[0]['start'])->toBe('09:00')
        ->and($text)->toContain('Jane')->toContain('1) ')->toContain('3) ')
        ->and(outbound())->toBe($before);

    $this->post(route('jobs.part-arrived.send', $this->job), ['text' => $text])->assertRedirect();
    expect(outbound())->toBe($before + 1);
});

test('technicians cannot text Part arrived; another company cannot use quick edit', function () {
    $this->job->forceFill(['status' => JobStatus::WaitingForParts])->save();
    $tech = memberOf($this->company, UserRole::Technician);
    $stranger = memberOf(Company::factory()->create());
    $this->actingAs($tech)->getJson(route('jobs.part-arrived', $this->job))->assertForbidden();

    $this->actingAs($stranger)->postJson(route('jobs.quick-edit', $this->job), ['text' => 'x', 'current' => []])->assertNotFound();
});

test('close by voice: "Price includes tax" starts off in the preview, whatever the AI heard', function () {
    FakeAiTransport::install(voiceAnswer(['lines' => [['description' => 'Repair', 'amount' => 112, 'kind' => 'service']], 'amounts_include_tax' => true]));
    $this->post(route('visits.start', $this->visit))->assertRedirect();

    $this->postJson(route('jobs.voice-close.read', $this->job), ['text' => 'Repair 112 including tax'])
        ->assertOk()
        ->assertJsonPath('draft.amounts_include_tax', false)
        ->assertJsonPath('money.total', 11760);
});

// ——— Close by voice: My expenses, Confirm & send, Save only (owner's task of November 24) ———

test('close by voice splits what the customer pays from what was spent; unsure lines carry "check"', function () {
    FakeAiTransport::install(voiceAnswer([
        'lines' => [
            ['description' => 'Dishwasher repair', 'amount' => 180, 'kind' => 'service', 'check' => false],
            ['description' => 'Drain pump', 'amount' => 95, 'kind' => 'part', 'check' => true],
        ],
        'expenses' => [
            ['description' => 'Pump at Reliable Parts', 'amount' => 60, 'check' => false],
            ['description' => 'Parking', 'amount' => 10, 'check' => false],
        ],
        'payment' => 'unpaid',
        'appliance_type' => 'dishwasher', 'brand' => 'bosch',
    ]));
    $this->post(route('visits.start', $this->visit))->assertRedirect();

    $response = $this->postJson(route('jobs.voice-close.read', $this->job), ['text' => 'Ремонт посудомойки Bosch 180, насос клиенту 95, сам купил насос за 60 и парковка 10, не оплатили'])
        ->assertOk();

    expect(array_column($response->json('draft.lines'), 'description'))->toBe(['Dishwasher repair', 'Drain pump'])
        ->and(array_column($response->json('draft.lines'), 'check'))->toBe([false, true])
        ->and(array_column($response->json('draft.expenses'), 'description'))->toBe(['Pump at Reliable Parts', 'Parking'])
        ->and(array_column($response->json('draft.expenses'), 'amount'))->toBe([60, 10])
        // Totals come from code: 275 + GST 5 %.
        ->and($response->json('money.subtotal'))->toBe(27500)
        ->and($response->json('money.total'))->toBe(28875)
        ->and($response->json('draft.appliance_type'))->toBe('dishwasher')
        ->and($response->json('draft.brand'))->toBe('Bosch');
    // Reading saves nothing.
    expect(inCompany($this->company, fn () => BusinessExpense::query()->count()))->toBe(0)
        ->and(inCompany($this->company, fn () => Invoice::query()->count()))->toBe(0);
});

test('Confirm & send on an unpaid job saves everything and texts the invoice with its payment link', function () {
    $this->post(route('visits.start', $this->visit))->assertRedirect();
    $before = outbound();

    $this->post(route('jobs.voice-close', $this->job), voiceAnswer([
        'payment' => 'unpaid',
        'expenses' => [['description' => 'Pump', 'amount' => 60], ['description' => '', 'amount' => 10]],
        'send' => true,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    $expenses = inCompany($this->company, fn () => BusinessExpense::query()->orderBy('id')->get());
    $sms = inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->latest('id')->first());

    expect($invoice->balance)->toBe(22400)
        // My expenses never reach the invoice.
        ->and($invoice->items->pluck('description')->all())->toBe(['Dryer repair', 'Thermal fuse'])
        ->and($expenses->pluck('description')->all())->toBe(['Pump', 'Expenses for job #'.$this->job->number])
        ->and($expenses->pluck('amount')->all())->toBe([6000, 1000])
        ->and($expenses->pluck('service_job_id')->unique()->all())->toBe([$this->job->id])
        ->and(outbound())->toBe($before + 1)
        ->and($sms->kind)->toBe(MessageKind::InvoiceLink)
        ->and($sms->body)->toContain('/i/')
        ->and($sms->body)->not->toContain('Pump')
        ->and($invoice->sent_at)->not->toBeNull();
});

test('Confirm & send on a paid job texts the receipt', function () {
    $this->post(route('visits.start', $this->visit))->assertRedirect();

    $this->post(route('jobs.voice-close', $this->job), voiceAnswer(['payment' => 'e_transfer', 'send' => true]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    $sms = inCompany($this->company, fn () => Message::query()->where('direction', 'outbound')->latest('id')->first());
    expect($invoice->status->value)->toBe('paid')
        ->and(inCompany($this->company, fn () => Payment::query()->sole()->method))->toBe(PaymentMethod::BankTransfer)
        ->and($sms->kind)->toBe(MessageKind::Receipt)
        ->and($sms->body)->toContain('#'.$invoice->number)
        ->and($sms->body)->toContain('224.00');
});

test('Save only saves the invoice, expenses and payment and texts nothing', function () {
    $this->post(route('visits.start', $this->visit))->assertRedirect();
    $before = outbound();

    $this->post(route('jobs.voice-close', $this->job), voiceAnswer([
        'payment' => 'cash', 'expenses' => [['description' => 'Parking', 'amount' => 10]], 'send' => false,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(outbound())->toBe($before)
        ->and(inCompany($this->company, fn () => Invoice::query()->sole()->status->value))->toBe('paid')
        ->and(inCompany($this->company, fn () => BusinessExpense::query()->sole()->amount))->toBe(1000);
    Mail::assertNothingQueued();
});
