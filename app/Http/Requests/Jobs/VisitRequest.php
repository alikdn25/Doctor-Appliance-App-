<?php

namespace App\Http\Requests\Jobs;

use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A visit's arrival window is entered as date + "from"/"to" times in the company's timezone.
 */
class VisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var JobVisit|null $visit */
        $visit = $this->route('visit');
        /** @var ServiceJob|null $job */
        $job = $this->route('job');

        return $visit
            ? $this->user()->can('update', $visit)
            : $job !== null && $this->user()->can('update', $job);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::visitRules();
    }

    /**
     * @return array<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => self::validateAssignees($validator, (array) $this->input('assignee_ids', []))];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::visitAttributes();
    }

    /**
     * @return array{attributes: array<string, mixed>, assignee_ids: list<int>}
     */
    public function visit(): array
    {
        return self::toVisit($this->validated());
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function visitRules(string $prefix = '', string $required = 'required'): array
    {
        return [
            "{$prefix}date" => [$required, 'nullable', 'date_format:Y-m-d'],
            "{$prefix}start_time" => [$required, 'nullable', 'date_format:H:i'],
            "{$prefix}end_time" => [$required, 'nullable', 'date_format:H:i', "after:{$prefix}start_time"],
            "{$prefix}estimated_duration_minutes" => ['nullable', 'integer', 'min:5', 'max:1440'],
            "{$prefix}assignee_ids" => ['array', 'max:10'],
            "{$prefix}assignee_ids.*" => ['integer', 'distinct'],
            "{$prefix}strict_arrival" => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function visitAttributes(string $prefix = ''): array
    {
        return collect(['date', 'start_time', 'end_time', 'estimated_duration_minutes', 'assignee_ids'])
            ->mapWithKeys(fn ($field) => ["{$prefix}{$field}" => __("jobs.visit_fields.{$field}")])
            ->all();
    }

    /**
     * Only active members of the current company who can go on calls may be assigned.
     *
     * @param  array<mixed>  $ids
     */
    public static function validateAssignees(Validator $validator, array $ids, string $prefix = ''): void
    {
        $allowed = Membership::query()->assignable()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        foreach (array_values($ids) as $i => $id) {
            if (! in_array((int) $id, $allowed, true)) {
                $validator->errors()->add("{$prefix}assignee_ids.{$i}", __('jobs.errors.invalid_assignee'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, assignee_ids: list<int>}
     */
    public static function toVisit(array $data): array
    {
        $timezone = currentCompany()->timezone;
        $at = fn (string $time) => CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$time}", $timezone)->utc();

        return [
            'attributes' => [
                'scheduled_start' => $at($data['start_time']),
                'scheduled_end' => $at($data['end_time']),
                'estimated_duration_minutes' => $data['estimated_duration_minutes'] ?? null,
                'strict_arrival' => (bool) ($data['strict_arrival'] ?? false),
            ],
            'assignee_ids' => array_values(array_map('intval', $data['assignee_ids'] ?? [])),
        ];
    }
}
