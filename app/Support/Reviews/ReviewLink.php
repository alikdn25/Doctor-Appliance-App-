<?php

namespace App\Support\Reviews;

use App\Models\Brand;
use App\Models\GoogleProfile;

/**
 * The Google review link for a customer document (Company → Google reviews, the same locations the review request
 * uses): the brand's default location, else a location of that brand, else a location for any brand. Null when
 * there is no such link (a location of another brand is never used). Must run in a tenant context.
 */
class ReviewLink
{
    public static function for(?Brand $brand): ?string
    {
        return self::profileFor($brand)?->review_url;
    }

    /**
     * The location whose link that is (null when it has no link).
     */
    public static function profileFor(?Brand $brand): ?GoogleProfile
    {
        $profiles = GoogleProfile::query()->orderBy('id')->get(['id', 'brand_id', 'review_url']);

        $profile = $profiles->firstWhere('id', $brand?->google_profile_id)
            ?? ($brand ? $profiles->firstWhere('brand_id', $brand->id) : null)
            ?? $profiles->firstWhere('brand_id', null);

        return filled($profile?->review_url) ? $profile : null;
    }
}
