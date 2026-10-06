<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Messaging\Messenger;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Texts from a job: an SMS typed on the job (Automatic mode), the record that a text was opened on the
 * technician's phone (sms: link).
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
    public function opened(Request $request, ServiceJob $job, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('work', $job);

        $data = $request->validate([
            // Review requests go only through the prompt after an invoice is sent.
            'kind' => ['required', Rule::in(array_map(fn (MessageKind $k) => $k->value, array_filter(MessageKind::templated(), fn (MessageKind $k) => $k !== MessageKind::ReviewRequest)))],
            'to' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:1600'],
        ]);
        $messenger->openedOnPhone(MessageKind::from($data['kind']), $job->customer, $job, $data['to'], $data['body'], $request->user());

        return back();
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
