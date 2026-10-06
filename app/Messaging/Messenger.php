<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Jobs\DeliverSmsMessage;
use App\Mail\CustomerMessageMail;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Message;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use App\Models\User;
use App\Sms\SmsProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Sends a customer message by the company's SMS mode (SPEC §7.7) and records it on the customer and job:
 *
 * - Automatic: SMS from the company number (after the quiet hours). When an SMS cannot go (no mobile, the customer
 *   replied STOP, US registration not approved, SMS not set up) it is recorded as "not sent" with the reason and the
 *   message goes by email instead, if there is an address.
 * - From technician's phone / Off: automated messages go by email.
 *
 * Must run in a tenant context.
 */
class Messenger
{
    public function __construct(private readonly SmsProvider $sms) {}

    public function send(MessageKind $kind, Customer $customer, ?ServiceJob $job, string $body, ?User $user = null, ?string $emailSubject = null, bool $afterCommit = false): Message
    {
        $company = currentCompany();

        if ($company->sms_mode === SmsMode::Automatic) {
            $sms = $this->sms($kind, $customer, $job, $body, $user, $afterCommit);

            if ($sms->status !== 'blocked') {
                return $sms;
            }

            $email = $this->email($kind, $customer, $job, $body, $user, $emailSubject, failIfMissing: false, afterCommit: $afterCommit);

            return $email ?? $sms;
        }

        return $this->email($kind, $customer, $job, $body, $user, $emailSubject, failIfMissing: true, afterCommit: $afterCommit);
    }

    /**
     * An SMS through the platform provider, or a "not sent" record saying why.
     */
    public function sms(MessageKind $kind, Customer $customer, ?ServiceJob $job, string $body, ?User $user = null, bool $afterCommit = false, ?CustomerPhone $recipient = null): Message
    {
        $company = currentCompany();
        $phone = $this->recipient($customer, $recipient);
        $account = SmsAccount::query()->first();

        $reason = match (true) {
            $phone === null => __('messages.blocked.no_phone'),
            $phone->sms_opted_out_at !== null => __('messages.blocked.opted_out'),
            $account === null || $account->phone_number === null || ! $this->sms->isConfigured() => __('messages.blocked.no_account'),
            $this->needsRegistration($phone->number) && ! $this->registrationApproved() => __('messages.blocked.registration'),
            default => null,
        };

        $message = Message::query()->create([
            'customer_id' => $customer->id,
            'service_job_id' => $job?->id,
            'direction' => Message::OUTBOUND,
            'channel' => Message::SMS,
            'kind' => $kind,
            'to' => $phone?->number,
            'from' => $account?->phone_number,
            'body' => $body,
            'status' => $reason === null ? 'scheduled' : 'blocked',
            'status_reason' => $reason,
            'send_after' => $reason === null ? QuietHours::nextAllowed($company) : null,
            'user_id' => $user?->id,
        ]);

        if ($reason === null) {
            $dispatch = DeliverSmsMessage::dispatch($message->id, $company->id)->delay($message->send_after);
            if ($afterCommit) {
                $dispatch->afterCommit();
            }
        }

        return $message;
    }

    /**
     * An SMS to a number that belongs to no customer yet (the office texting a new lead from the inbox), or a
     * "not sent" record saying why. Only the office sees it: it has no customer and no job.
     */
    public function smsToNumber(string $number, string $body, User $user): Message
    {
        $company = currentCompany();
        $account = SmsAccount::query()->first();
        $reason = $this->numberBlockedReason($number);

        $message = Message::query()->create([
            'direction' => Message::OUTBOUND,
            'channel' => Message::SMS,
            'kind' => MessageKind::General,
            'to' => $number,
            'from' => $account?->phone_number,
            'body' => $body,
            'status' => $reason === null ? 'scheduled' : 'blocked',
            'status_reason' => $reason,
            'send_after' => $reason === null ? QuietHours::nextAllowed($company) : null,
            'user_id' => $user->id,
        ]);

        if ($reason === null) {
            DeliverSmsMessage::dispatch($message->id, $company->id)->delay($message->send_after);
        }

        return $message;
    }

