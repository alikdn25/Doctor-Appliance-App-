<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskRequest;
use App\Jobs\GeocodeAddress;
use App\Models\JobPart;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AiLimitReached;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\TaskParser;
use App\Support\Jobs\NearbyWindows;
use App\Support\Locale\LocalTime;
use App\Support\Parts\JobParts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tasks ("+ → Task", the To Do column of Today): to-dos without a customer, shown in the calendar and on My Jobs —
 * a type (Parts, Materials, Home, Business, Call), an address ("On the way" near a job of that day), a linked job and
 * a repeat. Home tasks are seen only by the one who made it.
 */
class TaskController extends Controller
{
    /** "On the way": a job of that day within this distance of the task's address. */
    public const ON_THE_WAY_KM = 5.0;

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

        // A task can be linked to an open job the person sees (newest first).
        $jobs = ServiceJob::query()->visibleTo($user)->with('customer')
            ->whereNotIn('status', [JobStatus::Invoiced->value, JobStatus::AwaitingPayment->value, JobStatus::Paid->value, JobStatus::Cancelled->value])
            ->latest('id')->limit(60)->get()
            ->map(fn (ServiceJob $job) => ['value' => (string) $job->id, 'label' => trim('#'.$job->number.' '.($job->customer?->display_name ?? ''))])
            ->values()->all();

        return response()->json(['people' => $people, 'me' => $user->id, 'jobs' => $jobs]);
    }

    /**
     * A to-do's own page (a tap on it in Today): every detail, Done, Edit and Delete.
     */
    public function show(Request $request, Task $task): Response
    {
        Gate::authorize('view', $task);
        $user = $request->user();
        $task->load(['user', 'creator', 'job.customer']);

        return Inertia::render('tasks/show', [
            'task' => [
                ...self::present($task, $user),
                'on_the_way' => self::onTheWay(collect([$task]))[$task->id] ?? null,
                'planned_date' => $task->planned_date?->format('Y-m-d'),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'can_delete' => $user->can('delete', $task),
            ],
        ]);
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $task = new Task($request->attributesForTask());
        $task->created_by = $request->user()->id;
        $task->save();
        self::geocode($task);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.saved')]);

        return back();
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $task->fill($request->attributesForTask())->save();
        self::geocode($task);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('tasks.saved')]);

        return back();
    }

    /**
     * Done / not done. An automatic "Buy at {supplier}" task ticked means those parts were bought. A Parts or
     * Materials task ticked offers "Add a receipt?".
     */
    public function done(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $done = $request->boolean('done', true);

        // The parts first: the task then closes with them (and no new "Buy at" task is made meanwhile).
        if ($done && $task->supplier_id !== null) {
            JobPart::query()->with('job')->where('supplier_id', $task->supplier_id)->where('status', JobPart::TO_ORDER)->get()
                ->each(fn (JobPart $part) => JobParts::move($part, JobPart::ON_HAND, $request->user()));
        }

        $task->refresh()->forceFill(['completed_at' => $done ? ($task->completed_at ?? now()) : null])->save();

        if ($done && in_array($task->kind, [Task::KIND_PARTS, Task::KIND_MATERIALS], true)) {
            Inertia::flash('offerReceipt', true);
        }

        return back();
    }

    /**
     * The microphone of the To Do column: the text becomes the task form (nothing is saved).
     */
    public function parse(Request $request, TaskParser $parser, AiService $ai): JsonResponse
    {
        Gate::authorize('create', Task::class);
        $text = $request->validate(['text' => ['required', 'string', 'max:1000']])['text'];

        if (! $ai->configured()) {
            return response()->json(['message' => __('jobs.quick_booking.not_set_up')], 503);
        }

        try {
            $task = $parser->parse($text, currentCompany(), $request->user());
        } catch (AiLimitReached) {
            return response()->json(['message' => __('jobs.quick_booking.limit')], 429);
        } catch (AiUnavailable) {
            return response()->json(['message' => __('jobs.quick_booking.failed')], 503);
        }

        return $task === null
            ? response()->json(['message' => __('tasks.parse_failed')], 422)
            : response()->json(['task' => $task]);
    }

    private static function geocode(Task $task): void
    {
        if ($task->address !== null && $task->latitude === null) {
            GeocodeAddress::dispatch(Task::class, $task->id)->afterCommit();
        }
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

        $tasks = Task::query()
            ->with(['user', 'creator', 'job.customer'])
            ->visibleTo($user)
            ->when($onlyOwn, fn ($q) => $q->where('user_id', $user->id))
            ->where('task_date', '>=', $from->toDateString())
            ->where('task_date', '<', $to->toDateString())
            ->orderBy('task_date')
            ->orderByDesc('all_day')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
        $nearby = self::onTheWay($tasks);

        return $tasks->map(fn (Task $task) => [...self::present($task, $user), 'on_the_way' => $nearby[$task->id] ?? null])->all();
    }

    /**
     * "On the way: near the job at {time}, {N} km": for each task with a position, the nearest open job of its day
     * within ON_THE_WAY_KM (straight line).
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, string> task id => label
     */
    public static function onTheWay(Collection $tasks): array
    {
        $placed = $tasks->filter(fn (Task $t) => $t->latitude !== null && $t->longitude !== null && $t->completed_at === null);
        if ($placed->isEmpty()) {
            return [];
        }

        $company = currentCompany();
        $tz = $company->timezone;
        $days = $placed->map(fn (Task $t) => $t->task_date->format('Y-m-d'))->unique();
        $first = CarbonImmutable::parse((string) $days->min(), $tz)->startOfDay();
        $last = CarbonImmutable::parse((string) $days->max(), $tz)->endOfDay();
        $visits = JobVisit::query()->whereIn('status', VisitStatus::openValues())
            ->whereBetween('scheduled_start', [$first->utc(), $last->utc()])
            ->whereHas('job.property', fn ($q) => $q->whereNotNull('latitude')->whereNotNull('longitude'))
            ->with('job.property')->get()
            ->groupBy(fn (JobVisit $v) => CarbonImmutable::instance($v->scheduled_start)->setTimezone($tz)->toDateString());

        $labels = [];
        foreach ($placed as $task) {
            $best = null;
            foreach ($visits->get($task->task_date->format('Y-m-d'), collect()) as $visit) {
                $property = $visit->job->property;
                $km = NearbyWindows::km(
                    ['lat' => (float) $task->latitude, 'lng' => (float) $task->longitude],
                    ['lat' => (float) $property->latitude, 'lng' => (float) $property->longitude],
                );
                if ($km <= self::ON_THE_WAY_KM && ($best === null || $km < $best[0])) {
                    $best = [$km, $visit];
                }
            }
            if ($best !== null) {
                $labels[$task->id] = __('tasks.on_the_way', [
                    'time' => LocalTime::format(CarbonImmutable::instance($best[1]->scheduled_start), $tz, $company->locale),
                    'km' => number_format($best[0], $best[0] < 10 ? 1 : 0),
                ]);
            }
        }

        return $labels;
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
            'kind' => $task->kind,
            'address' => $task->address,
            'repeat' => $task->repeat,
            'carried_days' => $task->completed_at === null ? $task->carriedDays() : 0,
            'automatic' => $task->supplier_id !== null,
            'job' => $task->job === null ? null : [
                'id' => $task->job->id,
                'number' => $task->job->number,
                'customer' => $task->job->customer?->display_name,
            ],
        ];
    }
}
