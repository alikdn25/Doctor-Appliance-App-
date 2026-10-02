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
 * Manual status change by the office.
 */
class JobStatusController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job, SetJobStatus $setStatus): RedirectResponse
    {
        Gate::authorize('update', $job);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(JobStatus::class)->only(JobStatus::manual())],
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