    /**
     * An SMS to a number typed for this customer (e.g. the review request prompt): one of the customer's phones is
     * texted as usual; another number keeps the customer and job on the record, with the number's own opt-out.
     */
    public function smsToCustomerNumber(MessageKind $kind, Customer $customer, ?ServiceJob $job, string $number, string $body, User $user): Message
    {
        $phone = $customer->phones()->where('number_normalized', $number)->first();
        if ($phone !== null) {
            return $this->sms($kind, $customer, $job, $body, $user, recipient: $phone);
        }

        $company = currentCompany();
        $account = SmsAccount::query()->first();
        $reason = $this->numberBlockedReason($number);

        $message = Message::query()->create([
            'customer_id' => $customer->id,
            'service_job_id' => $job?->id,
            'direction' => Message::OUTBOUND,
            'channel' => Message::SMS,
            'kind' => $kind,
            'to' => $number,
            'from' => $account?->phone_number,
            'body' => $body,
            'status' => $reason === null ? 'scheduled' : 'blocked',
            'status_reason' => $reason,
            'send_after' => $reason === null ? QuietHours::nextAllowed($company) : null,
            'user_id' => $user->id,
        ]);

        if ($reason === null) {
            DeliverSmsMessage::dispatch($message->id, $company->id)->delay($message->send_after);
        }

        return $message;
    }

    /**
     * Whether the company can text a number that belongs to no customer: null = yes, otherwise the reason.
     */
    public function numberBlockedReason(string $number): ?string
    {
        return match (true) {
            $this->numberOptedOut($number) => __('messages.blocked.opted_out'),
            SmsAccount::query()->whereNotNull('phone_number')->doesntExist() || ! $this->sms->isConfigured() => __('messages.blocked.no_account'),
            $this->registrationBlocks($number) => __('messages.blocked.registration'),
            default => null,
        };
    }

    /**
     * A number without a customer keeps no opt-out flag of its own: its latest STOP / START reply decides.
     */
    public function numberOptedOut(string $number): bool
    {
        $optOut = array_map('strtoupper', (array) config('sms.opt_out_keywords'));
        $optIn = array_map('strtoupper', (array) config('sms.opt_in_keywords'));
        $latest = Message::query()->where('direction', Message::INBOUND)->where('channel', Message::SMS)
            ->where('from', $number)->whereIn(DB::raw('UPPER(TRIM(body))'), [...$optOut, ...$optIn])
            ->latest('id')->value('body');

        return $latest !== null && in_array(strtoupper(trim($latest)), $optOut, true);
    }

    /**
     * Records that a technician opened the phone's messages app with this text (sms: link).
     */
    public function openedOnPhone(MessageKind $kind, Customer $customer, ?ServiceJob $job, string $to, string $body, User $user): Message
    {
        return Message::query()->create([
            'customer_id' => $customer->id,
            'service_job_id' => $job?->id,
            'direction' => Message::OUTBOUND,
            'channel' => Message::TECHNICIAN_PHONE,
            'kind' => $kind,
            'to' => $to,
            'body' => $body,
            'status' => 'opened',
            'sent_at' => now(),
            'user_id' => $user->id,
        ]);
    }

