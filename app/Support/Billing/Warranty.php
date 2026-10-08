<?php

namespace App\Support\Billing;

use App\Enums\LineKind;
use App\Enums\WarrantyUnit;
use App\Models\Company;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Warranty of a line: a length (0 = no warranty) in days, weeks or months, and the day it ends.
 *
 * Default for a new line: the price book service's warranty → the company's defaults (labour for services and
 * materials have none; parts, or the "above threshold" warranty for parts priced above the threshold).
 */
class Warranty
{
    /**
     * @return array{value: int, unit: string}
     */
    public static function default(Company $company, LineKind $kind, int $unitPrice, ?Service $service = null): array
    {
        if ($kind === LineKind::Material) {
            return ['value' => 0, 'unit' => WarrantyUnit::Days->value];
        }

        if ($service?->warranty_value !== null) {
            return ['value' => $service->warranty_value, 'unit' => $service->warranty_unit ?? WarrantyUnit::Days->value];
        }

        if ($kind === LineKind::Part) {
            if ($company->warranty_parts_threshold !== null && $company->warranty_parts_above_value !== null
                && $unitPrice > $company->warranty_parts_threshold) {
                return ['value' => $company->warranty_parts_above_value, 'unit' => $company->warranty_parts_above_unit ?? WarrantyUnit::Days->value];
            }

            return ['value' => $company->warranty_parts_value, 'unit' => $company->warranty_parts_unit];
        }

        return ['value' => $company->warranty_labor_value, 'unit' => $company->warranty_labor_unit];
    }

    /**
     * The last day covered, or null for no warranty.
     */
    public static function endsOn(CarbonInterface $start, ?int $value, ?string $unit): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        $start = CarbonImmutable::instance($start)->startOfDay();

        return match (WarrantyUnit::tryFrom((string) $unit) ?? WarrantyUnit::Days) {
            WarrantyUnit::Days => $start->addDays($value),
            WarrantyUnit::Weeks => $start->addWeeks($value),
            WarrantyUnit::Months => $start->addMonthsNoOverflow($value),
        };
    }

    /**
     * "90 days", "3 months", "No warranty".
     */
    public static function label(?int $value, ?string $unit): string
    {
        if (! $value) {
            return __('billing.no_warranty');
        }

        return trans_choice("billing.warranty_length.{$unit}", $value, ['count' => $value]);
    }
}
