<?php

namespace App\Notifications\Channels;

use App\Models\FcmToken;
use App\Models\User;
use App\Support\Push\FcmClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push to the Android app (owner's task of November 22): the same notification as Web Push (toWebPush: title, body,
 * url, tag, buttons), sent as a Firebase data message to every app install of the person, once per install. Web Push
 * keeps going to the browsers / PWA, so a person signed in both in the app and in a browser gets it once on each.
 * Needs FCM_SERVICE_ACCOUNT_BASE64 in .env; without it nothing is sent.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    public static function configured(): bool
    {
        return FcmClient::configured();
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! self::configured() || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $tokens = FcmToken::query()->where('user_id', $notifiable->id)->get();

        if ($tokens->isEmpty()) {
            return;
        }

        $data = self::data($notification->toWebPush($notifiable));

        foreach ($tokens->unique('token_hash') as $token) {
            try {
                if ($this->client->send($token->token, $data) === 'unregistered') {
                    // The app was removed or signed out on that phone: forget it.
                    $token->delete();
                }
            } catch (Throwable $e) {
                Log::warning('FCM push failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * FCM data values are strings: the buttons go as JSON.
     *
     * @param  array<string, mixed>  $push
     * @return array<string, string>
     */
    public static function data(array $push): array
    {
        return array_filter([
            'title' => (string) ($push['title'] ?? ''),
            'body' => (string) ($push['body'] ?? ''),
            'url' => (string) ($push['url'] ?? url('/')),
            'tag' => (string) ($push['tag'] ?? ''),
            'actions' => isset($push['actions']) ? (string) json_encode(array_values((array) $push['actions']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            'action_url' => (string) ($push['action_url'] ?? ''),
        ], fn (string $value) => $value !== '');
    }
}
