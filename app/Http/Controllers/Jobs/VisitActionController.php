<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\VisitWorkflow;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\JobVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Status buttons in the field: On my way, Start, Finish.
 */
class VisitActionController extends Controller
{
    public function onMyWay(Request $request, JobVisit $visit, VisitWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $workflow->onMyWay($visit, $request->user());

        return back();
    }

    public function start(Request $request, JobVisit $visit, VisitWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $workflow->start($visit, $request->user());

        return back();
    }

    public function finish(Request $request, JobVisit $visit, VisitWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in([JobStatus::Completed->value, JobStatus::WaitingForParts->value])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $workflow->finish($visit, $request->user(), JobStatus::from($validated['outcome']), $validated['note'] ?? null);

        return back();
    }
}
