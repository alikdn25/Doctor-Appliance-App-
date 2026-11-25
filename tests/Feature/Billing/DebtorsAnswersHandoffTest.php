<?php

use App\Enums\ApplianceType;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Messaging\SmsThreads;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\SmsThread;
use App\Support\Billing\PaymentReminders;
use App\Support\Jobs\JobBacklog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's task of November 19, part 2: debtors import and soft payment reminders, Completed without invoice, Russian
 * → English, prices and answers, clarifying questions and the sticker photo, handing a job to a technician.
 */
beforeEach(function () {
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201)]);
    Mail::fake();
    config(['ai.key' => '']);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create([
        'name' => 'Doctor Appliance', 'sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'country' => 'CA', 'currency' => 'CAD',
        'business_hours' => Company::defaultBusinessHours(),
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Smith']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '12 Main St', 'city' => 'Surrey']);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
});

function smsSentTo(string $number): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => str_contains($r->url(), 'Messages.json') && ($r->data()['To'] ?? null) === $number)
        ->map(fn (HttpRequest $r) => $r->data()['Body'])->values()->all();
}

function inboundSms(string $body, string $from = '+16045550142'): Message
{
    $url = route('webhooks.sms.inbound', 'twilio');
    $params = ['AccountSid' => 'ACsub', 'From' => $from, 'To' => '+16045550100', 'Body' => $body, 'MessageSid' => 'SM'.uniqid()];
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }
    test()->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $data, 'sub-token', true)))->post($url, $params)->assertOk();

    return inCompany(test()->company, fn () => Message::query()->where('direction', 'inbound')->latest('id')->first());
}

function debtorCsv(string $rows): UploadedFile
{
    return UploadedFile::fake()->createWithContent('debtors.csv', "name,phone,email,address,date,work,amount,note\n".$rows);
}

function debtTriageAnswer(array $overrides = []): array
{
    return [...[
        'urgency' => 'normal', 'summary' => 'Asks about a repair', 'actions' => [], 'reply_draft' => 'Thanks, we can help.',
        'about_breakdown' => false, 'questions' => [], 'appliance_type' => '',
    ], ...$overrides];
}

// ——— Debtors import ———

test('the debtors CSV is previewed without saving anything; only the Owner can import', function () {
    $this->actingAs($this->owner)->postJson(route('invoices.debtors.preview'), ['file' => debtorCsv(
        "Jane Cooper,604-555-0142,,12 Main St,2026-09-03,Dryer repair,180,old debt\n"
        ."Bob Lee,604 555 0177,bob@example.com,5 Oak Ave Burnaby,09/10/2026,Washer pump,,\n"
        .",,,,,,,\n",
    )])->assertOk()
        ->assertJsonPath('rows.0.customer_id', $this->customer->id)->assertJsonPath('rows.0.matched_by', 'phone')
        ->assertJsonPath('rows.0.amount', 18000)
        ->assertJsonPath('rows.1.customer_id', null)->assertJsonPath('rows.1.amount', null)->assertJsonPath('rows.1.date', '2026-09-10')
        ->assertJsonPath('rows.2.problems.0', 'No name');

    expect(inCompany($this->company, fn () => [ServiceJob::query()->count(), Invoice::query()->count()]))->toBe([0, 0]);

    $this->actingAs(memberOf($this->company, UserRole::Admin))->get(route('invoices.debtors.import'))->assertForbidden();
});

