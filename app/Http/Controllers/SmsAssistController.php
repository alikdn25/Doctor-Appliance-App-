<?php

namespace App\Http\Controllers;

use App\Messaging\SmsInbox;
use App\Messaging\SmsThreads;
use App\Models\Message;
use App\Services\AI\Sms\SmsActions;
use App\Support\PrivateMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The AI card under an incoming text in the SMS chat (owner's task of November 18): Confirm or Dismiss a proposed
 * action, attach a rating plate read from a photo, "Done" without a reply, and the MMS photos themselves.
 * Office only (the SMS Inbox); nothing runs without the person's button.
 */
class SmsAssistController extends Controller
{
    public function confirm(Request $request, Message $message, int $index, SmsActions $actions): JsonResponse
    {
        $this->authorizeInbound($request, $message);
        $result = $actions->confirm($message, $index, $request->user());

        return response()->json([...$result, 'card' => SmsActions::card($message->refresh())]);
    }

    public function dismiss(Request $request, Message $message, int $index, SmsActions $actions): JsonResponse
    {
        $this->authorizeInbound($request, $message);
        $actions->dismiss($message, $index);

        return response()->json(['card' => SmsActions::card($message->refresh())]);
    }

    public function plate(Request $request, Message $message, SmsActions $actions): JsonResponse
    {
        $this->authorizeInbound($request, $message);

        if ($request->boolean('dismiss')) {
            $actions->dismissPlate($message);

            return response()->json(['card' => SmsActions::card($message->refresh())]);
        }

        $text = $actions->attachPlate($message, $request->user());

        return response()->json(['message' => $text, 'card' => SmsActions::card($message->refresh())]);
    }

    /**
     * "Done": handled without a reply; the reminders stop.
     */
    public function done(Request $request, SmsThreads $threads): RedirectResponse
    {
        Gate::authorize('viewAny', Message::class);
        $phone = $request->validate(['phone' => ['required', 'string', 'regex:/^\+[1-9]\d{5,14}$/']])['phone'];
        abort_if(SmsInbox::hiddenFrom($request->user(), $phone), 404);

        $threads->done($phone, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.inbox.done_saved')]);

        return back();
    }

    public function media(Request $request, Message $message, int $index): StreamedResponse
    {
        $this->authorizeInbound($request, $message);
        $photo = ((array) $message->media)[$index] ?? null;
        abort_unless(is_array($photo) && isset($photo['path']), 404);

        return PrivateMedia::response($photo['path']);
    }

    private function authorizeInbound(Request $request, Message $message): void
    {
        Gate::authorize('viewAny', Message::class);
        abort_unless($message->direction === Message::INBOUND
            && SmsInbox::messages($request->user())->whereKey($message->id)->exists(), 404);
    }
}
