<?php

namespace App\Http\Controllers;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Enums\UserRole;
use App\Http\Controllers\Jobs\JobMessageController;
use App\Messaging\Messenger;
use App\Messaging\SmsInbox;
use App\Messaging\SmsThreads;
use App\Models\Message;
use App\Services\AI\AiUnavailable;
use App\Services\AI\Sms\Translator;
use Illuminate\Http\JsonResponse;
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
            'nudge' => ['nullable', 'boolean'], 'call' => ['nullable', 'boolean'],
        ]);
        $search = trim($data['search'] ?? '');
        $unread = $request->boolean('unread');
        $phone = $data['phone'] ?? null;

        return Inertia::render('messages/index', [
            'threads' => $inbox->threads($request->user(), $search, $unread),
            'conversation' => $phone ? $inbox->conversation($request->user(), $phone, $request->boolean('nudge')) : null,
            // "Call" on a push notification opens the chat and the phone's dialer.
            'call' => $phone !== null && $request->boolean('call'),
            'filters' => ['search' => $search, 'unread' => $unread],
            // The empty list says why it is empty (UX-REDESIGN §10.5); the Owner gets a link to the settings.
            'smsMode' => currentCompany()->sms_mode->value,
            'canSetUpMessaging' => $request->user()->hasRole(UserRole::Owner),
        ]);
    }

    /**
     * Older messages of a conversation, loaded when the chat is scrolled up.
     */
    public function older(Request $request, SmsInbox $inbox): JsonResponse
    {
        Gate::authorize('viewAny', Message::class);
        $data = $request->validate([
            'phone' => ['required', 'string', self::PHONE_RULE],
            'before' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['messages' => $inbox->older($request->user(), $data['phone'], (int) $data['before'])]);
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

    /**
     * "Not waiting for a reply" on one of our texts: it no longer starts a "customer is quiet" reminder.
     */
    public function notWaiting(Message $message): RedirectResponse
    {
        Gate::authorize('viewAny', Message::class);
        abort_unless($message->direction === Message::OUTBOUND, 404);
        abort_if(SmsInbox::hiddenFrom(request()->user(), (string) $message->to), 404);
        SmsThreads::notWaiting($message);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.quiet.not_waiting_done')]);

        return back();
    }

    /**
     * Russian in the reply box → a short, polite English SMS put back into the box (owner's task of November 19). The
     * person sends it; English text comes back unchanged without an AI call.
     */
    public function translate(Request $request, Translator $translator): JsonResponse
    {
        Gate::authorize('create', Message::class);
        $data = $request->validate(['text' => ['required', 'string', 'max:1600']]);
        $company = currentCompany();

        if (! $company->translate_to_english || ! Translator::isRussian($data['text'])) {
            return response()->json(['text' => $data['text'], 'translated' => false]);
        }

        try {
            return response()->json(['text' => $translator->toEnglish($data['text'], $company, $request->user()), 'translated' => true]);
        } catch (AiUnavailable) {
            return response()->json(['message' => __('answers.translate.failed')], 422);
        }
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
