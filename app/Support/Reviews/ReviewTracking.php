<?php

namespace App\Support\Reviews;

use App\Models\GoogleProfile;
use App\Models\ServiceJob;
use Illuminate\Support\Str;

/**
 * Review links that go through our address (owner's task of November 25): /r/{token}/{location} records the
 * customer's first click on the job and then opens the location's Google review page. The token is per job.
 */
class ReviewTracking
{
    public static function url(ServiceJob $job, GoogleProfile $profile): string
    {
        if ($job->review_token === null) {
            $job->forceFill(['review_token' => Str::random(40)])->saveQuietly();
        }

        return route('reviews.go', ['token' => $job->review_token, 'profile' => $profile->id]);
    }

    /**
     * The text with the location's own review link replaced by the job's tracked one.
     */
    public static function inText(string $text, ?ServiceJob $job, GoogleProfile $profile): string
    {
        if ($job === null || blank($profile->review_url)) {
            return $text;
        }

        return str_replace($profile->review_url, self::url($job, $profile), $text);
    }

    /**
     * The tracked link for a job, or the location's own link when there is no job.
     */
    public static function link(?ServiceJob $job, GoogleProfile $profile): ?string
    {
        if (blank($profile->review_url)) {
            return null;
        }

        return $job === null ? $profile->review_url : self::url($job, $profile);
    }
}
