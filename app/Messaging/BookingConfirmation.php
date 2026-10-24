<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Enums\VisitStatus;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * The booking confirmation (the day, arrival window and address) to the customer — the one who pays — sent by itself
 * when a booking is finished, and again on "Resend confirmation" or "Notify client?".
 *
 * How it goes follows the company's SMS setting: Automatic texts from the company number; From technician's phone
 * opens the phone's messages app with the text ready (the page does that, within the tap); when a text cannot go
 * (no phone, opted out, no SMS account) it is emailed instead, if the customer has an email address. Off sends
 * nothing.
 */
class BookingConfirmation
{
    public const SMS = 'sms';

    public const PHONE = 'phone';

    public const EMAIL = 'email';

    public function __construct(private readonly Messenger $messenger) {}

    public function body(ServiceJob $job): string
    {
        // The next visit that is still to come, else the latest one.
        $visit = JobVisit::query()->where('service_job_id', $job->id)
            ->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::OnTheWay->value])
            ->orderBy('scheduled_start')->first()
            ?? JobVisit::query()->where('service_job_id', $job->id)->orderByDesc('scheduled_start')->first();

        return MessageTemplates::render(currentCompany(), MessageKind::BookingConfirmation, MessageContext::for($job->customer, $job, $visit));
    }

    /**
     * How the confirmation would go now: the channel (null = it does not go), where to and, when it does not go
     * the usual way, why.
     *
     * @return array{channel: string|null, to: string|null, reason: string|null}
     */
    public function plan(ServiceJob $job): array
    {
        $job->loadMissing('customer');
        $customer = $job->customer;
        $mode = currentCompany()->sms_mode;

        if ($mode === SmsMode::Off) {
            return ['channel' => null, 'to' => null, 'reason' => __('jobs.confirmation.sms_off')];
        }

        $phone = $this->messenger->mobile($customer);
        $reason = match ($mode) {
            SmsMode::Automatic => $this->messenger->smsBlockedReason($customer),
            default => match (true) {
                $phone === null => __('messages.blocked.no_phone'),
                $phone->sms_opted_out_at !== null => __('messages.blocked.opted_out'),
                default => null,
            },
        };

        if ($reason === null) {
            return ['channel' => $mode === SmsMode::Automatic ? self::SMS : self::PHONE, 'to' => $phone?->number, 'reason' => null];
        }

        $email = $customer->primaryEmail()->value('email');

        return $email === null
            ? ['channel' => null, 'to' => null, 'reason' => $reason]
            : ['channel' => self::EMAIL, 'to' => $email, 'reason' => $reason];
    }

    /**
     * Sends it by the plan. A text from the technician's phone is left to the page (it opens the messages app), so
     * the plan comes back with the text for it.
     *
     * @return array{channel: string|null, to: string|null, reason: string|null, body: string, status: string|null, send_after: string|null}
     */
    public function send(ServiceJob $job, ?User $user): array
    {
        $job->loadMissing(['customer', 'brand', 'property']);
        $plan = $this->plan($job);
        $body = $this->body($job);

        $message = match ($plan['channel']) {
            self::SMS => $this->messenger->sms(MessageKind::BookingConfirmation, $job->customer, $job, $body, $user),
            self::EMAIL => $this->messenger->emailMessage(MessageKind::BookingConfirmation, $job->customer, $job, $body, $user,
                __('messages.email_subject.booking_confirmation', ['brand' => $job->brand?->name ?? currentCompany()->name])),
            default => null,
        };

        return [
            ...$plan,
            'reason' => $message?->status === 'blocked' ? $message->status_reason : $plan['reason'],
            'body' => $body,
            'status' => $message?->status,
            'send_after' => $message instanceof Message && $message->send_after?->isFuture()
                ? MessageContext::date($message->send_after).' '.MessageContext::time($message->send_after)
                : null,
        ];
    }
}
