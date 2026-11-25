<?php

use App\Enums\EstimateStatus;
use App\Enums\UserRole;
use App\Mail\DocumentMail;
use App\Mail\JobReportMail;
use App\Models\Brand;
use App\Models\BrandAddress;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobPhoto;
use App\Models\JobReport;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Support\Billing\DocumentPdf;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\PublicDocument;
use App\Support\PrivateMedia;
use App\Support\Reports\JobReportPdf;
use App\Support\Reports\ServiceReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeAiTransport;

/*
 * Owner's task of November 25: a report with photos in an invoice or estimate (PDF attached to the email, shown on the
 * customer's page), and separate job reports.
 */

/** A noisy camera-like JPEG (noise keeps it from compressing to nothing). */
function reportPhotoFile(string $name = 'photo.jpg', int $width = 3000, int $height = 2000): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    for ($i = 0; $i < 4000; $i++) {
        $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefilledrectangle($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $color);
    }
    ob_start();
    imagejpeg($image, null, 92);
    $bytes = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

beforeEach(function () {
    Storage::fake(PrivateMedia::diskName());
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver', 'currency' => 'CAD', 'sms_mode' => 'automatic']);
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance', 'tax_number' => '123456789RT0001']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->withEmail('payer@example.com')->create(['first_name' => 'Paula']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '500 Rental Ave', 'city' => 'Surrey']);
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->owner)->create(['brand_id' => $this->brand->id, 'description' => 'Dishwasher not draining']);
    $this->actingAs($this->owner);

    $this->upload = function (int $count) {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = reportPhotoFile("p{$i}.jpg");
        }

        return $this->post(route('jobs.report-photos.store', $this->job), ['photos' => $files])->assertOk()->json('photos');
    };
});

test('photos from a report are shrunk to 1600 px JPEG and saved among the job\'s photos', function () {
    $photos = ($this->upload)(2);

    expect($photos)->toHaveCount(2);
    $saved = inCompany($this->company, fn () => JobPhoto::query()->where('service_job_id', $this->job->id)->get());
    expect($saved)->toHaveCount(2);
    $size = getimagesizefromstring(Storage::disk(PrivateMedia::diskName())->get($saved->first()->path));
    expect(max($size[0], $size[1]))->toBe(1600)->and($size['mime'])->toBe('image/jpeg');
});

test('an invoice with a report and 10 photos: PDF under 8 MB with the text and every photo, emailed attached, shown online', function () {
    $photos = ($this->upload)(10);

    $this->post(route('invoices.store', $this->job), documentPayload([
        'report' => [
            'enabled' => true,
            'text' => 'Diagnosis: clogged drain pump. Cleaned and tested.',
            'photos' => collect($photos)->map(fn ($p, $i) => ['job_photo_id' => $p['id'], 'caption' => "Photo {$i}"])->all(),
        ],
    ]))->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    expect($invoice->has_report)->toBeTrue()
        ->and(inCompany($this->company, fn () => $invoice->reportPhotos()->count()))->toBe(10);

    $pdf = inCompany($this->company, fn () => DocumentPdf::render($invoice));
    expect(strlen($pdf))->toBeLessThanOrEqual(DocumentPdf::MAX_BYTES)->and(substr($pdf, 0, 4))->toBe('%PDF');

    // The PDF's page holds the text and all 10 photos with captions.
    $html = inCompany($this->company, fn () => view('pdf.document', [
        'doc' => DocumentPrint::data($invoice, embedLogo: true),
        'url' => PublicDocument::url($invoice),
        'report' => ['text' => $invoice->report_text, 'photos' => ServiceReport::forPdf($invoice, 900, 60)],
    ])->render());
    expect($html)->toContain('Service report')->toContain('Diagnosis: clogged drain pump.')
        ->and(substr_count($html, 'data:image/jpeg;base64,'))->toBe(10)
        ->and($html)->toContain('Photo 9');

    $this->post(route('invoices.send', $invoice), ['email' => 'payer@example.com', 'message' => 'Thanks'])->assertSessionHasNoErrors();
    Mail::assertQueued(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('payer@example.com') && count($mail->attachments()) === 1);

    $token = $invoice->fresh()->public_token;
    $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
        ->where('report.text', 'Diagnosis: clogged drain pump. Cleaned and tested.')
        ->has('report.photos', 10)->where('report.photos.0.caption', 'Photo 0'));
    $photoUrl = inCompany($this->company, fn () => route('documents.public.photo', [$token, $invoice->reportPhotos()->first()->id]));
    auth()->logout();
    $this->get($photoUrl)->assertOk();
});

