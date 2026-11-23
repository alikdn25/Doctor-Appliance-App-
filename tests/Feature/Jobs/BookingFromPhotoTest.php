<?php

use App\Enums\PhotoKind;
use App\Models\AssistantAction;
use App\Models\Brand;
use App\Models\Company;
use App\Models\JobPhoto;
use App\Models\ServiceJob;
use App\Services\AI\AiResponse;
use App\Services\AI\AnthropicTransport;
use App\Services\AI\Assistant\AssistantChat;
use App\Services\AI\Assistant\AssistantTools;
use App\Support\Jobs\BookingPhotos;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\FakeAiTransport;

/**
 * Booking from photo (owner's task of November 23): Quick booking takes up to 5 photos (a screenshot of the customer's
 * message, a rating plate) with or instead of the text; the AI (AI_MODEL_BOOKING) reads them into the form, leaves
 * unreadable values empty, and the photos become the job's photos when the booking is saved. The assistant proposes a
 * booking from photos the same way.
 */
beforeEach(function () {
    Queue::fake();
    Storage::fake(PrivateMedia::diskName());
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    Brand::factory()->for($this->company)->create();
    $this->actingAs($this->owner);
    config(['ai.models.booking' => 'claude-haiku-5-5', 'ai.models.assistant' => 'claude-sonnet-5-5', 'ai.daily_limit' => 50]);
});

/** A full answer of the AI read from a screenshot, with the given fields changed. */
function photoAnswer(array $fields = []): array
{
    return array_merge([
        'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '604 555 0177', 'email' => 'maria@example.com',
        'address' => ['line1' => '42 Oak Ave', 'city' => 'Burnaby', 'region' => 'BC', 'postal_code' => ''],
        'unit' => '', 'buzzer' => '', 'appliance_type' => 'dishwasher', 'brand' => 'Bosch', 'model' => 'SHX878WD5N',
        'serial_number' => 'fd 9801 00123', 'problem' => 'Dishwasher leaking', 'date' => '2026-11-24', 'arrival_now' => false,
        'arrival_start' => '09:00', 'arrival_end' => '11:00', 'notes' => '', 'uncertain_fields' => [],
    ], $fields);
}

function screenshot(string $name = 'sms.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 1080, 1600);
}

