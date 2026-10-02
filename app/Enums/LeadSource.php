<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Fixed list for now; per-company configurable sources come later.
 */
enum LeadSource: string
{
    use HasOptions;

    case GoogleBusinessProfile = 'google_business_profile';
    case GoogleAds = 'google_ads';
    case Website = 'website';
    // Yelp, Angi, Thumbtack, HomeStars, Checkatrade … (one bucket for every country)
    case Directory = 'directory';
    case Facebook = 'facebook';
    case Referral = 'referral';
    case RepeatCustomer = 'repeat_customer';
    case Manufacturer = 'manufacturer';
    case PropertyManager = 'property_manager';
    case Other = 'other';

    public function label(): string
    {
        return __("customers.lead_sources.{$this->value}");
    }
}
