<?php

namespace App\Support\Jobs;

/**
 * Starting checklists per job type for a new company. Each company edits its own in settings.
 * Kept in English like the rest of the UI text that companies can change themselves.
 */
class ChecklistDefaults
{
    /**
     * @return array<string, list<string>>
     */
    public static function all(): array
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
