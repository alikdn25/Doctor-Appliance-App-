<?php

use App\Actions\Billing\SaveBillingDocument;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsThread;
use App\Models\TaxRate;
use App\Models\Trip;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's task of November 18, part 3: a receipt read from a photo (nothing saved before Save), the morning summary and
 * "Today" (code only), and the day's route as a mileage draft.
 */
beforeEach(function () {
    Storage::fake(PrivateMedia::diskName());
    $this->travelTo(CarbonImmutable::parse('2026-10-14 06:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['currency' => 'CAD', 'timezone' => 'America/Vancouver', 'country' => 'CA']);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Tom Fixer']);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Mike Stone']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    inCompany($this->company, function () {
        TaxRate::create(['name' => 'GST', 'rate' => 5, 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        TaxRate::create(['name' => 'PST', 'rate' => 7, 'is_default' => true, 'is_active' => true, 'sort_order' => 2]);
        $this->fuel = BusinessExpenseCategory::query()->forceCreate(['name' => 'Fuel', 'created_by' => $this->owner->id]);
    });
    config(['ai.key' => '']);
    $this->actingAs($this->owner);
});

function receiptAnswer(array $overrides = []): array
{
    return [...[
        'merchant' => 'Shell', 'date' => '2026-10-13', 'total' => 56.00,
        'taxes' => [['name' => 'GST', 'amount' => 2.50], ['name' => 'PST', 'amount' => 3.50]],
        'category' => 'Fuel', 'description' => 'Gas for the van',
    ], ...$overrides];
}

// ——— Receipt photo ———

test('a receipt photo fills the expense form: merchant, date, amount before tax, GST, PST, category; nothing saved', function () {
    $fake = FakeAiTransport::install(receiptAnswer());
    config(['ai.models.assistant' => 'claude-sonnet-5-5']);

    $fields = $this->post(route('expenses.read-receipt'), ['photo' => UploadedFile::fake()->image('receipt.jpg', 800, 1200)], ['Accept' => 'application/json'])
        ->assertOk()->json('fields');

    $gst = inCompany($this->company, fn () => TaxRate::query()->where('name', 'GST')->value('id'));
    $pst = inCompany($this->company, fn () => TaxRate::query()->where('name', 'PST')->value('id'));
    expect($fake->requests[0]['model'])->toBe('claude-sonnet-5-5')
        ->and($fake->requests[0]['messages'][0]['content'][0]['type'])->toBe('image')
        ->and($fields)->toMatchArray([
            'merchant' => 'Shell', 'spent_on' => '2026-10-13', 'description' => 'Gas for the van',
            'category_id' => $this->fuel->id, 'amount' => '50.00', 'use_named_taxes' => true,
            'tax_rate_ids' => [$gst, $pst], 'tax_amount' => '6.00',
        ])
        ->and($fields['tax_amounts'])->toEqual([$gst => '2.50', $pst => '3.50'])
        ->and(inCompany($this->company, fn () => BusinessExpense::query()->count()))->toBe(0);
});

