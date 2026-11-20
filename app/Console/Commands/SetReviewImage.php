<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Reviews\ReviewImage;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Sets a company's picture for the Google review block in invoice emails from a file on the server, the same way
 * as Company → Google reviews (220×220 PNG, at most 40 KB). For setting it up without the app's settings screen.
 */
#[Signature('reviews:set-image {company : Company id or slug} {path : PNG, JPEG, WebP or GIF file}')]
#[Description('Set the picture of the Google review block in invoice emails for one company')]
class SetReviewImage extends Command
{
    public function handle(CurrentCompany $current): int
    {
        $key = (string) $this->argument('company');
        $company = Company::query()->where(ctype_digit($key) ? 'id' : 'slug', $key)->first();
        $path = (string) $this->argument('path');

        if ($company === null) {
            $this->error("No company {$key}.");

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error("No file {$path}.");

            return self::FAILURE;
        }

        try {
            $current->runAs($company, fn () => ReviewImage::store($company, $path));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("OK {$company->name}: ".ReviewImage::url($company->fresh()));

        return self::SUCCESS;
    }
}
