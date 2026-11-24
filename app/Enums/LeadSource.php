<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a customer came from. Pickers offer Google, Website, Repeat, Referral, Other (owner's task of November 24);
 * the older, finer values stay on existing records and are counted in those five groups by Statistics.
 */
enum LeadSource: string
{
    use HasOptions;

    case Google = 'google';
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

    /**
     * The five sources the pickers offer.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::offered());
    }

    /**
     * @return list<self>
     */
    public static function offered(): array
    {
        return [self::Google, self::Website, self::RepeatCustomer, self::Referral, self::Other];
    }

    /** The Statistics group: one of the five offered sources. */
    public function group(): self
    {
        return match ($this) {
            self::Google, self::GoogleBusinessProfile, self::GoogleAds => self::Google,
            self::Website => self::Website,
            self::RepeatCustomer => self::RepeatCustomer,
            self::Referral => self::Referral,
            default => self::Other,
        };
    }
}
