<?php

namespace App\Support\Jobs;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\Appliance;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Jobs without a time (owner's task of November 25). "No time": an open job with a day but no visit with a time; it
 * shows on that day (calendar strip above the hours, first on Today and My Jobs). "No date": an open job with neither;
 * it shows at the top of Today and above today in the calendar until it gets a day. Parts-flow, on-hold and
 * waiting-for-customer jobs have their own lists and are not "No date". Tenant and member scopes apply.
 */
class UnscheduledJobs
{
    /** Statuses that wait for something else rather than for a day. */
    private const WAITING = [JobStatus::NeedToOrderParts, JobStatus::WaitingForParts, JobStatus::WaitingForCustomer, JobStatus::OnHold];

    /** Statuses of finished jobs. */
    private const FINISHED = [JobStatus::Completed, JobStatus::Invoiced, JobStatus::AwaitingPayment, JobStatus::Paid, JobStatus::Cancelled];

    /** Most jobs a strip lists. */
    public const LIMIT = 50;

    /**
     * Open jobs the person sees that have no open visit.
     *
     * @return Builder<ServiceJob>
     */
    public static function base(?User $user, bool $onlyOwn = false): Builder
    {
        // No person (the morning SMS): the whole company.
        return ServiceJob::query()
            ->when($user !== null, fn (Builder $q) => $q->visibleTo($user))
            ->whereNotIn('status', self::FINISHED)
            ->whereNull('outcome')
            ->whereNull('closed_at')
            ->whereDoesntHave('visits', fn (Builder $v) => $v->whereIn('status', VisitStatus::openValues()))
            // My Jobs of a technician: only the jobs given to them.
            ->when($onlyOwn && $user !== null, fn (Builder $q) => $q->whereHas('assignees', fn (Builder $a) => $a->where('users.id', $user->id)));
    }

    /**
     * "No time" jobs of the days from $from (inclusive) to $to (exclusive), local dates.
     *
     * @return Collection<int, ServiceJob>
     */
    public static function noTime(?User $user, CarbonImmutable $from, CarbonImmutable $to, bool $onlyOwn = false): Collection
    {
        return self::base($user, $onlyOwn)
            ->whereNotNull('planned_date')
            ->where('planned_date', '>=', $from->toDateString())
            ->where('planned_date', '<', $to->toDateString())
            ->with(['customer.primaryPhone', 'property', 'appliances', 'assignees'])
            ->orderBy('planned_date')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * "No date" jobs: no day and no time yet.
     *
     * @return Builder<ServiceJob>
     */
    public static function noDateQuery(?User $user, bool $onlyOwn = false): Builder
    {
        return self::base($user, $onlyOwn)
            ->whereNull('planned_date')
            ->whereNotIn('status', self::WAITING);
    }

    /**
     * @return Collection<int, ServiceJob>
     */
    public static function noDate(?User $user, bool $onlyOwn = false): Collection
    {
        return self::noDateQuery($user, $onlyOwn)
            ->with(['customer.primaryPhone', 'property', 'appliances', 'assignees'])
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * A strip / Today card of a job without a time.
     *
     * @return array<string, mixed>
     */
    public static function card(ServiceJob $job): array
    {
        return [
            'id' => $job->id,
            'number' => $job->number,
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'job_type_label' => $job->job_type->label(),
            'planned_date' => $job->planned_date?->toDateString(),
            'customer' => $job->customer?->display_name,
            'customer_icon' => $job->customer?->avatarIcon() ?? 'neutral',
            'phone' => $job->customer?->primaryPhone?->number,
            'address' => $job->property?->hasAddress() ? $job->property->fullAddress() : null,
            'appliances' => $job->appliances->map(fn (Appliance $a) => $a->label())->values()->all(),
            'appliance_types' => $job->appliances->map(fn (Appliance $a) => $a->type->label())->values()->all(),
            'picture' => JobPresenter::picture($job),
            'problem' => $job->description,
            'assignees' => $job->assignees->pluck('name')->values()->all(),
            'assignee_ids' => $job->assignees->pluck('id')->values()->all(),
        ];
    }
}
