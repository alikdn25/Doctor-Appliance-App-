<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\SetJobStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Manual status change: the office sets any status; the technician on the job sets a waiting reason.
 */
class JobStatusController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job, SetJobStatus $setStatus): RedirectResponse
    {
        $office = Gate::allows('update', $job);
        abort_unless($office || Gate::allows('work', $job), 403);
        // Beyond the waiting reasons, status changes stay with the office.
        abort_if(! $office && ($job->status->isLocked()
            || ! in_array(JobStatus::tryFrom((string) $request->input('status')), JobStatus::waiting(), true)), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(JobStatus::class)->only($office ? JobStatus::manual() : JobStatus::waiting())],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => [
                'nullable', 'required_if:status,'.JobStatus::Cancelled->value,
                Rule::in(currentCompany()->closureReasons(JobOutcome::Cancelled)),
            ],
        ], [], ['reason' => __('jobs.fields.reason')]);

        $setStatus->handle($job, JobStatus::from($validated['status']), $request->user(), $validated['note'] ?? null, $validated['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.status_changed')]);

        return back();
    }
}
