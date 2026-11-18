<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Models\CustomerPhone;
use App\Models\Message;
use App\Support\Locale\BusinessHours;
use Carbon\CarbonImmutable;

/**
 * Clarifying questions sent by themselves (owner's task of November 19): only when the company turned on "Send
 * clarifying questions automatically" (off by default — then they stay in the reply draft), in Automatic SMS mode,
 * in business hours, at most once per 24 hours to one number, never to staff. The text is only the questions and the
 * request for a sticker photo; the rest of the AI's draft still waits for a person. Must run in a tenant context.
 */
class Clarifier
{
    public const GAP_HOURS = 24;

    public function __construct(private readonly Messenger $messenger) {}

    /**
     * @param  array<string, mixed>  $triage
     */
    public function maybeSend(Message $incoming, array $triage): ?Message
    {
        $company = currentCompany();
        $text = $triage['clarifying'] ?? null;
        $phone = (string) $incoming->from;
        $now = CarbonImmutable::now();

        if (! $company->auto_clarify || ! is_string($text) || $text === '' || $company->sms_mode !== SmsMode::Automatic
            || ! BusinessHours::isOpen($company, $now) || SmsThreads::isStaff($company, $phone)) {
            return null;
        }

        $thread = SmsThreads::for($phone);
        if ($thread->last_clarify_at !== null && $thread->last_clarify_at->greaterThan($now->subHours(self::GAP_HOURS))) {
            return null;
        }
        $thread->forceFill(['last_clarify_at' => $now])->save();

        $body = __('answers.auto_hello').' '.$text;
        $recipient = CustomerPhone::query()->with('customer')->where('number_normalized', $phone)->whereHas('customer')->first();

        return $recipient !== null
            ? $this->messenger->sms(MessageKind::Clarifying, $recipient->customer, null, $body, recipient: $recipient)
            : $this->messenger->smsToNumber($phone, $body, null, MessageKind::Clarifying);
    }
}
