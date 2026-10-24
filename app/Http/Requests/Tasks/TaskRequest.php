<?php

namespace App\Http\Requests\Tasks;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "+ → Task": title, day, a time or all day, note, who it is for (oneself, or a teammate when the person may give
 * tasks) and an optional reminder.
 */
class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            ? $this->user()->can('update', $task)
            : $this->user()->can('create', Task::class);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:5000'],
            'task_date' => ['required', 'date_format:Y-m-d'],
            'all_day' => ['boolean'],
            'start_time' => ['exclude_if:all_day,true', 'required', 'date_format:H:i'],
            'end_time' => ['exclude_if:all_day,true', 'nullable', 'date_format:H:i', 'after:start_time'],
            'user_id' => ['nullable', 'integer'],
            'remind_minutes' => ['nullable', 'integer', Rule::in(Task::REMINDERS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect(['title', 'note', 'task_date', 'start_time', 'end_time', 'user_id', 'remind_minutes'])
            ->mapWithKeys(fn (string $field) => [$field => __("tasks.fields.{$field}")])
            ->all();
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $for = $this->assignee();

            if ($for === $this->user()->id) {
                return;
            }

            if (! $this->user()->can('assignOthers', Task::class) || ! in_array($for, self::assignableIds(), true)) {
                $validator->errors()->add('user_id', __('tasks.errors.assignee'));
            }
        }];
    }

    /** The one the task is for: oneself unless someone else was picked. */
    public function assignee(): int
    {
        $picked = $this->input('user_id');

        if (filled($picked)) {
            return (int) $picked;
        }

        $task = $this->route('task');

        return $task instanceof Task ? $task->user_id : (int) $this->user()->id;
    }

    /**
     * Active members a task can be given to.
     *
     * @return list<int>
     */
    public static function assignableIds(): array
    {
        return Membership::query()->where('is_active', true)
            ->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value, UserRole::Technician->value, UserRole::Collector->value])
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForTask(): array
    {
        $data = $this->validated();
        $allDay = (bool) ($data['all_day'] ?? false);

        return [
            'title' => trim($data['title']),
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            'task_date' => $data['task_date'],
            'all_day' => $allDay,
            'start_time' => $allDay ? null : $data['start_time'],
            'end_time' => $allDay ? null : ($data['end_time'] ?? null),
            'remind_minutes' => $data['remind_minutes'] ?? null,
            'user_id' => $this->assignee(),
        ];
    }
}
