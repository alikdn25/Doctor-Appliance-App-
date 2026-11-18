<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Support\AccountEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short notice for the Owner or the platform owner: a failed backup, a sudden drop in records, a booking made by
 * the website bot. It goes to the in-app list (bell), to push on the phones the person turned it on for, and by
 * email once delivery is enabled. Server alerts can also go to Telegram (Alerts::platform). Texts are translated
 * when the alert is created, in the company's context.
 */
class AppAlert extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        // Off when the same news already goes by its own email (an estimate decision).
        public readonly bool $mail = true,
        // Buttons under the push notification (PushActions): [{action, title, url}], and where a tap that runs on the
        // server is sent ("__action__" is replaced by the button's key).
        public readonly array $actions = [],
        public readonly ?string $actionUrl = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return [TelegramChannel::class];
        }

        return array_values(array_filter([
            'database',
            WebPushChannel::class,
            $this->mail && AccountEmail::deliveryEnabled() ? 'mail' : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_filter([
            'kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url,
            'actions' => $this->actions !== [] ? $this->actions : null,
            'action_url' => $this->actionUrl,
        ], fn ($value, $key) => $value !== null || in_array($key, ['url'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        return array_filter([
            'title' => $this->title, 'body' => $this->body, 'url' => $this->url,
            // A notification with buttons gets its own tag, so two of them do not replace each other.
            'tag' => $this->actions !== [] ? $this->kind.':'.md5((string) $this->actionUrl) : $this->kind,
            'actions' => $this->actions !== [] ? $this->actions : null,
            'action_url' => $this->actionUrl,
        ], fn ($value, $key) => $value !== null || $key === 'url', ARRAY_FILTER_USE_BOTH);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->body);

        return $this->url ? $mail->action(__('notifications.alert.open'), $this->url) : $mail;
    }

    public function toTelegram(object $notifiable): string
    {
        return trim("{$this->title}\n{$this->body}\n".($this->url ?? ''));
    }
}
