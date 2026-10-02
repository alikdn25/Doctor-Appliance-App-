<?php

namespace App\Support\Jobs;

use App\Enums\Vertical;

/**
 * Starting checklists per job type for a new company, by vertical. Each company edits its own in settings.
 * Kept in English like the rest of the text that companies can change themselves.
 */
class ChecklistDefaults
{
    /**
     * @return array<string, list<string>>
     */
    public static function forVertical(Vertical $vertical): array
    {
        return match ($vertical) {
            Vertical::ApplianceRepair => self::applianceRepair(),
            Vertical::Handyman => self::handyman(),
        };
    }

    /**
     * @return array<string, list<string>>
     */
    public static function handyman(): array
    {
        return [
            'repair' => [
                'Confirm the job and the price with the customer',
                'Protect floors and furniture',
                'Photo before',
                'Photo after',
                'Clean up and remove debris',
            ],
            'installation' => [
                'Confirm the location with the customer',
                'Check for pipes and wires before drilling',
                'Level and secure',
                'Test with the customer',
                'Remove packaging',
            ],
            'assembly' => [
                'Check all parts against the instructions',
                'Assemble and tighten all fasteners',
                'Anchor to the wall if required',
                'Remove packaging',
            ],
            'mounting' => [
                'Confirm height and position with the customer',
                'Locate studs, check for pipes and wires',
                'Mount and level',
                'Hide or tidy cables',
                'Clean up dust',
            ],
            'maintenance' => [
                'Walk through the task list with the customer',
                'Note anything that needs a follow-up job',
                'Clean up the work area',
            ],
            'inspection' => [
                'Photo of each issue found',
                'Note findings in Work done',
                'Suggest an estimate for repairs',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function applianceRepair(): array
    {
        $repair = [
            'Confirm the problem with the customer',
            'Record brand, model and serial from the rating plate',
            'Check for error codes',
            'Test run after the repair',
            'Clean up the work area',
        ];

        return [
            'repair' => $repair,
            'warranty' => [
                'Record brand, model and serial from the rating plate',
                'Photo of proof of purchase',
                'Confirm the problem with the customer',
                'Test run after the repair',
                'Clean up the work area',
            ],
            'maintenance' => [
                'Inspect hoses, seals and connections',
                'Clean filters',
                'Check for error codes',
                'Test run',
            ],
            'installation' => [
                'Check the space, power and water supply',
                'Level the appliance',
                'Check for leaks',
                'Test run with the customer',
                'Remove packaging',
            ],
            'vent_cleaning' => [
                'Photo of the vent before cleaning',
                'Clean the duct and exterior hood',
                'Check airflow at the exterior',
                'Photo of the vent after cleaning',
            ],
            'inspection' => [
                'Record brand, model and serial from the rating plate',
                'Check for error codes',
                'Note findings in Work done',
            ],
        ];
    }
}
