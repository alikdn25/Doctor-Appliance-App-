<?php

namespace App\Http\Controllers\Customers;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Jobs\JobMessageController;
use App\Messaging\Messenger;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * "Send SMS" on the customer profile, outside any job: customers often ignore calls from unknown numbers, a text
 * gets through. Owner, or Office with the SMS inbox permission. Automatic mode sends from the company number;
 * technician's-phone mode records the text opened in the phone's messages app.
 */
class CustomerMessageController extends Controller
{
    public function sms(Request $request, Customer $customer, Messenger $messenger): RedirectResponse
    {
        $this->authorizeTexting($customer);
        abort_unless(currentCompany()->sms_mode === SmsMode::Automatic, 404);

        $data = $request->validate([
            'phone_id' => ['required', 'integer', $this->phoneRule($customer)],
            'body' => ['required', 'string', 'max:1000'],
        ]);
        $phone = CustomerPhone::query()->findOrFail($data['phone_id']);
        $message = $messenger->sms(MessageKind::General, $customer, null, trim($data['body']), $request->user(), recipient: $phone);

        return JobMessageController::result($message->status, $message->status_reason, $message->send_after);
    }

    public function opened(Request $request, Customer $customer, Messenger $messenger): RedirectResponse
    {
        $this->authorizeTexting($customer);
        abort_unless(currentCompany()->sms_mode === SmsMode::TechnicianPhone, 404);

        $data = $request->validate([
            'phone_id' => ['required', 'integer', $this->phoneRule($customer)],
            'body' => ['required', 'string', 'max:1600'],
        ]);
        $phone = CustomerPhone::query()->findOrFail($data['phone_id']);
        $messenger->openedOnPhone(MessageKind::General, $customer, null, $phone->number, $data['body'], $request->user());

        return back();
    }

    private function authorizeTexting(Customer $customer): void
    {
        Gate::authorize('view', $customer);
        Gate::authorize('create', Message::class);
    }

    private function phoneRule(Customer $customer): Exists
    {
        return Rule::exists('customer_phones', 'id')->where('customer_id', $customer->id)->where('company_id', currentCompany()->id);
    }
}
