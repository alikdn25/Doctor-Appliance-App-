<?php

namespace App\Services\AI;

use App\Models\ServiceJob;

/**
 * When a job moves to "Need to order parts" (owner's task of November 18): AI_MODEL_ASSISTANT suggests a possible part
 * number from the appliance's brand and model and the problem. Kept as a suggestion marked "check"; it goes into the
 * part fields only when the person taps Use and saves.
 */
class PartFinder
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array{part_number: string, description: string}|null
     *
     * @throws AiUnavailable
     */
    public function suggest(ServiceJob $job): ?array
    {
        $job->loadMissing('appliances');
        $appliances = $job->appliances->map(fn ($a) => trim(implode(' ', array_filter([$a->manufacturer, $a->type->label(), $a->model_number ? 'model '.$a->model_number : null]))))->implode('; ');

        if ($appliances === '' && trim((string) $job->part_name) === '' && trim((string) $job->description) === '') {
            return null;
        }

        $raw = $this->ai->send(
            AiFeature::PartLookup,
            currentCompany(),
            null,
            'You help an appliance repair technician find the OEM part number to order. Give the most likely part number '
            .'for this appliance model and problem, as printed by the manufacturer. If you are not reasonably sure, return '
            .'part_number "". Never invent a model.',
            [['role' => 'user', 'content' => "Appliance: {$appliances}\nPart needed: ".($job->part_name ?: '—')."\nProblem: ".($job->description ?: '—')."\nNotes: ".($job->tech_notes ?: '—')]],
            schema: self::schema(),
            maxTokens: 400,
        )->json();

        $number = mb_strtoupper(trim((string) ($raw['part_number'] ?? '')));

        return $number === '' ? null : [
            'part_number' => mb_substr($number, 0, 100),
            'description' => mb_substr(trim((string) ($raw['description'] ?? '')), 0, 255),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['part_number' => ['type' => 'string'], 'description' => ['type' => 'string', 'description' => 'What the part is, short']],
            'required' => ['part_number', 'description'],
            'additionalProperties' => false,
        ];
    }
}
