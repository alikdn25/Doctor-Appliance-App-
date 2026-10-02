<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the office that a customer approved or declined an estimate online. Everything is formatted when it is
 * created (in the company's context), so the queued mail needs no tenant.
 */
class EstimateDecided extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{number: string, customer: string, total: string, deposit: string|null, signer: string|null, reason: string|null, brand: string}  $details
     */
    public function __construct(
        public readonly bool $approved,
        public readonly array $details,
        public readonly string $url,
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
        $key = $this->approved ? 'approved' : 'declined';
        $replace = array_map(fn (?string $value) => (string) $value, $this->details);
        $mail = (new MailMessage)
            ->subject(__("notifications.estimate_{$key}.subject", $replace))
            ->greeting(__('notifications.estimate_decided.greeting', ['name' => $notifiable->name]))
            ->line(__("notifications.estimate_{$key}.line", $replace));

        if ($this->approved && $this->details['deposit'] !== null) {
            $mail->line(__('notifications.estimate_approved.deposit', $replace));
        }

        if (! $this->approved && $this->details['reason'] !== null) {
            $mail->line(__('notifications.estimate_declined.reason', $replace));
        }

        return $mail->action(__('notifications.estimate_decided.action'), $this->url);
    }
}
