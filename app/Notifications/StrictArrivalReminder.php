<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds the technician to leave in time for a visit with a strict arrival time.
 */
class StrictArrivalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{number: string, customer: string, address: string, window: string, time: string}  $details
     */
    public function __construct(public readonly array $details, public readonly string $url) {}

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
            ->subject(__('jobs.strict.reminder_subject', $this->details))
            ->line(__('jobs.strict.reminder', $this->details))
            ->action(__('notifications.estimate_decided.action_job'), $this->url);
    }
}
