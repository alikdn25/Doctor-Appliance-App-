<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\ReopenJob;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\VisitRequest;
use App\Messaging\BookingConfirmation;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Inertia\Inertia;

/**
 * "Reopen job" on a closed job's page (owner's task of November 26): a new visit on the same job (a window, Now, No
 * time or no date) and, when "Notify client" is ticked, the booking confirmation as for a new booking.
 */
class JobReopenController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job, ReopenJob $reopen, BookingConfirmation $confirmation): RedirectResponse
    {
        Gate::authorize('update', $job);

        $data = validator($request->all(), VisitRequest::visitRules() + ['notify' => ['boolean'], 'no_date' => ['boolean']], [], VisitRequest::visitAttributes())
            ->after(fn (Validator $validator) => VisitRequest::validateAssignees($validator, (array) $request->input('assignee_ids', [])))
            ->validate();

        $noDate = (bool) ($data['no_date'] ?? false);
        $visit = $noDate ? null : VisitRequest::toVisit($data);
        $reopen->handle($job, $visit, $noDate ? null : ($data['date'] ?? null), $request->user());

        if ((bool) ($data['notify'] ?? false) && $job->refresh()->status !== JobStatus::Draft) {
            $sent = $confirmation->send($job, $request->user());
            BookingConfirmationController::flashResult($sent);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.reopen.done')]);
        }

        return to_route('jobs.show', $job);
    }
}