test('Confirm finds or creates the customer, makes a Completed job on the date and an open invoice not sent; no amount = "amount needed"', function () {
    $this->actingAs($this->owner)->post(route('invoices.debtors.store'), ['rows' => [
        ['name' => 'Jane Cooper', 'phone' => '604-555-0142', 'email' => '', 'address' => '', 'date' => '2026-09-03', 'work' => 'Dryer repair', 'amount' => '180', 'note' => 'old'],
        ['name' => 'Bob Lee', 'phone' => '', 'email' => 'bob@example.com', 'address' => '5 Oak Ave', 'date' => '2026-09-10', 'work' => 'Washer pump', 'amount' => '', 'note' => ''],
        ['name' => 'No Contact', 'phone' => '', 'email' => '', 'address' => '', 'date' => '2026-09-10', 'work' => 'x', 'amount' => '1', 'note' => ''],
    ]])->assertRedirect(route('invoices.unpaid', ['filter' => 'not_reminded']));

    inCompany($this->company, function () {
        expect(Customer::query()->count())->toBe(2);
        $jane = Invoice::query()->where('customer_id', $this->customer->id)->with('job.visits')->sole();
        expect($jane)->status->toBe(InvoiceStatus::Unpaid)->sent_at->toBeNull()->balance->toBe(18000)
            ->and($jane->issued_on->toDateString())->toBe('2026-09-03')
            ->and($jane->job->outcome?->value)->toBe('repaired')
            ->and($jane->job->status)->toBe(JobStatus::Invoiced)
            ->and(CarbonImmutable::instance($jane->job->visits->first()->scheduled_start)->setTimezone('America/Vancouver')->toDateString())->toBe('2026-09-03');

        $bob = Customer::query()->where('first_name', 'Bob')->sole();
        $invoice = Invoice::query()->where('customer_id', $bob->id)->with('items')->sole();
        expect($invoice)->status->toBe(InvoiceStatus::Unpaid)->sent_at->toBeNull()
            ->and($invoice->items->first()->needs_amount)->toBeTrue()
            ->and($bob->primaryProperty->line1)->toBe('5 Oak Ave');
    });
    // Nothing was sent to anyone.
    expect(collect(Http::recorded())->count())->toBe(0);
});

// ——— Soft payment reminders ———

function openInvoice(string $issued = '2026-09-03'): Invoice
{
    test()->actingAs(test()->owner)->post(route('invoices.debtors.store'), ['rows' => [
        ['name' => 'Jane Cooper', 'phone' => '604-555-0142', 'email' => '', 'address' => '', 'date' => $issued, 'work' => 'Dryer repair', 'amount' => '180', 'note' => ''],
    ]]);

    return inCompany(test()->company, fn () => Invoice::query()->latest('id')->first());
}

test('a soft reminder: preview shows the text without amount or link; nothing is sent before Confirm', function () {
    $invoice = openInvoice();

    $text = $this->actingAs($this->owner)->postJson(route('invoices.reminders.preview'), ['invoice_ids' => [$invoice->id]])
        ->assertOk()->json('texts.0.text');
    expect($text)->toBe('Hi Jane, this is Alex from Doctor Appliance. Just a quick note: our records show the invoice for your dryer repair from September is still open. Could you take a look when you get a chance?')
        ->not->toContain('180')->not->toContain('http');
    expect(smsSentTo('+16045550142'))->toBe([]);

    $this->post(route('invoices.reminders.send'), ['invoice_ids' => [$invoice->id]])->assertRedirect();
    expect(smsSentTo('+16045550142'))->toBe([$text])
        ->and(PaymentReminders::status($invoice->fresh()))->toBe('reminded');
});

test('the answer to a soft reminder gets a reply draft with the amount and the link; one tap sends it; nothing is texted again by itself', function () {
    $invoice = openInvoice();
    $this->actingAs($this->owner)->post(route('invoices.reminders.send'), ['invoice_ids' => [$invoice->id]]);
    FakeAiTransport::install(debtTriageAnswer(['reply_draft' => 'Sure, no problem.']));

    $incoming = inboundSms('Oh sorry, how much was it?');
    $draft = $incoming->ai_triage['reply_draft'];
    expect($draft)->toContain('$180.00')->toContain('/i/')
        ->and(PaymentReminders::status($invoice->fresh()))->toBe('answered');
    expect(smsSentTo('+16045550142'))->toHaveCount(1);

    $alert = $this->owner->notifications()->get()->first(fn ($n) => $n->data['kind'] === 'sms_incoming');
    expect($alert->data['actions'][0]['title'])->toBe('Send reply');
    $this->postJson(str_replace('__action__', 'main', $alert->data['action_url']))->assertOk()->assertJsonPath('message', 'Reply sent.');
    expect(smsSentTo('+16045550142'))->toHaveCount(2)->and(smsSentTo('+16045550142')[1])->toBe($draft);
});

test('an unanswered soft reminder gives the "customer is quiet" reminder and is never repeated automatically', function () {
    $invoice = openInvoice();
    $this->actingAs($this->owner)->post(route('invoices.reminders.send'), ['invoice_ids' => [$invoice->id]]);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:00', 'America/Vancouver'));
    app(SmsThreads::class)->sweep();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 14:00', 'America/Vancouver'));
    app(SmsThreads::class)->sweep();

    expect($this->owner->notifications()->get()->filter(fn ($n) => $n->data['kind'] === 'sms_quiet')->count())->toBe(1)
        ->and(smsSentTo('+16045550142'))->toHaveCount(1);
});

