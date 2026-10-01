<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a brand-new user: contains a link to set their password.
 */
class MemberInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject(__('notifications.invited.subject', ['company' => $this->companyName]))
            ->greeting(__('notifications.invited.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.invited.line', ['company' => $this->companyName]))
            ->action(__('notifications.invited.action'), $url)
            ->line(__('notifications.invited.expires', [
                'count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]));
    }
}
