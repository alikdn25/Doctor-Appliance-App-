<?php

namespace App\Http\Controllers\Billing;

use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Jobs\JobMessageController;
use App\Messaging\ReviewRequests;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Message;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Send Google Review request?" after an invoice is sent (SPEC §8): the review link of the chosen location goes to
 * the chosen phone as a separate SMS. Everyone who can send the invoice can send it.
 */
class InvoiceReviewRequestController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, ReviewRequests $reviews): RedirectResponse
    {
        Gate::authorize('view', $invoice);
        abort_if($invoice->isVoid(), 422);

        $data = $request->validate([
            'google_profile_id' => ['required', 'integer', Rule::exists('google_profiles', 'id')->where('company_id', currentCompany()->id)],
            'phone' => ['required', 'string', 'max:32'],
        ], [], ['google_profile_id' => __('reviews.prompt.location'), 'phone' => __('reviews.prompt.phone')]);

        if (currentCompany()->sms_mode === SmsMode::Off) {
            throw ValidationException::withMessages(['phone' => __('reviews.prompt.sms_off')]);
        }

        if (! PhoneNumber::isPossible($data['phone'])) {
            throw ValidationException::withMessages(['phone' => __('reviews.prompt.invalid_phone')]);
        }

        $message = $reviews->send($invoice, GoogleProfile::query()->findOrFail($data['google_profile_id']), PhoneNumber::normalize($data['phone']), $request->user());

        // On the technician's phone the messages app opens in the browser; nothing to report here.
        if ($message->channel !== Message::SMS) {
            return back();
        }

        return JobMessageController::result($message->status, $message->status_reason, $message->send_after);
    }
}
