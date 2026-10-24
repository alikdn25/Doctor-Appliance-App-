<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Messaging\MessageTemplates;
use App\Messaging\Messenger;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Send Booking Confirmation (after booking and on the job page): the day, arrival window and address to the
 * customer — the one who pays — by email or SMS.
 */
class BookingConfirmationController extends Controller
{
    /** What would be sent and where: the text, the email address, the number and whether it can be texted. */
    public function show(ServiceJob $job, Messenger $messenger): JsonResponse
    {
        Gate::authorize('work', $job);
        $job->loadMissing(['customer.primaryEmail', 'brand', 'property']);
        $mode = currentCompany()->sms_mode;
        $phone = $messenger->mobile($job->customer);

        return response()->json([
            'body' => $this->body($job),
            'email' => $job->customer->primaryEmail?->email,
            'sms' => [
                // From technician's phone: the page opens the phone's messages app; Off: no texts at all.
                'mode' => $mode->value,
                'to' => $phone?->number,
                'blocked' => match ($mode) {
                    SmsMode::Automatic => $messenger->smsBlockedReason($job->customer),
                    SmsMode::TechnicianPhone => $phone === null ? __('messages.blocked.no_phone') : null,
                    default => __('jobs.confirmation.sms_off'),
                },
            ],
        ]);
    }

    public function send(Request $request, ServiceJob $job, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('work', $job);
        $channel = $request->validate(['channel' => ['required', Rule::in(['email', 'sms'])]])['channel'];
        $job->loadMissing(['customer', 'brand', 'property']);
        $body = $this->body($job);

        if ($channel === 'sms') {
            // From technician's phone the text goes from the phone's own app (recorded by messages/opened).
            abort_unless(currentCompany()->sms_mode === SmsMode::Automatic, 404);
            $message = $messenger->sms(MessageKind::BookingConfirmation, $job->customer, $job, $body, $request->user());

            return JobMessageController::result($message->status, $message->status_reason, $message->send_after);
        }

        $message = $messenger->emailMessage(MessageKind::BookingConfirmation, $job->customer, $job, $body, $request->user(),
            __('messages.email_subject.booking_confirmation', ['brand' => $job->brand?->name ?? currentCompany()->name]));

        Inertia::flash('toast', $message->status === 'blocked'
            ? ['type' => 'error', 'message' => __('messages.sms_blocked', ['reason' => $message->status_reason])]
            : ['type' => 'success', 'message' => __('jobs.confirmation.emailed', ['email' => $message->to])]);

        return back();
    }

    private function body(ServiceJob $job): string
    {
        // The next visit that is still to come, else the latest one.
        $visit = JobVisit::query()->where('service_job_id', $job->id)
            ->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::OnTheWay->value])
            ->orderBy('scheduled_start')->first()
            ?? JobVisit::query()->where('service_job_id', $job->id)->orderByDesc('scheduled_start')->first();

        return MessageTemplates::render(currentCompany(), MessageKind::BookingConfirmation, MessageContext::for($job->customer, $job, $visit));
    }
}