test('a booking is read from a photo without any text', function () {
    $fake = FakeAiTransport::install(photoAnswer());

    $response = $this->post(route('jobs.quick-booking.parse'), ['photos' => [screenshot()]], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('fields.first_name', 'Maria')
        ->assertJsonPath('fields.last_name', 'Lopez')
        ->assertJsonPath('fields.phone', '+16045550177')
        ->assertJsonPath('fields.email', 'maria@example.com')
        ->assertJsonPath('fields.address.line1', '42 Oak Ave')
        ->assertJsonPath('fields.appliance_type', 'dishwasher')
        ->assertJsonPath('fields.brand', 'Bosch')
        ->assertJsonPath('fields.model', 'SHX878WD5N')
        ->assertJsonPath('fields.serial_number', 'FD980100123')
        ->assertJsonPath('fields.problem', 'Dishwasher leaking')
        ->assertJsonPath('fields.date', '2026-11-24')
        ->assertJsonPath('fields.arrival_start', '09:00')
        ->assertJsonCount(1, 'photos');

    // One request on the booking model: the photo as an image block, then a note that there is no text.
    $request = $fake->requests[0];
    $content = $request['messages'][0]['content'];
    expect($request['model'])->toBe('claude-haiku-5-5')
        ->and(array_column($content, 'type'))->toBe(['image', 'text'])
        ->and($content[1]['text'])->toContain('[Photos attached: 1]')->toContain('No text')
        ->and($request['system'])->toContain('Photos and screenshots')->toContain('never completed or guessed')
        ->and($request['schema']['properties'])->toHaveKey('serial_number');
    $api = AnthropicTransport::messages([$request['messages'][0]])[0]['content'];
    expect($api[0]['source']['type'])->toBe('base64');

    // The preview is served to the sender only; nothing is saved yet.
    $this->get($response->json('photos.0.url'))->assertOk();
    $this->actingAs(memberOf($this->company))->get($response->json('photos.0.url'))->assertNotFound();
    expect(ServiceJob::withoutCompanyScope()->count())->toBe(0);
});

test('a booking is read from photos together with the text', function () {
    $fake = FakeAiTransport::install(photoAnswer(['date' => '', 'arrival_start' => '', 'arrival_end' => '', 'notes' => 'Call before coming']));

    $this->post(route('jobs.quick-booking.parse'), [
        'text' => 'Запиши её на завтра, позвонить перед приездом',
        'photos' => [screenshot('sms.jpg'), screenshot('plate.png')],
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('fields.first_name', 'Maria')
        ->assertJsonPath('fields.notes', 'Call before coming')
        ->assertJsonCount(2, 'photos');

    $content = $fake->requests[0]['messages'][0]['content'];
    expect(array_column($content, 'type'))->toBe(['image', 'image', 'text'])
        ->and($content[2]['text'])->toContain('[Photos attached: 2]')->toContain('Запиши её на завтра');
});

test('text only still works and neither text nor photos is refused', function () {
    $fake = FakeAiTransport::install(photoAnswer());

    $this->postJson(route('jobs.quick-booking.parse'), ['text' => 'Maria 604 555 0177'])
        ->assertOk()->assertJsonPath('photos', []);
    expect($fake->requests[0]['messages'][0]['content'])->toBe('Maria 604 555 0177');

    $this->postJson(route('jobs.quick-booking.parse'), [])->assertJsonValidationErrors('text');
    $this->post(route('jobs.quick-booking.parse'), [
        'photos' => array_map(fn ($i) => screenshot("p{$i}.jpg"), range(1, 6)),
    ], ['Accept' => 'application/json'])->assertJsonValidationErrors('photos');
});

test('an unreadable phone stays empty, nothing is invented and there are no required fields', function () {
    FakeAiTransport::install(photoAnswer([
        'first_name' => 'Maria', 'last_name' => '', 'phone' => '604 55', 'email' => 'not readable',
        'address' => ['line1' => '', 'city' => '', 'region' => '', 'postal_code' => ''], 'appliance_type' => 'unknown',
        'brand' => '', 'model' => '', 'serial_number' => '', 'problem' => '', 'date' => '', 'arrival_start' => '',
        'arrival_end' => '', 'uncertain_fields' => ['phone'],
    ]));

    $this->post(route('jobs.quick-booking.parse'), ['photos' => [screenshot()]], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('fields.first_name', 'Maria')
        ->assertJsonPath('fields.phone', null)
        ->assertJsonPath('fields.email', null)
        ->assertJsonPath('fields.address', null)
        ->assertJsonPath('fields.appliance_type', null)
        ->assertJsonPath('fields.serial_number', null)
        ->assertJsonPath('fields.date', null)
        ->assertJsonPath('fields.uncertain_fields', ['phone']);

    // The booking saves as it is: no phone, no address.
    $this->post(route('jobs.store'), ['quick_booking' => true, 'new_customer_mode' => true, 'new_customer' => ['first_name' => 'Maria']])
        ->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => ServiceJob::with('customer.phones')->sole())->customer->phones)->toHaveCount(0);
});

test('after saving, the photos are attached to the new job', function () {
    FakeAiTransport::install(photoAnswer());
    $photos = $this->post(route('jobs.quick-booking.parse'), ['photos' => [screenshot('a.jpg'), screenshot('b.jpg')]], ['Accept' => 'application/json'])
        ->assertOk()->json('photos');

    $this->post(route('jobs.store'), [
        'quick_booking' => true, 'new_customer_mode' => true,
        'new_customer' => ['first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '604 555 0177'],
        'new_appliances' => [['type' => 'dishwasher', 'manufacturer' => 'Bosch', 'model_number' => 'SHX878WD5N', 'serial_number' => 'FD980100123']],
        'booking_photos' => array_column($photos, 'id'),
    ])->assertSessionHasNoErrors();

    $job = inCompany($this->company, fn () => ServiceJob::with('appliances')->sole());
    $saved = inCompany($this->company, fn () => JobPhoto::query()->where('service_job_id', $job->id)->get());
    expect($saved)->toHaveCount(2)
        ->and($saved[0]->kind)->toBe(PhotoKind::Before)
        ->and($saved[0]->user_id)->toBe($this->owner->id)
        ->and($saved[0]->path)->toStartWith("companies/{$this->company->id}/jobs/{$job->id}/photos/")
        ->and($job->appliances->first()->serial_number)->toBe('FD980100123');
    Storage::disk(PrivateMedia::diskName())->assertExists($saved[0]->path);
    // Moved, not copied: the waiting file is gone and the preview link with it.
    Storage::disk(PrivateMedia::diskName())->assertMissing("booking-photos/{$this->company->id}/{$this->owner->id}/{$photos[0]['id']}");
    $this->get($photos[0]['url'])->assertNotFound();
    $this->get(route('jobs.photos.show', [$job, $saved[0]]))->assertOk();
});

test('photos removed in the form are not attached', function () {
    FakeAiTransport::install(photoAnswer());
    $photos = $this->post(route('jobs.quick-booking.parse'), ['photos' => [screenshot('a.jpg'), screenshot('b.jpg')]], ['Accept' => 'application/json'])
        ->json('photos');

    $this->post(route('jobs.store'), ['quick_booking' => true, 'booking_photos' => [$photos[1]['id']]])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => JobPhoto::query()->count()))->toBe(1);
});

test('another person\'s or another company\'s booking photos are never attached', function () {
    $other = Company::factory()->create(['country' => 'CA']);
    $outsider = memberOf($other);
    Brand::factory()->for($other)->create();
    $colleague = memberOf($this->company);
    FakeAiTransport::install(photoAnswer());
    $photos = $this->post(route('jobs.quick-booking.parse'), ['photos' => [screenshot()]], ['Accept' => 'application/json'])->json('photos');
    $id = $photos[0]['id'];

    $this->actingAs($outsider)->post(route('jobs.store'), ['quick_booking' => true, 'booking_photos' => [$id, '../../x.jpg']])->assertSessionHasNoErrors();
    $this->actingAs($colleague)->post(route('jobs.store'), ['quick_booking' => true, 'booking_photos' => [$id]])->assertSessionHasNoErrors();

    expect(JobPhoto::withoutCompanyScope()->count())->toBe(0)
        ->and(BookingPhotos::find($id, $this->company->id, $this->owner->id))->not->toBeNull()
        ->and(BookingPhotos::valid('../../x.jpg'))->toBeFalse();
});

test('the assistant proposes a booking from a screenshot and its photos are attached after saving', function () {
    $conversation = (string) Str::uuid();
    $fake = FakeAiTransport::install(function (array $request) {
        if ($request['model'] === 'claude-haiku-5-5') {
            return photoAnswer();
        }
        $last = end($request['messages']);

        return ($last['content'][0]['type'] ?? null) === 'tool_result'
            ? new AiResponse([['type' => 'text', 'text' => 'Confirm the booking.']], 'end_turn')
            : new AiResponse([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'propose_booking', 'input' => [
                'text' => 'Maria Lopez 604 555 0177, 42 Oak Ave Burnaby, Bosch dishwasher leaking', 'from_photos' => true,
            ]]], 'tool_use');
    });

    $this->post(route('assistant.ask'), [
        'question' => 'Запиши клиента', 'conversation' => $conversation, 'photos' => [screenshot()],
    ], ['Accept' => 'application/json'])->assertOk();

    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());
    expect($action->type)->toBe(AssistantAction::BOOKING)
        ->and($action->params['photos'])->toHaveCount(1)
        ->and(ServiceJob::withoutCompanyScope()->count())->toBe(0);

    $booking = $this->postJson(route('assistant.actions.confirm', $action))
        ->assertOk()
        ->assertJsonPath('booking.fields.first_name', 'Maria')
        ->assertJsonPath('booking.fields.serial_number', 'FD980100123')
        ->assertJsonCount(1, 'booking.photos')
        ->json('booking');

    // The booking parser read the photo again.
    $parse = collect($fake->requests)->firstWhere('model', 'claude-haiku-5-5');
    expect(array_column($parse['messages'][0]['content'], 'type'))->toBe(['image', 'text']);

    $this->post(route('jobs.store'), ['quick_booking' => true, 'booking_photos' => array_column($booking['photos'], 'id')])
        ->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => JobPhoto::query()->count()))->toBe(1);
});

