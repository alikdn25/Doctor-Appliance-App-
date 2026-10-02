<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The trade of a company (SPEC §1.2). Picked when the company is created; it sets the job types,
 * the default checklists and the starting services.
 */
enum Vertical: string
{
    use HasOptions;

    case ApplianceRepair = 'appliance_repair';
    case Handyman = 'handyman';

    public function label(): string
    {
        return __("company.verticals.{$this->value}");
    }

    /**
     * @return list<JobType>
     */
    public function jobTypes(): array
    {
        return match ($this) {
            self::ApplianceRepair => [
                JobType::Repair, JobType::Warranty, JobType::Maintenance,
                JobType::Installation, JobType::VentCleaning, JobType::Inspection,
            ],
            self::Handyman => [
                JobType::Repair, JobType::Installation, JobType::Assembly,
                JobType::Mounting, JobType::Maintenance, JobType::Inspection,
            ],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function jobTypeOptions(): array
    {
        return array_map(fn (JobType $type) => ['value' => $type->value, 'label' => $type->label()], $this->jobTypes());
    }

    /**
     * Whether jobs record appliances (model, serial, rating plate).
     */
    public function tracksAppliances(): bool
    {
        return $this === self::ApplianceRepair;
    }
}
