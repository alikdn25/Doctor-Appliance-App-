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
            AccountEmail::deliveryEnabled() ? 'mail' : null,
        ]));
    }

    /**
     * @return array{kind: string, title: string, body: string, url: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }

    /**
     * @return array{title: string, body: string, url: string|null, tag: string}
     */
    public function toWebPush(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'tag' => $this->kind];
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
