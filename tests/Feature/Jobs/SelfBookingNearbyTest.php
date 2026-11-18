<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Http\Controllers\SelfBookingController;
use App\Models\BookingLink;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobPhoto;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Support\Jobs\NearbyWindows;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/**
 * Owner's task of November 19, part 3: self-booking by link and windows by area.
 */
beforeEach(function () {
    Storage::fake(PrivateMedia::diskName());
    config(['services.twilio' => [
        'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
        'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
    ]]);
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201)]);
    Mail::fake();
    config(['ai.key' => '', 'services.google_maps.server_key' => null]);

    // Wednesday 07:00 in Vancouver; windows 8–10, 10–12, 12–14, 14–16; Mon–Fri open.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 07:00', 'America/Vancouver'));
    $this->company = Company::factory()->create([
        'name' => 'Doctor Appliance', 'sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'country' => 'CA',
        'business_hours' => Company::defaultBusinessHours(),
        'arrival_windows' => [['start' => '08:00', 'end' => '10:00'], ['start' => '10:00', 'end' => '12:00'], ['start' => '12:00', 'end' => '14:00'], ['start' => '14:00', 'end' => '16:00']],
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Smith']);
    $this->company->update(['works_alone' => true]);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    // Jane lives in Surrey.
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '12 Main St', 'city' => 'Surrey', 'latitude' => 49.1913, 'longitude' => -122.8490]);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
});

function bookedAt(string $start, string $end, float $lat, float $lng, string $city): ServiceJob
{
    $customer = Customer::factory()->for(test()->company)->create();
    $property = Property::factory()->for($customer)->create(['line1' => '1 Other St', 'city' => $city, 'latitude' => $lat, 'longitude' => $lng]);

    return ServiceJob::factory()->for($property)->withVisit(test()->owner, [
        'scheduled_start' => CarbonImmutable::parse($start, 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse($end, 'America/Vancouver')->utc(),
    ])->create(['brand_id' => test()->brand->id, 'status' => JobStatus::Scheduled]);
}

function bookingLink(): BookingLink
{
    $url = inCompany(test()->company, fn () => SelfBookingController::link(test()->customer, '+16045550142'));

    return inCompany(test()->company, fn () => BookingLink::query()->where('token', basename($url))->sole());
}

// ——— Windows by area ———

test('days with a job nearby come first, labelled with the city and the distance', function () {
    // Friday: a job 2 km away in Surrey; Thursday: a job in Vancouver, 25 km away.
    bookedAt('2026-10-16 08:00', '2026-10-16 10:00', 49.2050, -122.8400, 'Surrey');
    bookedAt('2026-10-15 08:00', '2026-10-15 10:00', 49.2827, -123.1207, 'Vancouver');

    $windows = inCompany($this->company, fn () => NearbyWindows::rank(NearbyWindows::freeWindows($this->company),
        NearbyWindows::days($this->company, NearbyWindows::point($this->property))));

    expect($windows[0]['date'])->toBe('2026-10-16')->and($windows[0]['nearby'])->toBe('Nearby: job in Surrey, 1.7 km')
        ->and(collect($windows)->where('date', '2026-10-15')->pluck('nearby')->unique()->all())->toBe([null]);
    // The busy window is not offered.
    expect(collect($windows)->contains(fn ($w) => $w['date'] === '2026-10-16' && $w['start'] === '08:00'))->toBeFalse();
});

test('without a known address the windows keep the usual order', function () {
    bookedAt('2026-10-16 08:00', '2026-10-16 10:00', 49.2050, -122.8400, 'Surrey');
    $usual = inCompany($this->company, fn () => NearbyWindows::freeWindows($this->company));
    $ranked = inCompany($this->company, fn () => NearbyWindows::rank($usual, NearbyWindows::days($this->company, NearbyWindows::point(null, ''))));

    expect(array_map(fn ($w) => $w['date'].' '.$w['start'], $ranked))->toBe(array_map(fn ($w) => $w['date'].' '.$w['start'], $usual))
        ->and($usual[0]['date'])->toBe('2026-10-14');
});

test('the booking form gets the nearby days for the chosen address', function () {
    bookedAt('2026-10-16 08:00', '2026-10-16 10:00', 49.2050, -122.8400, 'Surrey');

    $this->actingAs($this->owner)->getJson(route('booking.nearby', ['property_id' => $this->property->id]))
        ->assertOk()->assertJsonPath('days.2026-10-16.label', 'Nearby: job in Surrey, 1.7 km');
    $this->getJson(route('booking.nearby'))->assertOk()->assertJsonPath('days', []);
});

test('the AI gets the nearby days for its proposals from a text', function () {
    bookedAt('2026-10-16 08:00', '2026-10-16 10:00', 49.2050, -122.8400, 'Surrey');
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $ai = FakeAiTransport::install(['urgency' => 'normal', 'summary' => '', 'actions' => [], 'reply_draft' => 'Sure.', 'about_breakdown' => false, 'questions' => [], 'appliance_type' => '', 'wants_time' => false]);

    smsFromJane('Can you come next week?');

    expect($ai->requests[0]['messages'][0]['content'])->toContain('Fri 2026-10-16 (Nearby: job in Surrey, 1.7 km)');
});

// ——— Self-booking ———

function smsFromJane(string $body): Message
{
    $url = route('webhooks.sms.inbound', 'twilio');
    $params = ['AccountSid' => 'ACsub', 'From' => '+16045550142', 'To' => '+16045550100', 'Body' => $body, 'MessageSid' => 'SM'.uniqid()];
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) {
        $data .= $k.$v;
    }
    test()->withHeader('X-Twilio-Signature', base64_encode(hash_hmac('sha1', $data, 'sub-token', true)))->post($url, $params)->assertOk();

    return inCompany(test()->company, fn () => Message::query()->where('direction', 'inbound')->latest('id')->first());
}

