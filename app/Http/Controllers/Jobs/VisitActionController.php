<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\CloseJob;
use App\Actions\Jobs\VisitWorkflow;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Messaging\MessageTemplates;
use App\Messaging\Messenger;
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
    /**
     * Automatic: the customer gets an SMS with the arrival window. Off: the same text by email.
     * From technician's phone: the page opens the phone's messages app itself (and records it).
     */
    public function onMyWay(Request $request, JobVisit $visit, VisitWorkflow $workflow, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $workflow->onMyWay($visit, $request->user());

        if (currentCompany()->sms_mode !== SmsMode::TechnicianPhone) {
            $job = $visit->job()->with(['customer', 'brand'])->firstOrFail();
            $body = MessageTemplates::render(currentCompany(), MessageKind::OnMyWay, MessageContext::for($job->customer, $job, $visit, $request->user()));
            $messenger->send(MessageKind::OnMyWay, $job->customer, $job, $body, $request->user(),
                __('messages.email_subject.on_my_way', ['brand' => $job->brand?->name ?? currentCompany()->name]));
        }

        return back();
    }

    public function start(Request $request, JobVisit $visit, VisitWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $workflow->start($visit, $request->user());

        return back();
    }

    /**
     * Finish on site: completed (repaired), waiting for parts, or closed without a repair (customer declined, unable to
     * repair; with a reason). "Invoice diagnosis only" opens the invoice form with the diagnostic fee line.
     */
    public function finish(Request $request, JobVisit $visit, VisitWorkflow $workflow, CloseJob $close): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $outcome = JobOutcome::tryFrom((string) $request->input('outcome'));
        $validated = $request->validate([
            'outcome' => ['required', Rule::in([
                JobStatus::Completed->value, JobStatus::WaitingForParts->value,
                JobOutcome::CustomerDeclined->value, JobOutcome::UnableToRepair->value,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => $outcome?->needsReason()
                ? ['required', Rule::in(currentCompany()->closureReasons($outcome))]
                : ['nullable'],
            'invoice_diagnosis' => ['boolean'],
        ], [], ['reason' => __('jobs.fields.reason')]);

        $note = $validated['note'] ?? null;

        if ($validated['outcome'] === JobStatus::WaitingForParts->value) {
            $workflow->finish($visit, $request->user(), JobStatus::WaitingForParts, $note);

            return back();
        }

        $outcome ??= JobOutcome::Repaired;
        $workflow->finish($visit, $request->user(), JobStatus::Completed, $outcome === JobOutcome::Repaired
            ? $note
            : collect([$outcome->label(), $validated['reason'] ?? null, $note])->filter()->implode(' — '));
        $close->close($visit->job, $outcome, $validated['reason'] ?? null, $note, $request->user(), $visit);

        if ($outcome->allowsDiagnosisInvoice() && $request->boolean('invoice_diagnosis')) {
            return to_route('invoices.create', ['job' => $visit->service_job_id, 'diagnosis' => 1]);
        }

        return back();
    }
}
