<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Push notifications to the phones and browsers a user turned them on for (Settings → Notifications).
 * Needs the VAPID keys in .env (WEBPUSH_PUBLIC_KEY, WEBPUSH_PRIVATE_KEY); without them nothing is sent.
 * A notification provides toWebPush(): ['title' => ..., 'body' => ..., 'url' => ...].
 */
class WebPushChannel
{
    public static function configured(): bool
    {
        return filled(config('webpush.public_key')) && filled(config('webpush.private_key'));
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! self::configured() || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $notifiable->id)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = json_encode($notification->toWebPush($notifiable), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $push = new WebPush(['VAPID' => [
                'subject' => (string) config('webpush.subject'),
                'publicKey' => (string) config('webpush.public_key'),
                'privateKey' => (string) config('webpush.private_key'),
            ]]);

            foreach ($subscriptions as $subscription) {
                $push->queueNotification(Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                ]), $payload);
            }

            foreach ($push->flush() as $report) {
                // The browser dropped the subscription (app removed, permission revoked): forget it.
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::query()->where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                }
            }
        } catch (Throwable $e) {
            Log::warning('Web push failed', ['error' => $e->getMessage()]);
        }
    }
}
