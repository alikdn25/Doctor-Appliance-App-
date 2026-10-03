<?php

namespace App\Actions\Members;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransferMemberJobs
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Membership $source, Membership $target): int
    {
        if ($source->company_id !== $target->company_id || ! $target->is_active || ! $target->user->is_active || $source->user_id === $target->user_id) {
            throw ValidationException::withMessages(['replacement_id' => __('team.errors.invalid_replacement')]);
        }

        $jobs = ServiceJob::query()
            ->where(fn ($q) => $q->whereHas('assignees', fn ($a) => $a->where('users.id', $source->user_id))->orWhere(fn ($legacy) => $legacy->where('assignment_is_explicit', false)->whereHas('visits.assignees', fn ($a) => $a->where('users.id', $source->user_id))))
            ->whereNull('closed_at')->whereNull('outcome')->whereNotIn('status', [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled])
            ->lockForUpdate()->get();
        $user = User::findOrFail($target->user_id);
        $allowedBrands = $user->brands()->withTrashed()->pluck('brands.id')->all();
        foreach ($jobs as $job) {
            Gate::authorize('update', $job);
            if ($allowedBrands !== [] && ! in_array($job->brand_id, $allowedBrands, true)) {
                throw ValidationException::withMessages(['replacement_id' => __('team.errors.replacement_brands')]);
            }
        }
        $visits = JobVisit::query()->whereIn('service_job_id', $jobs->pluck('id'))
            ->whereHas('assignees', fn ($q) => $q->where('users.id', $source->user_id))
            ->whereIn('status', VisitStatus::openValues())->lockForUpdate()->get();
        foreach ($visits as $visit) {
            $visit->assignees()->syncWithoutDetaching([$target->user_id]);
            $visit->assignees()->detach($source->user_id);
        }
        foreach ($jobs as $job) {
            $ids = $job->assignment_is_explicit
                ? $job->assignees()->pluck('users.id')->all()
                : DB::table('job_visit_user')->whereIn('job_visit_id', $job->visits()->pluck('id'))->pluck('user_id')->all();
            $ids = array_values(array_unique([...array_diff($ids, [$source->user_id]), $target->user_id]));
            $job->assignees()->sync(array_fill_keys($ids, ['company_id' => $job->company_id]));
            $job->forceFill(['assignment_is_explicit' => true])->saveQuietly();
        }
        $this->audit->record('member.jobs_transferred', $source, ['from_user_id' => $source->user_id, 'to_user_id' => $target->user_id, 'jobs' => $jobs->pluck('id')->all(), 'visits' => $visits->pluck('id')->all()]);

        return $jobs->count();
    }
}