test('without a report the PDF is as before; removing the block removes the report', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    expect($invoice->has_report)->toBeFalse();

    $photos = ($this->upload)(1);
    $this->put(route('invoices.update', $invoice), documentPayload(['report' => ['enabled' => true, 'text' => 'X', 'photos' => [['job_photo_id' => $photos[0]['id']]]]]));
    $this->put(route('invoices.update', $invoice), documentPayload(['report' => ['enabled' => false, 'text' => 'X', 'photos' => []]]));
    expect($invoice->fresh()->has_report)->toBeFalse()->and($invoice->fresh()->report_text)->toBeNull()
        ->and(inCompany($this->company, fn () => $invoice->reportPhotos()->count()))->toBe(0);
});

test('an invoice made from an estimate takes its report and photos; both can still be changed', function () {
    $photos = ($this->upload)(2);
    $this->post(route('estimates.store', $this->job), documentPayload([
        'report' => ['enabled' => true, 'text' => 'Estimate report', 'photos' => [['job_photo_id' => $photos[1]['id'], 'caption' => 'Pump'], ['job_photo_id' => $photos[0]['id']]]],
    ]))->assertSessionHasNoErrors();
    $estimate = inCompany($this->company, fn () => Estimate::query()->sole());
    inCompany($this->company, fn () => $estimate->forceFill(['status' => EstimateStatus::Approved])->save());

    $this->post(route('estimates.convert', $estimate))->assertSessionHasNoErrors();

    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    expect($invoice->has_report)->toBeTrue()->and($invoice->report_text)->toBe('Estimate report')
        ->and(inCompany($this->company, fn () => $invoice->reportPhotos()->pluck('job_photo_id')->all()))->toBe([$photos[1]['id'], $photos[0]['id']])
        ->and(inCompany($this->company, fn () => $invoice->reportPhotos()->first()->caption))->toBe('Pump');

    $this->get(route('invoices.edit', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('report.enabled', true)->has('report.photos', 2)->has('jobPhotos', 2));
});

test('a photo of another job cannot be put in a report', function () {
    $other = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    $foreign = inCompany($this->company, function () use ($other) {
        $photo = new JobPhoto(['kind' => 'after', 'client_uuid' => (string) Str::uuid(), 'taken_at' => now()]);
        $photo->service_job_id = $other->id;
        $photo->path = 'x.jpg';
        $photo->save();

        return $photo;
    });

    $this->post(route('invoices.store', $this->job), documentPayload(['report' => ['enabled' => true, 'photos' => [['job_photo_id' => $foreign->id]]]]));
    expect(inCompany($this->company, fn () => Invoice::query()->sole()->reportPhotos()->count()))->toBe(0);
});

test('a separate job report: filled from the job, saved with nothing required, PDF, email with the PDF and SMS with a link', function () {
    config(['services.twilio' => ['account_sid' => 'ACmaster', 'auth_token' => 't', 'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com']]);
    Http::fake(['*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    inCompany($this->company, fn () => SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 's', 'phone_number' => '+16045550100']));
    $location = inCompany($this->company, fn () => BrandAddress::query()->forceCreate(['brand_id' => $this->brand->id, 'line1' => '1 Shop St', 'city' => 'Burnaby', 'country' => 'CA', 'is_primary' => true]));

    $this->get(route('jobs.reports.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->component('reports/form')
        ->where('values.customer_name', 'Paula '.$this->customer->last_name)
        ->where('values.brand_address_id', $location->id)
        ->where('values.address', fn ($a) => str_contains($a, '500 Rental Ave'))
        ->has('locations', 1));

    // Completely empty: saved.
    $this->post(route('jobs.reports.store', $this->job), [])->assertSessionHasNoErrors();
    $report = inCompany($this->company, fn () => JobReport::query()->sole());

    $photos = ($this->upload)(3);
    $this->put(route('jobs.reports.update', $report), [
        'brand_address_id' => $location->id, 'customer_name' => 'Paula P', 'description' => 'Replaced the drain pump.',
        'photos' => collect($photos)->map(fn ($p) => ['job_photo_id' => $p['id'], 'caption' => 'Pump'])->all(),
        'signature' => 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')),
        'signer_name' => 'Paula',
    ])->assertSessionHasNoErrors();
    $report->refresh();
    expect($report->description)->toBe('Replaced the drain pump.')->and($report->signature_path)->not->toBeNull();

    $pdf = $this->get(route('jobs.reports.pdf', $report))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(strlen($pdf->getContent()))->toBeLessThanOrEqual(DocumentPdf::MAX_BYTES);
    $this->get(route('jobs.reports.pdf', [$report, 'download' => 1]))->assertHeader('Content-Disposition', 'attachment; filename="'.JobReportPdf::filename($report).'"');

    $this->post(route('jobs.reports.send', $report), ['channel' => 'email', 'to' => 'payer@example.com'])->assertSessionHasNoErrors();
    Mail::assertQueued(JobReportMail::class, fn (JobReportMail $mail) => $mail->hasTo('payer@example.com') && count($mail->attachments()) === 1);

    $this->post(route('jobs.reports.send', $report), ['channel' => 'sms'])->assertSessionHasNoErrors();
    $sms = inCompany($this->company, fn () => Message::query()->where('channel', Message::SMS)->latest('id')->value('body'));
    expect($sms)->toContain(route('reports.public', $report->public_token));

    auth()->logout();
    $this->get(route('reports.public', $report->public_token))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Under the job's Files.
    $this->actingAs($this->owner)->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->has('job.reports', 1));
});

test('another company cannot open, change or send a job report', function () {
    $stranger = memberOf(Company::factory()->create());
    $this->actingAs($this->owner)->post(route('jobs.reports.store', $this->job), ['description' => 'Secret']);
    $report = inCompany($this->company, fn () => JobReport::query()->sole());

    $this->actingAs($stranger)->get(route('jobs.reports.edit', $report))->assertNotFound();
    $this->actingAs($stranger)->put(route('jobs.reports.update', $report), ['description' => 'X'])->assertNotFound();
    $this->actingAs($stranger)->get(route('jobs.reports.pdf', $report))->assertNotFound();
    $this->actingAs($stranger)->post(route('jobs.report-photos.store', $this->job), ['photos' => [reportPhotoFile()]])->assertNotFound();
});

test('Write with AI and Translate to English answer into the field; nothing is saved', function () {
    $ai = FakeAiTransport::install(['text' => 'Diagnosis: the drain pump was clogged.']);

    $this->postJson(route('ai.report'), ['text' => 'насос забит, почистил', 'job_id' => $this->job->id])
        ->assertOk()->assertJsonPath('text', 'Diagnosis: the drain pump was clogged.');
    expect($ai->requests[0]['model'])->toBe(config('ai.models.assistant'))
        ->and(json_encode($ai->requests[0]))->toContain('Dishwasher not draining');

    $this->postJson(route('ai.translate'), ['text' => 'Заменили насос'])->assertOk()->assertJsonPath('text', 'Diagnosis: the drain pump was clogged.');
    expect($ai->requests[1]['model'])->toBe(config('ai.models.booking'));

    FakeAiTransport::install(null);
    $this->postJson(route('ai.translate'), ['text' => 'Заменили насос'])->assertStatus(422);
});

test('the microphone language RU / EN is saved for the person; the owner\'s companies default to RU', function () {
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('auth.user.dictation_language', 'en'));

    $this->putJson(route('dictation-language.update'), ['language' => 'ru'])->assertOk();
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('auth.user.dictation_language', 'ru'));

    $other = memberOf($this->company, UserRole::Technician);
    $this->company->forceFill(['default_dictation_language' => 'ru'])->save();
    $this->actingAs($other)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('auth.user.dictation_language', 'ru'));
});