test('Unpaid lists open invoices with the reminder filters and Completed jobs without an invoice', function () {
    $reminded = openInvoice();
    $this->actingAs($this->owner)->post(route('invoices.reminders.send'), ['invoice_ids' => [$reminded->id]]);
    openInvoice('2026-09-20');
    ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Completed]);

    $this->get(route('invoices.unpaid'))->assertInertia(fn (Assert $page) => $page->component('invoices/unpaid')
        ->has('invoices', 2)->where('noInvoiceCount', 1));
    $this->get(route('invoices.unpaid', ['filter' => 'not_reminded']))->assertInertia(fn (Assert $page) => $page->has('invoices', 1));
    $this->get(route('invoices.unpaid', ['filter' => 'reminded']))->assertInertia(fn (Assert $page) => $page
        ->has('invoices', 1)->where('invoices.0.id', $reminded->id));
});

test('Completed jobs without an invoice are counted in the unfinished-jobs bar', function () {
    ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Completed]);
    ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Completed]);
    openInvoice();

    $summary = inCompany($this->company, fn () => JobBacklog::summary($this->owner));
    expect($summary['counts']['no_invoice'])->toBe(2);
});

// ——— Russian → English ———

test('Russian in the reply box becomes a short English SMS; English is left alone; nothing is sent', function () {
    $ai = FakeAiTransport::install(['text' => 'We can come on Thursday between 10 and 12.']);

    $this->actingAs($this->owner)->postJson(route('messages.translate'), ['text' => 'Можем приехать в четверг с 10 до 12'])
        ->assertOk()->assertJson(['text' => 'We can come on Thursday between 10 and 12.', 'translated' => true]);
    $this->postJson(route('messages.translate'), ['text' => 'See you Thursday'])
        ->assertOk()->assertJson(['text' => 'See you Thursday', 'translated' => false]);
    expect($ai->requests)->toHaveCount(1)->and(smsSentTo('+16045550142'))->toBe([]);

    $this->company->update(['translate_to_english' => false]);
    $this->postJson(route('messages.translate'), ['text' => 'Привет'])->assertJson(['translated' => false]);
});

// ——— Prices and answers; clarifying questions; sticker ———

test('the AI gets the prices and answers and never more than 2 questions; a sticker photo is asked when no model is known', function () {
    $this->company->update(['diagnostic_fee' => 9500, 'service_areas' => 'Surrey, Burnaby', 'hours_text' => 'Mon-Fri 8-5']);
    $ai = FakeAiTransport::install(debtTriageAnswer([
        'about_breakdown' => true, 'appliance_type' => 'washer', 'reply_draft' => 'Sorry to hear that.',
        'questions' => ['What exactly happens?', 'Since when?', 'Any error code?'],
    ]));

    $draft = inboundSms('My washer is broken')->ai_triage['reply_draft'];

    expect($ai->requests[0]['system'])->toContain('Diagnostic fee: $95.00')->toContain('Surrey, Burnaby')->toContain('Mon-Fri 8-5');
    expect($draft)->toContain('What exactly happens?')->toContain('Since when?')->not->toContain('Any error code?')
        ->toContain('photo of the sticker with the model number')->toContain('rim of the door');
});

test('no sticker request when the customer\'s appliance already has a model number', function () {
    inCompany($this->company, fn () => Appliance::factory()->for($this->property)->create(['type' => ApplianceType::Washer, 'model_number' => 'WM3400CW']));
    FakeAiTransport::install(debtTriageAnswer(['about_breakdown' => true, 'appliance_type' => 'washer', 'questions' => ['Since when?']]));

    expect(inboundSms('My washer is broken')->ai_triage['reply_draft'])->not->toContain('sticker');
});

test('clarifying questions go automatically only when turned on, in business hours, once per 24 hours per number', function () {
    FakeAiTransport::install(debtTriageAnswer(['about_breakdown' => true, 'appliance_type' => 'dryer', 'questions' => ['Since when?']]));
    $clarifying = fn () => inCompany($this->company, fn () => Message::query()->where('kind', MessageKind::Clarifying)->count());

    inboundSms('Dryer not heating');
    expect($clarifying())->toBe(0);

    $this->company->update(['auto_clarify' => true]);
    inboundSms('Dryer not heating at all');
    expect($clarifying())->toBe(1)->and(smsSentTo('+16045550142')[0])->toContain('Since when?')->toContain('door frame');

    inboundSms('Hello?');
    expect($clarifying())->toBe(1);

    // After hours: never.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', 'America/Vancouver'));
    inboundSms('Dryer still not heating');
    expect($clarifying())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'America/Vancouver'));
    inboundSms('Dryer still broken');
    expect($clarifying())->toBe(2);
});