    /**
     * Sends a scheduled SMS now (queue job). Rechecks the opt-out: a STOP may have arrived meanwhile.
     */
    public function deliver(Message $message): void
    {
        if ($message->status !== 'scheduled') {
            return;
        }

        $account = SmsAccount::query()->first();
        $phone = CustomerPhone::query()->where('customer_id', $message->customer_id)->where('number_normalized', $message->to)->first();

        if ($phone?->sms_opted_out_at !== null || ($phone === null && $this->numberOptedOut((string) $message->to))) {
            $message->update(['status' => 'blocked', 'status_reason' => __('messages.blocked.opted_out')]);

            return;
        }

        if ($account === null || $account->phone_number === null) {
            $message->update(['status' => 'blocked', 'status_reason' => __('messages.blocked.no_account')]);

            return;
        }

        $message->update(['status' => 'sending']);

        try {
            $id = $this->sms->send($account, (string) $message->to, $message->body);
            $message->update(['status' => 'sent', 'provider_message_id' => $id, 'sent_at' => now(), 'from' => $account->phone_number]);
        } catch (Throwable $e) {
            $message->update(['status' => 'failed', 'status_reason' => __('messages.blocked.provider', ['error' => $e->getMessage()])]);
        }
    }

    /**
     * Whether the company can text this number now (for the UI): null = yes, otherwise the reason.
     */
    public function smsBlockedReason(Customer $customer, ?CustomerPhone $recipient = null): ?string
    {
        $phone = $this->recipient($customer, $recipient);

        return match (true) {
            $phone === null => __('messages.blocked.no_phone'),
            $phone->sms_opted_out_at !== null => __('messages.blocked.opted_out'),
            SmsAccount::query()->whereNotNull('phone_number')->doesntExist() || ! $this->sms->isConfigured() => __('messages.blocked.no_account'),
            $this->needsRegistration($phone->number) && ! $this->registrationApproved() => __('messages.blocked.registration'),
            default => null,
        };
    }

    /**
     * The number to text: the primary phone, else a mobile one.
     */
    public function mobile(Customer $customer): ?CustomerPhone
    {
        $phones = $customer->phones()->get();

        return $phones->firstWhere('is_primary', true) ?? $phones->first();
    }

    private function recipient(Customer $customer, ?CustomerPhone $recipient): ?CustomerPhone
    {
        return $recipient === null ? $this->mobile($customer) : CustomerPhone::query()
            ->where('customer_id', $customer->id)->whereKey($recipient->id)->firstOrFail();
    }

    private function email(MessageKind $kind, Customer $customer, ?ServiceJob $job, string $body, ?User $user, ?string $subject, bool $failIfMissing, bool $afterCommit = false): ?Message
    {
        $email = $customer->primaryEmail()->value('email');

        if ($email === null && ! $failIfMissing) {
            return null;
        }

        $brand = $job?->brand?->name ?? currentCompany()->name;
        $message = Message::query()->create([
            'customer_id' => $customer->id,
            'service_job_id' => $job?->id,
            'direction' => Message::OUTBOUND,
            'channel' => Message::EMAIL,
            'kind' => $kind,
            'to' => $email,
            'body' => $body,
            'status' => $email === null ? 'blocked' : 'sent',
            'status_reason' => $email === null ? __('messages.blocked.no_email') : null,
            'sent_at' => $email === null ? null : now(),
            'user_id' => $user?->id,
        ]);

        if ($email !== null) {
            $mail = new CustomerMessageMail($message->id, currentCompany()->id,
                $subject ?? __("messages.email_subject.{$kind->value}", ['brand' => $brand, 'number' => '']));
            if ($afterCommit) {
                $mail->afterCommit();
            }
            Mail::to($email)->queue($mail);
        }

        return $message;
    }

    /**
     * US numbers can be texted only once the company's A2P 10DLC registration is approved.
     */
    public function registrationBlocks(string $number): bool
    {
        return $this->needsRegistration($number) && ! $this->registrationApproved();
    }

    private function needsRegistration(string $number): bool
    {
        try {
            $util = PhoneNumberUtil::getInstance();

            return $util->getRegionCodeForNumber($util->parse($number)) === 'US';
        } catch (Throwable) {
            return false;
        }
    }

    private function registrationApproved(): bool
    {
        return SmsRegistration::query()->where('status', SmsRegistration::APPROVED)->exists();
    }
}