test('the AI puts a self-booking link into the draft when the customer asks about a time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    FakeAiTransport::install(['urgency' => 'normal', 'summary' => '', 'actions' => [], 'reply_draft' => 'Sure, we can come.', 'about_breakdown' => false, 'questions' => [], 'appliance_type' => '', 'wants_time' => true]);

    $draft = smsFromJane('When can you come?')->ai_triage['reply_draft'];

    expect($draft)->toContain('You can pick a time that suits you here: ')->toContain('/book/');
    $link = inCompany($this->company, fn () => BookingLink::query()->sole());
    expect($link->customer_id)->toBe($this->customer->id)->and($link->expires_at->diffInDays(now()->addDays(7)) < 1)->toBeTrue();
    // Nothing was texted.
    expect(collect(Http::recorded())->count())->toBe(0);
});

test('the self-booking page shows free windows and the known name and phone, without sign-in', function () {
    bookedAt('2026-10-14 08:00', '2026-10-14 10:00', 49.2050, -122.8400, 'Surrey');
    $link = bookingLink();

    $this->get(route('self-booking.show', $link->token))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/self-booking')->where('state', 'open')
        ->where('prefill.name', 'Jane Cooper')->where('prefill.phone', '(604) 555-0142')->where('prefill.address', fn ($a) => str_contains($a, '12 Main St'))
        ->where('windows.0.date', '2026-10-14')->where('windows.0.start', '10:00')->where('windows.0.nearby', 'Nearby: job in Surrey, 1.7 km')
        ->where('windows', fn ($windows) => ! collect($windows)->contains(fn ($w) => $w['date'] === '2026-10-14' && $w['start'] === '08:00')));
});

