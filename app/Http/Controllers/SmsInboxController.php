<?php

namespace App\Http\Controllers;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Jobs\JobMessageController;
use App\Messaging\Messenger;
use App\Messaging\SmsInbox;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SmsInboxController extends Controller
{
    private const PHONE_RULE = 'regex:/^\+[1-9]\d{5,14}$/';

    public function index(Request $request, SmsInbox $inbox): Response
    {
        Gate::authorize('viewAny', Message::class);
        $data = $request->validate([
            'phone' => ['nullable', 'string', self::PHONE_RULE],
            'search' => ['nullable', 'string', 'max:100'], 'unread' => ['nullable', 'boolean'],
        ]);
        $search = trim($data['search'] ?? '');
        $unread = $request->boolean('unread');
        $phone = $data['phone'] ?? null;

        return Inertia::render('messages/index', [
            'threads' => $inbox->threads($request->user(), $search, $unread),
            'conversation' => $phone ? $inbox->conversation($request->user(), $phone) : null,
            'filters' => ['search' => $search, 'unread' => $unread],
        ]);
    }

    public function read(Request $request, SmsInbox $inbox): RedirectResponse
    {
        Gate::authorize('viewAny', Message::class);
        $data = $request->validate([
            'phone' => ['required', 'string', self::PHONE_RULE],
            'message_ids' => ['required', 'array', 'min:1', 'max:50'],
            'message_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $inbox->markRead($request->user(), $data['phone'], $data['message_ids']);

        return back();
    }

    public function send(Request $request, SmsInbox $inbox, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('create', Message::class);
        $data = $request->validate([
            'phone' => ['required', 'string', self::PHONE_RULE],
            'body' => ['required', 'string', 'max:1600'],
        ]);
        // Any number can be texted: an existing conversation, a customer's phone or a new lead.
        abort_if(SmsInbox::hiddenFrom($request->user(), $data['phone']), 404);
        if (currentCompany()->sms_mode !== SmsMode::Automatic) {
            throw ValidationException::withMessages(['body' => __('messages.inbox.automatic_required')]);
        }
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('validation.required', ['attribute' => __('messages.inbox.reply')])]);
        }
        $recipient = $inbox->recipient($request->user(), $data['phone']);
        if ($recipient !== null) {
            $message = $messenger->sms(MessageKind::General, $recipient->customer,
                $inbox->job($request->user(), $data['phone'], $recipient), $body, $request->user(), recipient: $recipient);
        } elseif ($inbox->unlinked($request->user(), $data['phone'])) {
            $message = $messenger->smsToNumber($data['phone'], $body, $request->user());
        } else {
            throw ValidationException::withMessages(['body' => __('messages.inbox.link_customer')]);
        }

        return JobMessageController::result($message->status, $message->status_reason, $message->send_after);
    }
}
