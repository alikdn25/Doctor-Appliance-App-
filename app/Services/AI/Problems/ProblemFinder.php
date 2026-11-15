<?php

namespace App\Services\AI\Problems;

use App\Models\AssistantMessage;
use App\Models\ProblemReport;
use App\Models\User;
use App\Services\AI\AiFeature;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;

/**
 * Find problems: the code collects the findings (ProblemCollector), then one request to AI_MODEL_ASSISTANT turns them
 * into problems by importance, each with what to do, a link to the job / customer / invoice / estimate, and for
 * technical problems a ready task for Claude Code. Only when the Owner asks: never on a schedule, never in the
 * background. The AI fixes nothing.
 */
class ProblemFinder
{
    public function __construct(
        private readonly ProblemCollector $collector,
        private readonly AiService $ai,
    ) {}

    /**
     * @throws AiUnavailable
     */
    public function run(User $user, ?string $conversation = null): ProblemReport
    {
        $findings = $this->collector->collect($user);

        $report = $findings['business'] === [] && $findings['technical'] === []
            ? ['summary' => __('assistant.problems.none'), 'problems' => []]
            : $this->analyse($user, $findings);

        return ProblemReport::create([
            'user_id' => $user->id, 'conversation' => $conversation, 'findings' => $findings, 'report' => $report,
            'after_message_id' => $conversation === null ? null : AssistantMessage::query()->where('conversation', $conversation)->max('id'),
        ]);
    }

    /**
     * @param  array{business: list<array<string, mixed>>, technical: list<array<string, mixed>>}  $findings
     * @return array{summary: string, problems: list<array<string, mixed>>}
     */
    private function analyse(User $user, array $findings): array
    {
        $answer = $this->ai->send(
            AiFeature::FindProblems,
            currentCompany(),
            $user,
            self::instructions(),
            [['role' => 'user', 'content' => json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            schema: self::schema(),
            maxTokens: 12000,
            effort: 'medium',
        )->json();

        return ['summary' => (string) ($answer['summary'] ?? ''), 'problems' => $this->clean((array) ($answer['problems'] ?? []), $findings)];
    }

    /**
     * Keeps only links to records that are in the findings (the AI cannot point anywhere else), sorted by importance.
     *
     * @param  list<mixed>  $problems
     * @param  array{business: list<array<string, mixed>>, technical: list<array<string, mixed>>}  $findings
     * @return list<array<string, mixed>>
     */
    private function clean(array $problems, array $findings): array
    {
        $known = [];
        foreach ([...$findings['business'], ...$findings['technical']] as $finding) {
            foreach ($finding['items'] as $item) {
                if ($item['type'] !== null) {
                    $known[$item['type'].':'.$item['id']] = $item['label'];
                }
                foreach ($item['other_customer_ids'] ?? [] as $id) {
                    $known['customer:'.$id] = $known['customer:'.$id] ?? '#'.$id;
                }
                if (isset($item['other_job_id'])) {
                    $known['job:'.$item['other_job_id']] = $known['job:'.$item['other_job_id']] ?? '#'.$item['other_job_id'];
                }
            }
        }

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];

        return collect($problems)
            ->filter(fn ($p) => is_array($p) && is_string($p['title'] ?? null))
            ->map(fn (array $p) => [
                'severity' => in_array($p['severity'] ?? null, ['high', 'medium', 'low'], true) ? $p['severity'] : 'medium',
                'kind' => ($p['kind'] ?? null) === 'technical' ? 'technical' : 'business',
                'title' => mb_substr($p['title'], 0, 300),
                'why' => mb_substr((string) ($p['why'] ?? ''), 0, 2000),
                'action' => mb_substr((string) ($p['action'] ?? ''), 0, 2000),
                'links' => collect((array) ($p['links'] ?? []))
                    ->filter(fn ($l) => is_array($l) && isset($known[($l['type'] ?? '').':'.($l['id'] ?? '')]))
                    ->map(fn (array $l) => ['type' => $l['type'], 'id' => (int) $l['id'], 'label' => $known[$l['type'].':'.$l['id']], 'url' => self::url($l['type'], (int) $l['id'])])
                    ->unique('url')->take(10)->values()->all(),
                'claude_task' => ($p['kind'] ?? null) === 'technical' && filled($p['claude_task'] ?? null) ? mb_substr((string) $p['claude_task'], 0, 6000) : null,
            ])
            ->sortBy(fn (array $p) => $rank[$p['severity']])
            ->values()->all();
    }

    private static function url(string $type, int $id): string
    {
        return match ($type) {
            'job' => route('jobs.show', $id, false),
            'customer' => route('customers.show', $id, false),
            'invoice' => route('invoices.show', $id, false),
            'estimate' => route('estimates.show', $id, false),
        };
    }

    private static function instructions(): string
    {
        return <<<'TEXT'
            You review the findings of automatic checks for a small repair company (appliance repair, handyman) and tell the
            owner what needs fixing. The input is JSON: "business" (overdue invoices, parts waiting, estimates without an
            answer, unclosed past jobs, schedule overlaps, customers missing a phone or address, possible duplicates) and
            "technical" (errors from the last 7 days). Each finding has a count and example items with a type and id.

            - Group related findings into problems and order them by importance: money and customers waiting first.
              severity: high (money or a customer at risk now), medium (should be done this week), low (housekeeping).
            - title: one short line. why: one or two sentences with the numbers from the findings. action: what the owner
              should do, concrete and short.
            - links: the records from the findings the owner should open (type and id exactly as given). Never invent ids.
            - For technical problems write claude_task: a ready task for Claude Code (the developer agent) in one block:
              what is failing (exact error text and how often), where to look, what to fix, and "add a test" — direct
              instructions, no explanations. Business problems have claude_task "" (an empty string).
            - Plain English. Do not repeat the same item in several problems. Do not claim anything not in the findings.
            - summary: one or two sentences: how many problems and the most important one.
            TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'problems' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'severity' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'kind' => ['type' => 'string', 'enum' => ['business', 'technical']],
                            'title' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                            'links' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['type' => 'string', 'enum' => ['job', 'customer', 'invoice', 'estimate']],
                                        'id' => ['type' => 'integer'],
                                    ],
                                    'required' => ['type', 'id'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'claude_task' => ['type' => 'string'],
                        ],
                        'required' => ['severity', 'kind', 'title', 'why', 'action', 'links', 'claude_task'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'problems'],
            'additionalProperties' => false,
        ];
    }
}
