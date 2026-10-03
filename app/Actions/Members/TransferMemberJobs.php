<?php

namespace App\Actions\Members;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

class TransferMemberJobs
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Membership $source, Membership $target): int
    {
        if ($source->company_id !== $target->company_id || ! $target->is_active || ! $target->user->is_active || $source->user_id === $target->user_id) {
            throw ValidationException::withMessages(['replacement_id' => __('team.errors.invalid_replacement')]);
        }

        $visits = JobVisit::query()->whereHas('assignees', fn ($q) => $q->where('users.id', $source->user_id))
            ->whereIn('status', VisitStatus::openValues())
            ->whereHas('job', fn ($q) => $q->whereNull('closed_at')->whereNull('outcome')->whereNotIn('status', [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled]))
            ->with('job')->lockForUpdate()->get();
        $user = User::findOrFail($target->user_id);
        $allowedBrands = $user->brands()->withTrashed()->pluck('brands.id')->all();
        if ($allowedBrands !== [] && $visits->contains(fn ($visit) => ! in_array($visit->job->brand_id, $allowedBrands, true))) {
            throw ValidationException::withMessages(['replacement_id' => __('team.errors.replacement_brands')]);
        }

        foreach ($visits as $visit) {
            $visit->assignees()->syncWithoutDetaching([$target->user_id]);
            $visit->assignees()->detach($source->user_id);
        }
        $this->audit->record('member.jobs_transferred', $source, ['from_user_id' => $source->user_id, 'to_user_id' => $target->user_id, 'visits' => $visits->pluck('id')->all()]);

        return $visits->count();
    }
}
