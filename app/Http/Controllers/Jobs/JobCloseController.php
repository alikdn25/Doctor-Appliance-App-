<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\CloseJob;
use App\Enums\JobOutcome;
use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Closing a job with its outcome from the job page (when no visit is on the way or in progress). On site the
 * technician does the same from "Finish".
 */
class JobCloseController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job, CloseJob $close): RedirectResponse
    {
        Gate::authorize('work', $job);

        $outcome = JobOutcome::tryFrom((string) $request->input('outcome'));
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(array_map(fn (JobOutcome $o) => $o->value, JobOutcome::closing()))],
            'reason' => $outcome?->needsReason()
                ? ['required', Rule::in(currentCompany()->closureReasons($outcome))]
                : ['nullable'],
            'note' => ['nullable', 'string', 'max:2000'],
            'invoice_diagnosis' => ['boolean'],
        ], [], ['reason' => __('jobs.fields.reason')]);

        $close->close($job, $outcome, $validated['reason'] ?? null, $validated['note'] ?? null, $request->user());

        if ($outcome->allowsDiagnosisInvoice() && $request->boolean('invoice_diagnosis')) {
            return to_route('invoices.create', ['job' => $job->id, 'diagnosis' => 1]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.closed', ['outcome' => $outcome->label()])]);

        return back();
    }
}