test('the Owner sets prices and answers, translation, clarifying questions, the reminder text and outside technicians', function () {
    $this->actingAs($this->owner)->get(route('company.answers.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('company/answers')->where('settings.translate_to_english', true)->where('settings.auto_clarify', false));

    $this->put(route('company.answers.update'), [
        'diagnostic_fee' => '95', 'service_areas' => 'Surrey', 'hours_text' => 'Mon-Fri 8-5', 'answer_notes' => 'No Miele',
        'translate_to_english' => false, 'auto_clarify' => true, 'payment_reminder_template' => 'Hi {first_name}!',
        'outside_technicians' => [['name' => 'Sam Field', 'phone' => '604 555 0188']],
    ])->assertRedirect();

    expect($this->company->fresh())->diagnostic_fee->toBe(9500)->translate_to_english->toBeFalse()->auto_clarify->toBeTrue()
        ->payment_reminder_template->toBe('Hi {first_name}!')
        ->outside_technicians->toBe([['name' => 'Sam Field', 'phone' => '+16045550188']]);

    $this->actingAs(memberOf($this->company, UserRole::Admin))->get(route('company.answers.edit'))->assertForbidden();
});

// ——— Hand to technician ———

function handoffJob(): ServiceJob
{
    return ServiceJob::factory()->for(test()->property)->withVisit(test()->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-10-16 10:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-10-16 12:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => test()->brand->id, 'status' => JobStatus::Scheduled, 'description' => 'Fridge not cooling']);
}

test('hand to an outside technician: preview of both texts, nothing sent before Confirm, then both go and the job is marked', function () {
    $this->company->update(['outside_technicians' => [['name' => 'Sam Field', 'phone' => '+16045550188']]]);
    $job = handoffJob();

    $preview = $this->actingAs($this->owner)->postJson(route('jobs.handoff.preview', $job), ['to' => 'outside:0'])->assertOk()->json();
    expect($preview['technician'])->toContain('Jane Cooper')->toContain('(604) 555-0142')->toContain('12 Main St')->toContain('Fridge not cooling')
        ->and($preview['customer'])->toContain('Your technician Sam Field will arrive');
    expect(smsSentTo('+16045550188'))->toBe([])->and(smsSentTo('+16045550142'))->toBe([]);

    $this->post(route('jobs.handoff.store', $job), ['to' => 'outside:0'])->assertRedirect();
    expect(smsSentTo('+16045550188'))->toHaveCount(1)->and(smsSentTo('+16045550142'))->toBe([$preview['customer']])
        ->and($job->fresh()->handed_to)->toBe('Sam Field');

    // The texts never start a "customer is quiet" wait.
    expect(inCompany($this->company, fn () => SmsThread::query()->whereNotNull('silent_since')->count()))->toBe(0);
});

test('hand to a technician of the app assigns the job to them', function () {
    $tech = memberOf($this->company, UserRole::Technician, ['name' => 'Mike Stone', 'phone' => '604-555-0166']);
    $job = handoffJob();

    $this->actingAs($this->owner)->post(route('jobs.handoff.store', $job), ['to' => 'user:'.$tech->id])->assertRedirect();
    expect(smsSentTo('+16045550166'))->toHaveCount(1)
        ->and(inCompany($this->company, fn () => $job->visits()->first()->assignees->pluck('id')->all()))->toBe([$tech->id]);
    $this->actingAs($tech)->post(route('jobs.handoff.store', $job), ['to' => 'user:'.$tech->id])->assertForbidden();
});

test('the photo link for an outside technician works only signed', function () {
    $job = handoffJob();
    $this->get(route('shared.job-photos', ['job' => $job->id]))->assertForbidden();
    $this->get(URL::temporarySignedRoute('shared.job-photos', now()->addDay(), ['job' => $job->id]))->assertOk();
});

test('a company cannot hand jobs or read invoices of another company', function () {
    $other = memberOf(Company::factory()->create(), UserRole::Owner);
    $job = handoffJob();
    $invoice = openInvoice();

    $this->actingAs($other)->postJson(route('jobs.handoff.preview', $job), ['to' => 'user:'.$other->id])->assertNotFound();
    $this->actingAs($other)->postJson(route('invoices.reminders.preview'), ['invoice_ids' => [$invoice->id]])->assertUnprocessable();
});
