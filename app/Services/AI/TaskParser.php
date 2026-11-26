<?php

namespace App\Services\AI;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Support\Locale\RelativeDate;
use Carbon\CarbonImmutable;

/**
 * The microphone of the To Do column (owner's task of November 19): "tomorrow buy an element at Marcone on Kingsway"
 * goes to AI_MODEL_BOOKING and comes back as the task form (title, date, time or all day, address, type). Days like
 * "tomorrow" / "завтра" become dates by code in the company's time zone; a supplier named in the text gives its
 * saved address. Nothing is saved: the person checks the form and presses Save. The assistant's tasks (propose_task)
 * come through here too: a note and the customer's job (by number or the customer's name) are read as well.
 */
class TaskParser
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array{title: string, task_date: string, all_day: bool, start_time: string|null, address: string|null, kind: string|null, note: string|null, service_job_id: int|null, job_label: string|null}|null
     *
     * @throws AiUnavailable
     */
    public function parse(string $text, Company $company, User $user, ?int $jobId = null): ?array
    {
        $suppliers = Supplier::query()->orderBy('name')->get(['id', 'name', 'address']);
        $raw = $this->ai->send(
            AiFeature::TaskParse,
            $company,
            $user,
            $this->instructions($company, $suppliers->pluck('name')->all()),
            [['role' => 'user', 'content' => trim($text)]],
            schema: self::schema(),
            maxTokens: 600,
            effort: 'low',
        )->json();

        $task = self::normalize($raw, $company, $suppliers->all());

        if ($task === null) {
            return null;
        }

        $job = self::job($jobId, is_string($raw['job'] ?? null) ? trim($raw['job']) : '', $user);

        return [
            ...$task,
            'service_job_id' => $job?->id,
            'job_label' => $job === null ? null : trim('#'.$job->number.' '.($job->customer?->display_name ?? '')),
        ];
    }

    /**
     * The customer's job the task is about: the one the assistant found (by id), else by number ("#1042", "1042"),
     * else the newest job of the customer named (open ones first). Only jobs the person may see.
     */
    private static function job(?int $id, string $hint, User $user): ?ServiceJob
    {
        $visible = fn () => ServiceJob::query()->visibleTo($user)->with('customer');

        if ($id !== null && $id > 0) {
            return $visible()->find($id);
        }

        $number = ltrim($hint, '#');

        if ($number === '') {
            return null;
        }

        if (ctype_digit($number)) {
            return $visible()->where('number', (int) $number)->first();
        }

        return $visible()->search($hint, false)
            ->orderByRaw("case when status in ('completed', 'invoiced', 'paid', 'cancelled') then 1 else 0 end")
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $properties = [
            'title' => ['type' => 'string', 'description' => 'Short to-do in English, e.g. "Buy heating element at Marcone"'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD or the words said ("tomorrow", "thursday"); "" = today'],
            'time' => ['type' => 'string', 'description' => 'HH:MM (24 h) when a time was said, else ""'],
            'address' => ['type' => 'string', 'description' => 'Where, as said (store and street), else ""'],
            'supplier' => ['type' => 'string', 'description' => 'One of the known suppliers when named, else ""'],
            'kind' => ['type' => 'string', 'enum' => [...Task::KINDS, 'none']],
            'note' => ['type' => 'string', 'description' => 'Details worth keeping (part numbers, what to ask), else ""'],
            'job' => ['type' => 'string', 'description' => 'The customer\'s job it is about: job number or the customer\'s name, else ""'],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  list<string>  $suppliers
     */
    private function instructions(Company $company, array $suppliers): string
    {
        $today = CarbonImmutable::now($company->timezone)->format('Y-m-d (l)');
        $known = $suppliers === [] ? '(none)' : implode(', ', $suppliers);

        return <<<TEXT
            You turn a short note of an appliance repair technician (Russian, English or mixed) into a to-do.
            - title: short, in English, starting with a verb ("Buy heating element at Marcone", "Call Mrs. Lee about the
              dryer"). Translate Russian words (тэн = heating element, уплотнитель = door gasket, ремень = belt).
            - date: YYYY-MM-DD when sure, else the words said ("tomorrow", "thursday"); "" when no day was said. Today is
              {$today} (time zone {$company->timezone}).
            - time: HH:MM only when a time was said.
            - address: the place as said ("Marcone, Kingsway"); "" when none.
            - supplier: one of the known suppliers when named: {$known}.
            - kind: parts (buy or pick up appliance parts), materials (other supplies), home (personal / family),
              business (company errands, bank, paperwork), call (phone someone), none.
            - note: details worth keeping that are not in the title (part numbers, quantities, what to ask), else "".
            - job: the job number or the customer's name when the to-do is for a customer's job, else "".
            TEXT;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<Supplier>  $suppliers
     * @return array{title: string, task_date: string, all_day: bool, start_time: string|null, address: string|null, kind: string|null, note: string|null}|null
     */
    private static function normalize(array $raw, Company $company, array $suppliers): ?array
    {
        $string = fn (string $key, int $max) => is_string($raw[$key] ?? null) && trim($raw[$key]) !== '' ? mb_substr(trim($raw[$key]), 0, $max) : null;
        $title = $string('title', 160);

        if ($title === null) {
            return null;
        }

        $time = $string('time', 5);
        $time = $time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : null;
        $supplierName = mb_strtolower((string) $string('supplier', 160));
        $supplier = $supplierName === '' ? null : collect($suppliers)->first(fn (Supplier $s) => mb_strtolower($s->name) === $supplierName);
        $kind = $string('kind', 20);

        return [
            'title' => $title,
            'task_date' => RelativeDate::resolve($string('date', 40), $company) ?? CarbonImmutable::now($company->timezone)->toDateString(),
            'all_day' => $time === null,
            'start_time' => $time,
            'address' => filled($supplier?->address) ? $supplier->address : $string('address', 500),
            'kind' => in_array($kind, Task::KINDS, true) ? $kind : null,
            'note' => $string('note', 5000),
        ];
    }
}
