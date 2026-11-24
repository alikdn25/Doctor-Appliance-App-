<?php

namespace App\Support\Statistics;

use App\Enums\ApplianceCategory;

/**
 * Reads the appliance type and brand from free text (job description, notes, model): used once to fill older jobs.
 * Only clear words count; anything unclear stays empty.
 */
class ApplianceGuess
{
    /** Usual English spelling of the brands seen in appliance repair. */
    public const BRANDS = [
        'LG', 'Samsung', 'Whirlpool', 'Maytag', 'KitchenAid', 'Frigidaire', 'Electrolux', 'GE Profile', 'GE Cafe',
        'Café', 'Monogram', 'GE', 'Bosch', 'Thermador', 'Gaggenau', 'Miele', 'Sub-Zero', 'Wolf', 'Viking', 'Jenn-Air',
        'Amana', 'Kenmore', 'Fisher & Paykel', 'Speed Queen', 'Haier', 'Hotpoint', 'Dacor', 'Fulgor Milano', 'Bertazzoni',
        'Smeg', 'Asko', 'Blomberg', 'Beko', 'Panasonic', 'Sharp', 'Inglis', 'Danby', 'Liebherr', 'Signature Kitchen Suite',
        'Ilve', 'BlueStar', 'Hisense', 'Midea', 'Insignia', 'Summit', 'Marvel', 'U-Line', 'Perlick', 'Zephyr', 'Broan',
        'Faber', 'Elica', 'Vent-A-Hood',
    ];

    /** Words for each type, checked in this order (dishwasher before washer, wall oven before range/oven …). */
    private const TYPES = [
        'dishwasher' => ['dishwasher', 'dish washer', 'посудомо'],
        'microwave' => ['microwave', 'микроволн', 'свч'],
        'range_hood' => ['range hood', 'hood fan', 'vent hood', 'вытяжк'],
        'wall_oven' => ['wall oven', 'built-in oven', 'духовой шкаф', 'духовка'],
        'cooktop' => ['cooktop', 'cook top', 'induction top', 'варочн'],
        'range_oven' => ['range', 'stove', 'oven', 'плита'],
        'freezer' => ['freezer', 'морозил'],
        'refrigerator' => ['refrigerator', 'fridge', 'холодильник', 'wine cooler'],
        'dryer' => ['dryer', 'сушил', 'сушка'],
        'washer' => ['washer', 'washing machine', 'стирал'],
    ];

    public static function type(?string $text): ?ApplianceCategory
    {
        $text = mb_strtolower((string) $text);

        if (trim($text) === '') {
            return null;
        }

        foreach (self::TYPES as $value => $words) {
            foreach ($words as $word) {
                if (preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'/u', $text) === 1) {
                    return ApplianceCategory::from($value);
                }
            }
        }

        return null;
    }

    public static function brand(?string $text): ?string
    {
        $text = (string) $text;

        foreach (self::BRANDS as $brand) {
            if (preg_match('/(?<![\p{L}\d])'.preg_quote($brand, '/').'(?![\p{L}\d])/iu', $text) === 1) {
                return $brand;
            }
        }

        return null;
    }

    /** A brand as typed (" lg ") in its usual spelling ("LG"); unknown brands are kept, tidied. */
    public static function normalizeBrand(?string $brand): ?string
    {
        $brand = trim((string) $brand);

        if ($brand === '') {
            return null;
        }

        foreach (self::BRANDS as $known) {
            if (mb_strtolower($known) === mb_strtolower($brand)) {
                return $known;
            }
        }

        return mb_substr($brand, 0, 100);
    }
}
