<?php

namespace App\Enums;

/**
 * What a customer message is about. Each outbound kind has a company-editable template.
 */
enum MessageKind: string
{
    case BookingConfirmation = 'booking_confirmation';
    case VisitReminder = 'visit_reminder';
    case OnMyWay = 'on_my_way';
    /** On my way with the drive time from the technician's position (owner's task of November 18). */
    case OnMyWayEta = 'on_my_way_eta';
    case EstimateLink = 'estimate_link';
    case EstimateFollowup = 'estimate_followup';
    case InvoiceLink = 'invoice_link';
    case ReviewRequest = 'review_request';
    case General = 'general';
    case Reply = 'reply';
    /** Sent by the app itself (Auto messages): an auto reply to a text or a missed call. */
    case AutoReply = 'auto_reply';
    /** A soft reminder about an open invoice (no amount, no link), sent after Confirm. */
    case PaymentReminder = 'payment_reminder';
    /** Clarifying questions sent automatically (Prices and answers → "Send clarifying questions automatically"). */
    case Clarifying = 'clarifying';
    /** "Hand to technician": the texts to the technician and to the customer. */
    case Handoff = 'handoff';
    /** The receipt after payment, from the Paid screen (with the review request inside when ticked). */
    case Receipt = 'receipt';

    public function label(): string
    {
        return __("messages.kinds.{$this->value}");
    }

    /**
     * Kinds with a template companies can edit. The review request has its own two texts (ReviewText).
     *
     * @return list<self>
     */
    public static function templated(): array
    {
        return [self::BookingConfirmation, self::VisitReminder, self::OnMyWay, self::OnMyWayEta, self::EstimateLink, self::EstimateFollowup, self::InvoiceLink, self::General];
    }
}