test('the assistant is told to book from photos only when asked, and the tool carries from_photos', function () {
    $tool = collect(AssistantTools::definitions())->firstWhere('name', 'propose_booking');
    expect($tool['inputSchema']['properties'])->toHaveKey('from_photos');

    $system = inCompany($this->company, fn () => (new ReflectionMethod(AssistantChat::class, 'system'))->invoke(app(AssistantChat::class)));
    expect($system)->toContain('Bookings from photos or screenshots')
        ->toContain('call propose_booking')
        ->toContain('never propose a booking');
});

test('a booking proposed without from_photos takes no photos', function () {
    $conversation = (string) Str::uuid();
    FakeAiTransport::install(fn (array $request) => new AiResponse([['type' => 'text', 'text' => 'Here is the invoice data.']], 'end_turn'));
    $this->post(route('assistant.ask'), ['question' => 'Look', 'conversation' => $conversation, 'photos' => [screenshot()]], ['Accept' => 'application/json'])
        ->assertOk();

    $result = inCompany($this->company, fn () => json_decode(app(AssistantTools::class)->run('propose_booking', ['text' => 'Jane 604 555 0142', 'from_photos' => false], $this->owner, $conversation), true));

    expect($result['proposed'])->toBeTrue()
        ->and(inCompany($this->company, fn () => AssistantAction::query()->sole())->params['photos'])->toBe([]);
});
