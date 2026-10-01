<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an existing user who was added to another company.
 */
class AddedToCompany extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $companyName) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.added.subject', ['company' => $this->companyName]))
            ->greeting(__('notifications.added.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.added.line', ['company' => $this->companyName]))
            ->action(__('notifications.added.action'), route('login'));
    }
}
