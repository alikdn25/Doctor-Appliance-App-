<?php

namespace App\Support\Push;

use App\Messaging\SmsThreads;
use App\Models\Message;
use App\Models\PushAction;
use App\Models\User;
use App\Services\AI\Sms\SmsActions;
use Illuminate\Support\Facades\Gate;

/**
 * What the buttons of push notifications do on the server (owner's task of November 19). Each runs in the company of
 * the notification, as the person who tapped it, with the usual permission checks.
 */
class PushOperations
{
    public static function register(): void
    {
        // The AI's main action under an incoming text: move, change the time, cancel, or book.
        PushActions::define('sms_action', function (PushAction $action, array $button, User $user): string {
            $message = Message::query()->findOrFail((int) $button['message_id']);
            Gate::forUser($user)->authorize('viewAny', Message::class);

            return app(SmsActions::class)->runFromPush($message, (int) $button['index'], $user);
        });

        // "Not waiting" on a "customer is quiet" reminder.
        PushActions::define('not_waiting', function (PushAction $action, array $button, User $user): string {
            $message = Message::query()->findOrFail((int) $button['message_id']);
            Gate::forUser($user)->authorize('viewAny', Message::class);
            SmsThreads::notWaiting($message);

            return __('automation.quiet.not_waiting_done');
        });
    }
}