test('sending books the job marked Self-booked, saves the sticker photo and pushes the Owner; the link works once', function () {
    $link = bookingLink();

    $this->post(route('self-booking.store', $link->token), [
        'date' => '2026-10-15', 'start' => '10:00', 'end' => '12:00', 'name' => 'Jane Cooper', 'phone' => '604-555-0142',
        'address' => '12 Main St', 'problem' => 'Washer does not drain', 'photo' => UploadedFile::fake()->image('sticker.jpg'),
    ])->assertRedirect(route('self-booking.show', $link->token));

    $job = inCompany($this->company, fn () => ServiceJob::query()->with('visits')->sole());
    expect($job->self_booked)->toBeTrue()->and($job->customer_id)->toBe($this->customer->id)->and($job->description)->toBe('Washer does not drain')
        ->and(CarbonImmutable::instance($job->visits->first()->scheduled_start)->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-15 10:00')
        ->and(inCompany($this->company, fn () => JobPhoto::query()->where('service_job_id', $job->id)->count()))->toBe(1)
        ->and($this->owner->notifications()->get()->filter(fn ($n) => $n->data['kind'] === 'self_booking')->count())->toBe(1);

    // Once only.
    $this->post(route('self-booking.store', $link->token), [
        'date' => '2026-10-15', 'start' => '12:00', 'end' => '14:00', 'name' => 'Jane', 'phone' => '604-555-0142', 'address' => '12 Main St', 'problem' => 'x',
    ])->assertSessionHasErrors('date');
    $this->get(route('self-booking.show', $link->token))->assertInertia(fn (Assert $page) => $page->where('state', 'used'));
    expect(inCompany($this->company, fn () => ServiceJob::query()->count()))->toBe(1);
});

test('a link expires after 7 days', function () {
    $link = bookingLink();
    $this->travel(7)->days();
    $this->travel(1)->minutes();

    $this->get(route('self-booking.show', $link->token))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
    $this->post(route('self-booking.store', $link->token), [
        'date' => '2026-10-22', 'start' => '10:00', 'end' => '12:00', 'name' => 'Jane', 'phone' => '604-555-0142', 'address' => '12 Main St', 'problem' => 'x',
    ])->assertSessionHasErrors('date');
});

test('a busy window cannot be booked; a new customer is created from the page', function () {
    bookedAt('2026-10-15 10:00', '2026-10-15 12:00', 49.2050, -122.8400, 'Surrey');
    $link = inCompany($this->company, fn () => BookingLink::query()->create(['token' => str_repeat('b', 40), 'expires_at' => now()->addDays(7)]));

    $this->post(route('self-booking.store', $link->token), [
        'date' => '2026-10-15', 'start' => '10:00', 'end' => '12:00', 'name' => 'Bob Lee', 'phone' => '604-555-0177', 'address' => '5 Oak Ave', 'problem' => 'Dryer',
    ])->assertSessionHasErrors('date');

    $this->post(route('self-booking.store', $link->token), [
        'date' => '2026-10-15', 'start' => '12:00', 'end' => '14:00', 'name' => 'Bob Lee', 'phone' => '604-555-0177', 'address' => '5 Oak Ave', 'problem' => 'Dryer',
    ])->assertRedirect();
    $bob = inCompany($this->company, fn () => Customer::query()->where('first_name', 'Bob')->with('primaryProperty')->sole());
    expect($bob->primaryProperty->line1)->toBe('5 Oak Ave');
});

test('a self-booked job is marked in the calendar', function () {
    $link = bookingLink();
    $this->post(route('self-booking.store', $link->token), [
        'date' => '2026-10-15', 'start' => '10:00', 'end' => '12:00', 'name' => 'Jane Cooper', 'phone' => '604-555-0142', 'address' => '12 Main St', 'problem' => 'Washer',
    ]);

    $this->actingAs($this->owner)->get(route('calendar', ['view' => 'day', 'date' => '2026-10-15']))
        ->assertInertia(fn (Assert $page) => $page->where('visits.0.job.self_booked', true));
});

test('a link of one company never shows another company\'s data', function () {
    $other = Company::factory()->create();
    $link = inCompany($other, fn () => BookingLink::query()->create(['token' => str_repeat('c', 40), 'expires_at' => now()->addDays(7)]));

    $this->get(route('self-booking.show', $link->token))->assertInertia(fn (Assert $page) => $page
        ->where('company', $other->name)->where('prefill.name', ''));
    $this->get('/book/'.str_repeat('z', 40))->assertNotFound();
});
