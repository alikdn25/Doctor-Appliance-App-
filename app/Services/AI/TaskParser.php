<?php

namespace App\Services\AI;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Support\Locale\RelativeDate;
use Carbon\CarbonImmutable;

/**
 * The microphone of the To Do column (owner's task of November 19): "tomorrow buy an element at Marcone on Kingsway"
 * goes to AI_MODEL_BOOKING and comes back as the task form (title, date, time or all day, address, type). Days like
 * "tomorrow" / "завтра" become dates by code in the company's time zone; a supplier named in the text gives its
 * saved address. Nothing is saved: the person checks the form and presses Save.
 */
class TaskParser
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array{title: string, task_date: string, all_day: bool, start_time: string|null, address: string|null, kind: string|null}|null
     *
     * @throws AiUnavailable
     */
    public function parse(string $text, Company $company, User $user): ?array
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

        return self::normalize($raw, $company, $suppliers->all());
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
            TEXT;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<Supplier>  $suppliers
     * @return array{title: string, task_date: string, all_day: bool, start_time: string|null, address: string|null, kind: string|null}|null
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
        ];
    }
}
