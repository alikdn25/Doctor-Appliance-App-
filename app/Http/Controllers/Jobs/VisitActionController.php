<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\CloseJob;
use App\Actions\Jobs\RefundOriginalJob;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
     * Finish on site: completed (repaired / fixed under warranty), parts to order, waiting for parts, or closed without a repair
     * (customer declined, unable to repair, no charge; with a reason). See JobCloseController for the rest.
     */
    public function finish(Request $request, JobVisit $visit, VisitWorkflow $workflow, CloseJob $close, RefundOriginalJob $refund): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $job = JobCloseController::visitJob($visit);
        $data = JobCloseController::validateClose($request, $job, [
            JobStatus::Completed->value, JobStatus::PartsToOrder->value, JobStatus::WaitingForParts->value,
            ...array_map(fn (JobOutcome $o) => $o->value, JobOutcome::closing()),
        ]);
        $note = $data['note'] ?? null;

        if (in_array($data['outcome'], [JobStatus::PartsToOrder->value, JobStatus::WaitingForParts->value], true)) {
            $workflow->finish($visit, $request->user(), JobStatus::from($data['outcome']), $note);

            return back();
        }

        $outcome = JobOutcome::tryFrom($data['outcome']) ?? JobOutcome::Repaired;

        DB::transaction(function () use ($visit, $workflow, $close, $refund, $request, $outcome, $data, $note, $job) {
            $workflow->finish($visit, $request->user(), JobStatus::Completed, $outcome === JobOutcome::Repaired
                ? $note
                : collect([$outcome->label(), $data['reason'] ?? null, $note])->filter()->implode(' — '));
            $close->close($job, $outcome, $data['reason'] ?? null, $note, $request->user(), $visit);
            JobCloseController::refundOriginal($job, $outcome, $data, $request, $refund);
        });

        return JobCloseController::afterClose($job, $outcome, $request) ?? back();
    }
}
