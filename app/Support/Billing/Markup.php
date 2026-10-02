<?php

namespace App\Support\Billing;

use App\Enums\LineKind;
use App\Models\Company;

/**
 * Selling price from cost by the company's markup scale (separate for parts and materials): the first tier whose
 * "up to" covers the cost gives the multiplier. The price is a suggestion and can always be changed.
 */
class Markup
{
    /**
     * Default scales, "up to" in major units of the company currency.
     *
     * @return list<array{up_to: float|null, multiplier: float}>
     */
    public static function defaults(LineKind $kind): array
    {
        return $kind === LineKind::Material
            ? [['up_to' => 10, 'multiplier' => 2.0], ['up_to' => 100, 'multiplier' => 1.6], ['up_to' => null, 'multiplier' => 1.4]]
            : [['up_to' => 20, 'multiplier' => 2.5], ['up_to' => 100, 'multiplier' => 2.0], ['up_to' => 500, 'multiplier' => 1.6], ['up_to' => null, 'multiplier' => 1.35]];
    }

    /**
     * @return list<array{up_to: float|null, multiplier: float}>
     */
    public static function scale(Company $company, LineKind $kind): array
    {
        $own = $kind === LineKind::Material ? $company->markup_materials : $company->markup_parts;

        return is_array($own) && $own !== [] ? array_values($own) : self::defaults($kind);
    }

    /**
     * Price per unit for a cost per unit (both minor units).
     */
    public static function price(Company $company, LineKind $kind, int $unitCost, int $minorFactor): int
    {
        foreach (self::scale($company, $kind) as $tier) {
            if ($tier['up_to'] === null || $unitCost <= (float) $tier['up_to'] * $minorFactor) {
                return (int) round($unitCost * (float) $tier['multiplier']);
            }
        }

        return $unitCost;
    }
}
