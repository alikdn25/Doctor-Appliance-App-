<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\MarkEstimateSent;
use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Messaging\Messenger;
use App\Messaging\ReviewRequests;
use App\Models\GoogleProfile;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Texts from a job: an SMS typed on the job (Automatic mode), the record that a text was opened on the
 * technician's phone (sms: link), and the Google review request with the link the technician chose.
 */
class JobMessageController extends Controller
{
    public function sms(Request $request, ServiceJob $job, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('work', $job);
        abort_unless(currentCompany()->sms_mode === SmsMode::Automatic, 404);

        $body = $request->validate(['body' => ['required', 'string', 'max:1000']])['body'];
        $message = $messenger->sms(MessageKind::General, $job->customer, $job, $body, $request->user());

        return $this->result($message->status, $message->status_reason, $message->send_after);
    }

    /**
     * The technician tapped a button that opens the phone's messages app: keep a trace on the job.
     */
    public function opened(Request $request, ServiceJob $job, Messenger $messenger, ReviewRequests $reviews, MarkEstimateSent $markSent): RedirectResponse
    {
        // Document links and review requests go with the right to send those documents (e.g. an Office member who
        // only invoices); every other text needs field work on the job.
        $ability = match ($request->input('kind')) {
            MessageKind::EstimateLink->value => 'estimate',
            MessageKind::InvoiceLink->value, MessageKind::ReviewRequest->value => 'invoice',
            default => 'work',
        };
        abort_unless(Gate::allows($ability, $job) || Gate::allows('work', $job), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_map(fn (MessageKind $k) => $k->value, MessageKind::templated()))],
            'to' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:1600'],
        ]);
        $kind = MessageKind::from($data['kind']);

        if ($kind === MessageKind::ReviewRequest) {
            $reviews->sentFromPhone($job, $data['to'], $data['body'], $request->user());
        } else {
            $messenger->openedOnPhone($kind, $job->customer, $job, $data['to'], $data['body'], $request->user());
        }
        if ($kind === MessageKind::EstimateLink) {
            $markSent->handle($job, $request->user());
        }

        return back();
    }

    /**
     * Automatic or Off mode: send the Google review request with the review link of the location the technician chose.
     */
    public function reviewRequest(Request $request, ServiceJob $job, ReviewRequests $reviews): RedirectResponse
    {
        abort_unless(Gate::allows('invoice', $job) || Gate::allows('work', $job), 403);
        abort_if(currentCompany()->sms_mode === SmsMode::TechnicianPhone, 404);

        $id = $request->validate(['location_id' => ['required', 'integer']])['location_id'];
        $location = GoogleProfile::query()->whereKey($id)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $job->brand_id))->first();
        if ($location === null) {
            throw ValidationException::withMessages(['location_id' => __('reviews.choose_location')]);
        }
        $message = $reviews->sendWithLink($job, $location->review_url, $request->user());

        return $this->result($message->status, $message->status_reason, $message->send_after);
    }

    public static function result(string $status, ?string $reason, mixed $sendAfter = null): RedirectResponse
    {
        $toast = match (true) {
            in_array($status, ['blocked', 'failed'], true) => ['type' => 'error', 'message' => __('messages.sms_blocked', ['reason' => $reason])],
            $sendAfter !== null && $sendAfter->isFuture() => ['type' => 'success', 'message' => __('messages.sms_queued', [
                'time' => MessageContext::date($sendAfter).' '.MessageContext::time($sendAfter),
            ])],
            default => ['type' => 'success', 'message' => __('messages.sms_sent')],
        };

        Inertia::flash('toast', $toast);

        return back();
    }
}
