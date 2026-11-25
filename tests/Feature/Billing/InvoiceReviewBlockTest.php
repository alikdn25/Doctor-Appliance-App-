<?php

use App\Actions\Billing\SendDocument;
use App\Enums\UserRole;
use App\Mail\DocumentMail;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Reviews\ReviewImage;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    config(['fieldservice.media_disk' => 'public']);

    $this->company = Company::factory()->inUnitedStates()->create(['name' => 'Lone Star Group']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Lone Star Appliance']);
    $customer = Customer::factory()->for($this->company)->withEmail('maria@example.com')->create(['first_name' => 'Maria']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);

    $this->post(route('invoices.store', $this->job), documentPayload());
    $this->invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->message = inCompany($this->company, fn () => SendDocument::defaultMessage($this->invoice->fresh()));
});

function reviewPng(): string
{
    $path = tempnam(sys_get_temp_dir(), 'rv').'.png';
    $image = imagecreatetruecolor(600, 400);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledellipse($image, 300, 200, 300, 300, imagecolorallocate($image, 30, 60, 120));
    imagepng($image, $path);

    return $path;
}

test('the invoice message has no balance or due date in brackets', function () {
    expect($this->message)
        ->toContain("Please find invoice {$this->invoice->number} for \$280.50 attached.")
        ->toContain('You can view it and pay online with the link below.')
        ->not->toContain('balance due')
        ->not->toContain('(')
        ->not->toContain('due ');
});

test('the review block sits between the first sentence and the pay line when a review link is set', function () {
    $austin = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Austin', 'review_url' => 'https://g.page/r/austin/review']));

    $html = (new DocumentMail($this->invoice, $this->message))->render();
    // Through our address, so a click is seen on the job.
    $tracked = route('reviews.go', ['token' => $this->invoice->job()->value('review_token'), 'profile' => $austin->id]);

    expect($html)
        ->toContain('Every review helps a local family business grow.')
        ->toContain('⭐⭐⭐⭐⭐')
        ->toContain('Leave a review →')
        ->and(substr_count($html, 'href="'.$tracked.'" target="_blank"'))->toBe(3)
        ->and(strpos($html, 'attached.'))->toBeLessThan(strpos($html, 'Every review helps'))
        ->and(strpos($html, 'Every review helps'))->toBeLessThan(strpos($html, 'You can view it and pay online'))
        ->and($html)->not->toContain('<img');
});

test('the brand default location is used and the picture links to it', function () {
    $dallas = inCompany($this->company, function () {
        GoogleProfile::create(['label' => 'Austin', 'review_url' => 'https://g.page/r/austin/review']);
        $dallas = GoogleProfile::create(['label' => 'Dallas', 'review_url' => 'https://g.page/r/dallas/review']);
        $this->brand->update(['google_profile_id' => $dallas->id]);
        ReviewImage::store($this->company, reviewPng());

        return $dallas;
    });

    $html = (new DocumentMail($this->invoice, $this->message))->render();
    $url = ReviewImage::url($this->company->fresh());
    $tracked = route('reviews.go', ['token' => $this->invoice->job()->value('review_token'), 'profile' => $dallas->id]);

    expect($url)->toStartWith(url('/storage/companies/'.$this->company->id.'/review-image-'))
        ->and($html)->toContain('src="'.$url.'" width="110" height="110" alt="Leave us a Google review"')
        ->and(substr_count($html, 'href="'.$tracked.'" target="_blank"'))->toBe(4)
        ->and($html)->not->toContain('austin');
});

test('there is no review block without a review link', function () {
    inCompany($this->company, fn () => ReviewImage::store($this->company, reviewPng()));

    $html = (new DocumentMail($this->invoice, $this->message))->render();

    expect($html)->not->toContain('Every review helps')->not->toContain('<img')->not->toContain('⭐')
        ->toContain('You can view it and pay online');
});

test('estimates get no review block', function () {
    inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Austin', 'review_url' => 'https://g.page/r/austin/review']));
    $this->post(route('estimates.store', $this->job), documentPayload());
    $estimate = inCompany($this->company, fn () => Estimate::sole());

    expect((new DocumentMail($estimate, 'Hello'))->render())->not->toContain('Every review helps');
});

test('an edited message without the pay line gets the block after the text', function () {
    inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Austin', 'review_url' => 'https://g.page/r/austin/review']));
    $mail = new DocumentMail($this->invoice, 'Hi Maria, here is your invoice.');
    app(CurrentCompany::class)->forget();

    $html = $mail->render();

    expect(strpos($html, 'here is your invoice.'))->toBeLessThan(strpos($html, 'Every review helps'));
});

test('the office uploads, replaces and removes the review picture', function () {
    $this->post(route('company.google-profiles.image.store'), ['image' => new UploadedFile(reviewPng(), 'me.png', 'image/png', null, true)])
        ->assertSessionHasNoErrors();

    $path = $this->company->fresh()->review_image_path;
    Storage::disk('public')->assertExists($path);
    $bytes = Storage::disk('public')->get($path);
    $image = imagecreatefromstring($bytes);
    expect(imagesx($image))->toBe(220)->and(imagesy($image))->toBe(220)
        ->and(strlen($bytes))->toBeLessThanOrEqual(40 * 1024)
        ->and(imagecolorsforindex($image, imagecolorat($image, 1, 1))['alpha'])->toBe(127);

    $this->get(route('company.google-profiles.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('reviewImageUrl', ReviewImage::url($this->company->fresh())));

    $this->post(route('company.google-profiles.image.store'), ['image' => new UploadedFile(reviewPng(), 'me2.png', 'image/png', null, true)]);
    $replaced = $this->company->fresh()->review_image_path;
    expect($replaced)->not->toBe($path);
    Storage::disk('public')->assertMissing($path);

    $this->delete(route('company.google-profiles.image.destroy'))->assertRedirect();
    expect($this->company->fresh()->review_image_path)->toBeNull();
    Storage::disk('public')->assertMissing($replaced);
});

test('the attached Doctor Appliance picture fits in 40 KB at 220×220', function () {
    $png = ReviewImage::process(resource_path('images/reviews/doctor-appliance.png'));

    expect(strlen($png))->toBeLessThanOrEqual(40 * 1024)
        ->and(array_slice(getimagesizefromstring($png), 0, 2))->toBe([220, 220]);
});

test('a non-image is refused and technicians cannot change the picture', function () {
    $this->post(route('company.google-profiles.image.store'), ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('image');

    $this->actingAs(memberOf($this->company, UserRole::Technician));
    $this->post(route('company.google-profiles.image.store'), ['image' => new UploadedFile(reviewPng(), 'me.png', 'image/png', null, true)])
        ->assertForbidden();
});

test('another company\'s review link never reaches this invoice', function () {
    $other = Company::factory()->create();
    inCompany($other, fn () => GoogleProfile::create(['label' => 'Other', 'review_url' => 'https://g.page/r/other/review']));

    expect((new DocumentMail($this->invoice, $this->message))->render())->not->toContain('g.page/r/other');
});
