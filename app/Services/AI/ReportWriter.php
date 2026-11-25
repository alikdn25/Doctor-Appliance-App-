<?php

namespace App\Services\AI;

use App\Models\Company;
use App\Models\JobNote;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * "Write with AI" in a report (owner's task of November 25): AI_MODEL_ASSISTANT turns the job's notes and the text in
 * the field (any language, dictated or typed) into a clean English service report — diagnosis, what was found, what
 * was done, recommendations. Nothing is invented; the person edits it and saves it.
 */
class ReportWriter
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @throws AiUnavailable
     */
    public function write(?ServiceJob $job, string $text, Company $company, ?User $user): string
    {
        $answer = $this->ai->send(AiFeature::ReportWrite, $company, $user, self::instructions($company),
            [['role' => 'user', 'content' => self::material($job, $text)]], schema: TextTranslator::schema(), maxTokens: 3000, effort: 'low')->json();

        $report = trim((string) ($answer['text'] ?? ''));
        if ($report === '') {
            throw new AiUnavailable('Empty report.');
        }

        return $report;
    }

    private static function material(?ServiceJob $job, string $text): string
    {
        $lines = [];
        if ($job !== null) {
            $job->loadMissing('appliances');
            $appliance = $job->appliances->first();
            $lines[] = 'Appliance: '.(trim(implode(' ', array_filter([
                $appliance?->type->label(), $appliance?->manufacturer, $appliance?->model_number,
            ]))) ?: '(not given)');
            $lines[] = 'Problem reported: '.($job->description ?: '(not given)');
            if (filled($job->notes)) {
                $lines[] = 'Job notes: '.$job->notes;
            }
            if (filled($job->tech_notes)) {
                $lines[] = 'Technician notes: '.$job->tech_notes;
            }
            foreach (JobNote::query()->where('service_job_id', $job->id)->orderBy('id')->limit(30)->pluck('body') as $note) {
                $lines[] = 'Team note: '.$note;
            }
        }
        $lines[] = 'Text in the report field: '.(trim($text) !== '' ? $text : '(empty)');

        return implode("\n", $lines);
    }

    private static function instructions(Company $company): string
    {
        return <<<TEXT
            You write the service report of {$company->name}, an appliance repair company, for the customer. From the
            material (Russian, English or mixed; dictated notes) write a short, clean, professional report in English
            with these parts, each a short paragraph or a few lines, under plain headings:
            Diagnosis, Findings, Work performed, Recommendations.
            Use only facts from the material; invent nothing (no parts, prices, tests or results that are not there).
            Leave out a part with nothing to say. Keep model and part numbers exactly. No greeting, no signature, no
            prices. Plain text, no markdown symbols.
            TEXT;
    }
}
