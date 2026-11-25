<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Mail\BookingConfirmationMail;
use App\Models\Company;
use App\Models\Message;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The booking confirmation to the customer (owner's task of November 25): after every new booking (Finish booking,
 * quick booking, the assistant, a booking from an SMS), on "Resend confirmation" and after a reschedule with "Notify
 * client". It goes by SMS and by email (when the customer has one), as Settings → Company → Booking confirmation
 * says; Save as draft sends nothing.
 *
 * The SMS goes the company's SMS way: Automatic texts from the company number (it shows in the customer's SMS chat);
 * From technician's phone opens the phone's messages app with the text ready (the page does that, within the tap);
 * Off sends no SMS. The result is kept on the job ("Confirmation sent: SMS ✓, Email ✓, …" or why not).
 */
class BookingConfirmation
{
    public const SMS = 'sms';

    public const PHONE = 'phone';

    public const EMAIL = 'email';

    public function __construct(private readonly Messenger $messenger) {}

    /** The SMS text for the job. */
    public function body(ServiceJob $job): string
    {
        $company = currentCompany();
        $settings = BookingConfirmationTexts::settings($company);

        return BookingConfirmationTexts::render($company, $settings['sms_text'], BookingConfirmationTexts::variables($company, $job), $settings);
    }

    /**
     * How the SMS would go now: the channel (null = no SMS), where to and, when it does not go, why.
     *
     * @return array{channel: string|null, to: string|null, reason: string|null}
     */
    public function plan(ServiceJob $job): array
    {
        $job->loadMissing('customer');
        $customer = $job->customer;
        $company = currentCompany();
        $settings = BookingConfirmationTexts::settings($company);
        $email = $customer->primaryEmail()->value('email');
        $emailPlan = $settings['by_email'] && $email !== null ? ['channel' => self::EMAIL, 'to' => $email] : null;

        if (! $settings['enabled']) {
            return ['channel' => null, 'to' => null, 'reason' => __('booking_confirmation.status.off')];
        }

        $phone = $this->messenger->mobile($customer);
        $reason = match (true) {
            ! $settings['by_sms'] => null,
            $company->sms_mode === SmsMode::Off => __('jobs.confirmation.sms_off'),
            $company->sms_mode === SmsMode::Automatic => $this->messenger->smsBlockedReason($customer),
            $phone === null => __('messages.blocked.no_phone'),
            $phone->sms_opted_out_at !== null => __('messages.blocked.opted_out'),
            default => null,
        };

        if ($settings['by_sms'] && $reason === null) {
            return ['channel' => $company->sms_mode === SmsMode::Automatic ? self::SMS : self::PHONE, 'to' => $phone?->number, 'reason' => null];
        }
        if ($emailPlan !== null) {
            return [...$emailPlan, 'reason' => $reason];
        }
        if ($phone === null && $email === null) {
            return ['channel' => null, 'to' => null, 'reason' => __('booking_confirmation.status.no_contact')];
        }

        return ['channel' => null, 'to' => null, 'reason' => $reason ?? __('messages.blocked.no_email')];
    }

