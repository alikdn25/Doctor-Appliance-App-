<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskRequest;
use App\Models\Membership;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Tasks ("+ → Task"): personal to-dos without a customer, shown in the calendar and on My Jobs.
 */
class TaskController extends Controller
{
    /** Who a task can be for, for the Task sheet: only for those who may give tasks to others. */
    public function people(Request $request): JsonResponse
    {
        Gate::authorize('create', Task::class);
        $user = $request->user();

        $people = Gate::allows('assignOthers', Task::class) && ! currentCompany()->works_alone
            ? Membership::query()->with('user')->whereIn('user_id', TaskRequest::assignableIds())->get()
                ->map(fn (Membership $m) => ['value' => (string) $m->user_id, 'label' => $m->user?->name ?? ''])
                ->sortBy(fn (array $p) => [$p['value'] !== (string) $user->id, $p['label']])
                ->values()->all()
            : [];

        return response()->json(['people' => $people, 'me' => $user->id]);
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $task = new Task($request->attributesForTask());
        $task->created_by = $request->user()->id;
        $task->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.saved')]);

        return back();
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $task->fill($request->attributesForTask())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.saved')]);

        return back();
    }

    /** Done / not done. */
    public function done(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $task->forceFill(['completed_at' => $request->boolean('done', true) ? now() : null])->save();

        return back();
    }

    public function destroy(Task $task): RedirectResponse
    {
        Gate::authorize('delete', $task);
        $task->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.deleted')]);

        return back();
    }

    /**
     * The person's tasks on the days from $from (inclusive) to $to (exclusive), for the calendar and My Jobs.
     *
     * @return list<array<string, mixed>>
     */
    public static function between(User $user, CarbonImmutable $from, CarbonImmutable $to, bool $onlyOwn = false): array
    {
        if (! $user->can('viewAny', Task::class)) {
            return [];
        }

        return Task::query()
            ->with(['user', 'creator'])
            ->when($onlyOwn, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->visibleTo($user))
            ->where('task_date', '>=', $from->toDateString())
            ->where('task_date', '<', $to->toDateString())
            ->orderBy('task_date')
            ->orderByDesc('all_day')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->map(fn (Task $task) => self::present($task, $user))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Task $task, User $user): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'note' => $task->note,
            'date' => $task->task_date->format('Y-m-d'),
            'all_day' => $task->all_day,
            'start_time' => $task->start_time,
            'end_time' => $task->end_time,
            'remind_minutes' => $task->remind_minutes,
            'user_id' => $task->user_id,
            'user_name' => $task->user?->name,
            'created_by' => $task->creator?->name,
            'done' => $task->completed_at !== null,
            'can_edit' => $user->can('update', $task),
        ];
    }
}
