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

    public function label(): string
    {
        return __("messages.kinds.{$this->value}");
    }

    /**
     * Kinds with a template companies can edit.
     *
     * @return list<self>
     */
    public static function templated(): array
    {
        return [self::BookingConfirmation, self::VisitReminder, self::OnMyWay, self::OnMyWayEta, self::EstimateLink, self::EstimateFollowup, self::InvoiceLink, self::ReviewRequest, self::General];
    }
}