    /**
     * Sends it now: the SMS and the email, each when switched on. The result is saved on the job.
     *
     * @return array{channel: string|null, to: string|null, reason: string|null, body: string, status: string|null, send_after: string|null, sms: string|null, email: string|null, email_to: string|null}
     */
    public function send(ServiceJob $job, ?User $user): array
    {
        $job->loadMissing(['customer', 'brand', 'property']);
        $company = currentCompany();
        $settings = BookingConfirmationTexts::settings($company);
        $variables = BookingConfirmationTexts::variables($company, $job);
        $body = BookingConfirmationTexts::render($company, $settings['sms_text'], $variables, $settings);
        $customer = $job->customer;

        if (! $settings['enabled']) {
            $reason = (string) __('booking_confirmation.status.off');
            $this->remember($job, 'off', 'off', $reason);

            return ['channel' => null, 'to' => null, 'reason' => $reason, 'body' => $body, 'status' => null, 'send_after' => null, 'sms' => 'off', 'email' => 'off', 'email_to' => null];
        }

        $reasons = [];

        // SMS
        $smsStatus = 'off';
        $sms = null;
        $phone = $this->messenger->mobile($customer);
        $channel = null;
        $to = null;
        if ($settings['by_sms']) {
            $blocked = $company->sms_mode === SmsMode::Automatic ? $this->messenger->smsBlockedReason($customer) : null;
            if ($company->sms_mode === SmsMode::Automatic && $blocked !== null) {
                // Not even tried (no phone, opted out, no SMS number): the reason is shown on the job.
                $smsStatus = $phone === null ? 'none' : 'failed';
                $reasons[] = __('booking_confirmation.status.sms').': '.$blocked;
            } elseif ($company->sms_mode === SmsMode::Automatic) {
                $sms = $this->messenger->sms(MessageKind::BookingConfirmation, $customer, $job, $body, $user);
                $smsStatus = $sms->status === 'blocked' ? 'failed' : 'sent';
                if ($sms->status === 'blocked') {
                    $reasons[] = __('booking_confirmation.status.sms').': '.$sms->status_reason;
                } else {
                    $channel = self::SMS;
                    $to = $sms->to;
                }
            } elseif ($company->sms_mode === SmsMode::TechnicianPhone && $phone !== null && $phone->sms_opted_out_at === null) {
                // The page opens the phone's messages app with the text.
                $smsStatus = 'phone';
                $channel = self::PHONE;
                $to = $phone->number;
            } else {
                $smsStatus = $phone === null ? 'none' : 'failed';
                $reasons[] = __('booking_confirmation.status.sms').': '.($phone === null ? __('messages.blocked.no_phone')
                    : ($company->sms_mode === SmsMode::Off ? __('jobs.confirmation.sms_off') : __('messages.blocked.opted_out')));
            }
        }

        // Email
        $emailStatus = 'off';
        $emailTo = null;
        if ($settings['by_email']) {
            $emailTo = $customer->primaryEmail()->value('email');
            if ($emailTo === null) {
                $emailStatus = 'none';
            } else {
                $emailBody = BookingConfirmationTexts::render($company, $settings['email_text'], $variables, $settings);
                $subject = BookingConfirmationTexts::render($company, $settings['email_subject'], $variables, $settings);
                $message = Message::query()->create([
                    'customer_id' => $customer->id,
                    'service_job_id' => $job->id,
                    'direction' => Message::OUTBOUND,
                    'channel' => Message::EMAIL,
                    'kind' => MessageKind::BookingConfirmation,
                    'to' => $emailTo,
                    'body' => $emailBody,
                    'status' => 'sent',
                    'sent_at' => now(),
                    'user_id' => $user?->id,
                ]);
                try {
                    Mail::to($emailTo)->queue(new BookingConfirmationMail($job->id, $company->id, $subject, $emailBody, self::details($company, $variables, $settings)));
                    $emailStatus = 'sent';
                    $channel ??= self::EMAIL;
                    $to ??= $emailTo;
                } catch (\Throwable $e) {
                    report($e);
                    $message->forceFill(['status' => 'failed', 'status_reason' => __('booking_confirmation.status.email_failed')])->save();
                    $emailStatus = 'failed';
                    $reasons[] = __('booking_confirmation.status.email').': '.__('booking_confirmation.status.email_failed');
                }
            }
        }

        if ($channel === null && $smsStatus !== 'sent' && $emailStatus !== 'sent') {
            if ($phone === null && $emailTo === null && ($settings['by_sms'] || $settings['by_email'])) {
                $reasons = [__('booking_confirmation.status.no_contact')];
            } elseif ($reasons === []) {
                $reasons[] = __('booking_confirmation.status.no_channel');
            }
        }

        $reason = $reasons === [] ? null : implode('; ', $reasons);
        $this->remember($job, $smsStatus, $emailStatus, $channel === null ? $reason : ($smsStatus === 'failed' || $emailStatus === 'failed' ? $reason : null));

        return [
            'channel' => $channel,
            'to' => $to,
            'reason' => $reason,
            'body' => $body,
            'status' => $sms?->status ?? ($channel === self::EMAIL ? 'sent' : null),
            'send_after' => $sms instanceof Message && $sms->status !== 'blocked' && $sms->send_after?->isFuture()
                ? MessageContext::date($sms->send_after).' '.MessageContext::time($sms->send_after)
                : null,
            'sms' => $smsStatus,
            'email' => $emailStatus,
            'email_to' => $emailStatus === 'sent' ? $emailTo : null,
        ];
    }

    /**
     * The appointment block of the email: when, where, what and the company's phone.
     *
     * @param  array<string, string|null>  $variables
     * @param  array<string, mixed>  $settings
     * @return array{when: string, address: string|null, service: string|null, phone: string|null, policy: string}
     */
    public static function details(Company $company, array $variables, array $settings): array
    {
        $when = BookingConfirmationTexts::render($company, '{day}, {date}, {time_window}', $variables, $settings);
        if (blank($variables['date'] ?? null)) {
            $when = ucfirst((string) __('booking_confirmation.no_date'));
        }

        return [
            'when' => ltrim($when, ', '),
            'address' => $variables['address'] ?? null,
            'service' => filled($variables['appliance'] ?? null) ? ucfirst((string) __('booking_confirmation.email.service_value', ['appliance' => $variables['appliance']])) : null,
            'phone' => $variables['company_phone'] ?? null,
            'policy' => BookingConfirmationTexts::render($company, '{cancellation_policy}', $variables, $settings),
        ];
    }

    private function remember(ServiceJob $job, ?string $sms, ?string $email, ?string $error): void
    {
        $job->forceFill([
            'confirmation_sent_at' => now(),
            'confirmation_sms_status' => $sms,
            'confirmation_email_status' => $email,
            'confirmation_error' => $error === null ? null : mb_substr($error, 0, 500),
        ])->saveQuietly();
    }
}
