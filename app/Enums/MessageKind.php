<?php

namespace App\Enums;

/**
 * What a customer message is about. Each outbound kind has a company-editable template.
 */
enum MessageKind: string
{
    case VisitReminder = 'visit_reminder';
    case OnMyWay = 'on_my_way';
    case EstimateLink = 'estimate_link';
    case InvoiceLink = 'invoice_link';
    case ReviewRequest = 'review_request';
    case General = 'general';
    case Reply = 'reply';

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
        return [self::VisitReminder, self::OnMyWay, self::EstimateLink, self::InvoiceLink, self::ReviewRequest, self::General];
    }
}