test('taxes the company does not have go into the tax total; a new category is proposed; My expenses only the paid amount', function () {
    FakeAiTransport::install(receiptAnswer(['taxes' => [['name' => 'HST', 'amount' => 6.44]], 'total' => 56.00, 'category' => 'Tools']));

    $fields = $this->post(route('expenses.read-receipt'), ['photo' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->json('fields');
    expect($fields)->toMatchArray(['amount' => '49.56', 'use_named_taxes' => false, 'tax_amount' => '6.44', 'category_id' => null, 'new_category' => 'Tools']);

    $mine = $this->actingAs($this->tech)->post(route('expenses.read-receipt'), ['photo' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->json('fields');
    expect($mine)->toMatchArray(['amount' => '56.00', 'tax_amount' => '0']);
});

test('the photo is saved with the expense on Save', function () {
    $this->post(route('expenses.store'), [
        'category_id' => $this->fuel->id, 'spent_on' => '2026-10-13', 'description' => 'Gas', 'merchant' => 'Shell',
        'amount' => '50.00', 'tax_amount' => '6.00', 'receipt' => UploadedFile::fake()->image('receipt.jpg'),
    ])->assertRedirect();

    $expense = inCompany($this->company, fn () => BusinessExpense::query()->sole());
    expect($expense->receipt_path)->not->toBeNull();
    Storage::disk(PrivateMedia::diskName())->assertExists($expense->receipt_path);
});

test('reading a receipt counts in the daily AI limit', function () {
    config(['ai.daily_limit' => 1]);
    FakeAiTransport::install(receiptAnswer());

    $this->post(route('expenses.read-receipt'), ['photo' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->assertOk();
    $this->post(route('expenses.read-receipt'), ['photo' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->assertStatus(429);
});

// ——— Morning summary ———

function summaryDay(): array
{
    $test = test();
    $customer = Customer::factory()->for($test->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $home = Property::factory()->for($customer)->create(['line1' => '1 Main St', 'city' => 'Vancouver']);
    $nowhere = Property::factory()->for($customer)->create(['line1' => '', 'city' => null]);
    $at = fn (string $time) => CarbonImmutable::parse("2026-10-14 {$time}", 'America/Vancouver')->utc();
    $later = ServiceJob::factory()->for($home)->withVisit($test->tech, ['scheduled_start' => $at('13:00'), 'scheduled_end' => $at('15:00')])->create(['brand_id' => $test->brand->id]);
    $first = ServiceJob::factory()->for($home)->withVisit($test->tech, ['scheduled_start' => $at('09:00'), 'scheduled_end' => $at('11:00')])->create(['brand_id' => $test->brand->id]);
    $noAddress = ServiceJob::factory()->for($nowhere)->create(['brand_id' => $test->brand->id, 'status' => JobStatus::New]);
    $parts = ServiceJob::factory()->for($home)->create(['brand_id' => $test->brand->id, 'status' => JobStatus::WaitingForParts, 'part_name' => 'Drain pump']);
    inCompany($test->company, function () use ($customer, $later, $test) {
        app(SaveBillingDocument::class)->createInvoice($later, documentPayload(), $test->owner);
        Message::create(['customer_id' => $customer->id, 'direction' => 'inbound', 'channel' => 'sms', 'kind' => MessageKind::Reply, 'from' => '+16045550142', 'to' => '+16045550100', 'body' => 'Night text', 'status' => 'received']);
        SmsThread::create(['phone' => '+16045550142', 'awaiting_since' => now()->subHours(8)]);
    });

    return compact('first', 'later', 'noAddress', 'parts');
}

test('Today: jobs in order, no address, unanswered texts from the night, unpaid invoices, parts', function () {
    $jobs = summaryDay();

    $this->get(route('today'))->assertInertia(fn (Assert $page) => $page->component('today')
        ->where('summary.jobs.0.id', $jobs['first']->id)->where('summary.jobs.1.id', $jobs['later']->id)
        ->has('summary.no_address', 1)->where('summary.no_address.0.id', $jobs['noAddress']->id)
        ->has('summary.texts', 1)->where('summary.texts.0.name', 'Jane Cooper')
        ->where('summary.unpaid.count', 1)
        ->has('summary.parts', 1)->where('summary.parts.0.part', 'Drain pump'));

    // A technician sees their own jobs, no texts and no invoices.
    $this->actingAs($this->tech)->get(route('today'))->assertInertia(fn (Assert $page) => $page
        ->has('summary.jobs', 2)->where('summary.texts', [])->where('summary.unpaid', null));
});

test('the morning summary goes at each person\'s time, once a day, and can be turned off', function () {
    summaryDay();
    inCompany($this->company, fn () => Membership::query()->where('user_id', $this->tech->id)->update(['morning_summary_enabled' => false]));

    $this->travelTo(CarbonImmutable::parse('2026-10-14 06:59', 'America/Vancouver'));
    Artisan::call('summary:morning');
    expect($this->owner->notifications()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 07:00', 'America/Vancouver'));
    Artisan::call('summary:morning');
    Artisan::call('summary:morning');
    $alerts = $this->owner->notifications()->get();
    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->data['kind'])->toBe('morning_summary')
        ->and($alerts->first()->data['body'])->toContain('2 jobs today')->toContain('1 unanswered text')->toContain('1 unpaid invoice')->toContain('1 part to receive')
        ->and($alerts->first()->data['url'])->toEndWith('/today')
        ->and($this->tech->notifications()->count())->toBe(0);

    // The time is a setting.
    $this->put(route('notifications.morning'), ['enabled' => true, 'time' => '06:30'])->assertRedirect();
    expect(inCompany($this->company, fn () => Membership::query()->where('user_id', $this->owner->id)->value('morning_summary_time')))->toBe('06:30');
});

// ——— Mileage of the day ———

test('end of day: start → the person\'s jobs in order → start, saved as a draft that counts once confirmed', function () {
    config(['services.google_maps.server_key' => 'server-key']);
    // Property geocoding also calls Google: only the distance requests are counted, legs 12 + 8 + 15.5 km.
    $legs = [12000, 8000, 15500];
    Http::fake(['https://maps.googleapis.com/maps/api/distancematrix/*' => function () use (&$legs) {
        return Http::response(['status' => 'OK', 'rows' => [['elements' => [['status' => 'OK', 'distance' => ['value' => array_shift($legs) ?? 0]]]]]]);
    }, 'https://maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
    inCompany($this->company, fn () => Membership::query()->where('user_id', $this->tech->id)->update(['trip_start_address' => '9 Home Rd, Surrey']));
    $customer = Customer::factory()->for($this->company)->create();
    $a = Property::factory()->for($customer)->create(['line1' => '1 Main St', 'city' => 'Vancouver']);
    $b = Property::factory()->for($customer)->create(['line1' => '2 Oak Ave', 'city' => 'Burnaby']);
    $at = fn (string $time) => CarbonImmutable::parse("2026-10-14 {$time}", 'America/Vancouver')->utc();
    $jobA = ServiceJob::factory()->for($a)->withVisit($this->tech, ['scheduled_start' => $at('09:00'), 'scheduled_end' => $at('11:00'), 'status' => VisitStatus::Completed, 'started_at' => $at('09:10')])->create(['brand_id' => $this->brand->id]);
    $jobB = ServiceJob::factory()->for($b)->withVisit($this->tech, ['scheduled_start' => $at('13:00'), 'scheduled_end' => $at('15:00'), 'status' => VisitStatus::Completed, 'started_at' => $at('13:20')])->create(['brand_id' => $this->brand->id]);
    // Someone else's job that day is not counted.
    ServiceJob::factory()->for($a)->withVisit($this->owner, ['scheduled_start' => $at('10:00'), 'scheduled_end' => $at('11:00'), 'status' => VisitStatus::Completed, 'started_at' => $at('10:00')])->create(['brand_id' => $this->brand->id]);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 19:59', 'America/Vancouver'));
    Artisan::call('mileage:day-routes');
    expect(inCompany($this->company, fn () => Trip::query()->count()))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 20:20', 'America/Vancouver'));
    Artisan::call('mileage:day-routes');
    Artisan::call('mileage:day-routes');
    $trip = inCompany($this->company, fn () => Trip::query()->sole());
    expect($trip->is_draft)->toBeTrue()->and((float) $trip->distance_km)->toBe(35.5)
        ->and($trip->user_id)->toBe($this->tech->id)
        ->and($trip->purpose)->toBe('Day route: start → #'.$jobA->number.' → #'.$jobB->number.' → start');

    // Not in the totals until the Owner confirms it.
    $this->get(route('trips.index', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page
        ->where('trips.0.draft', true)->where('totals.month.distance', 0));
    $this->actingAs($this->tech)->post(route('trips.confirm', $trip))->assertForbidden();
    $this->actingAs($this->owner)->post(route('trips.confirm', $trip))->assertRedirect();
    $this->get(route('trips.index', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page
        ->where('trips.0.draft', false)->where('totals.month.distance', 35.5));
});

test('no start address or no own jobs: no draft', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 21:00', 'America/Vancouver'));
    inCompany($this->company, fn () => Membership::query()->where('user_id', $this->owner->id)->update(['trip_start_address' => '9 Home Rd']));

    Artisan::call('mileage:day-routes');

    expect(inCompany($this->company, fn () => Trip::query()->count()))->toBe(0);
});
