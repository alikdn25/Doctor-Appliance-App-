<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server alerts to the platform owner's Telegram (instead of email while mail is not set up): a bot token and a
 * chat id in .env (OPS_TELEGRAM_BOT_TOKEN, OPS_TELEGRAM_CHAT_ID). A notification provides toTelegram(): string.
 */
class TelegramChannel
{
    public static function configured(): bool
    {
        return filled(config('backup.alerts.telegram_bot_token')) && filled(config('backup.alerts.telegram_chat_id'));
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::configured() || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        try {
            Http::timeout(10)->asJson()->post(
                'https://api.telegram.org/bot'.config('backup.alerts.telegram_bot_token').'/sendMessage',
                ['chat_id' => config('backup.alerts.telegram_chat_id'), 'text' => $notification->toTelegram($notifiable)],
            )->throw();
        } catch (Throwable $e) {
            // The token is part of the URL: never log the exception message as is.
            Log::warning('Telegram alert failed', ['status' => method_exists($e, 'getCode') ? $e->getCode() : null]);
        }
    }
}
