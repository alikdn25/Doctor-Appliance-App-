<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\CloseJob;
use App\Actions\Jobs\RefundOriginalJob;
use App\Actions\Jobs\VisitWorkflow;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\PhotoKind;
use App\Enums\SmsMode;
use App\Enums\VisitStatus;
use App\Enums\VisitType;
use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Messaging\MessageTemplates;
use App\Messaging\Messenger;
use App\Models\Appliance;
use App\Models\JobPhoto;
use App\Models\JobVisit;
use App\Support\Jobs\JobPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
     * The Finish visit screen (approved mockup): customer, appliance, work completed / notes, photos and the result.
     */
    public function showFinish(Request $request, JobVisit $visit): Response|RedirectResponse
    {
        Gate::authorize('work', $visit);

        $job = JobCloseController::visitJob($visit)->load(['customer.primaryPhone', 'property', 'appliances', 'photos.user', 'previousJob']);

        // Only a started visit can be finished; afterwards the screen goes back to the job.
        if ($visit->status !== VisitStatus::InProgress || ! $job->status->allowsVisitWork()) {
            return to_route('jobs.show', $job);
        }

        $user = $request->user();
        $canUpdate = Gate::allows('update', $job);
        $property = $job->property;

        return Inertia::render('jobs/finish', [
            'visitId' => $visit->id,
            'job' => [
                'id' => $job->id,
                'number' => $job->number,
                'tech_notes' => $job->tech_notes,
                'customer' => [
                    'display_name' => $job->customer->display_name,
                    'avatar_icon' => $job->customer->avatarIcon(),
                    'phone' => $job->customer->primaryPhone?->number,
                ],
                'address' => $property?->fullAddress(),
                'unit' => $property?->unit,
                'gate_code' => $property?->gate_code,
                'appliances' => $job->appliances->map(fn (Appliance $a) => JobPresenter::appliance($a))->values(),
                'photos' => $job->photos->map(fn (JobPhoto $photo) => [
                    'id' => $photo->id,
                    'kind' => $photo->kind->value,
                    'url' => route('jobs.photos.show', [$job, $photo]),
                    'taken_at' => JobPresenter::iso($photo->taken_at),
                    'user' => $photo->user?->name,
                    'can_delete' => $canUpdate || ($photo->user_id === $user->id && Gate::allows('work', $job)),
                ])->values(),
            ],
            'photoKinds' => PhotoKind::options(),
            'closureReasons' => [
                'customer_declined' => currentCompany()->closureReasons(JobOutcome::CustomerDeclined),
                'unable_to_repair' => currentCompany()->closureReasons(JobOutcome::UnableToRepair),
                'cancelled' => currentCompany()->closureReasons(JobOutcome::Cancelled),
                'no_charge' => currentCompany()->closureReasons(JobOutcome::NoCharge),
            ],
            'callback' => $job->visit_type === VisitType::Callback && $job->previousJob ? [
                'number' => $job->previousJob->number,
                'refundable' => RefundOriginalJob::refundable($job->previousJob),
                'currency' => $job->previousJob->invoices()->value('currency') ?? currentCompany()->currency,
            ] : null,
        ]);
    }

    /**
     * Finish on site: completed (repaired / fixed under warranty), waiting for parts, or closed without a repair
     * (customer declined, unable to repair, no charge; with a reason). See JobCloseController for the rest.
     */
    public function finish(Request $request, JobVisit $visit, VisitWorkflow $workflow, CloseJob $close, RefundOriginalJob $refund): RedirectResponse
    {
        Gate::authorize('work', $visit);

        $job = JobCloseController::visitJob($visit);
        $data = JobCloseController::validateClose($request, $job, [
            JobStatus::Completed->value, JobStatus::WaitingForParts->value,
            ...array_map(fn (JobOutcome $o) => $o->value, JobOutcome::closing()),
        ]);
        $note = $data['note'] ?? null;

        // "Work completed / notes" typed on the Finish visit screen is the job's work notes.
        if ($request->has('tech_notes')) {
            $notes = $request->validate(['tech_notes' => ['nullable', 'string', 'max:5000']])['tech_notes'] ?? null;
            $job->forceFill(['tech_notes' => $notes])->save();
        }

        if ($data['outcome'] === JobStatus::WaitingForParts->value) {
            $workflow->finish($visit, $request->user(), JobStatus::WaitingForParts, $note);

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
