<?php

namespace App\Services\AI;

/**
 * What an AI request is for. Each feature always uses its own model from config/ai.php; there is no switching
 * between models.
 */
enum AiFeature: string
{
    case QuickBooking = 'quick_booking';
    case PasteMessage = 'paste_message';
    case Assistant = 'assistant';
    case FindProblems = 'find_problems';
    case AppReview = 'app_review';
    /** An incoming SMS: urgency, proposed actions, a reply draft. */
    case SmsTriage = 'sms_triage';
    /** A photo in an incoming MMS (rating plate). */
    case SmsPhoto = 'sms_photo';

    /** "Quick edit" of a booking by voice. */
    case BookingEdit = 'booking_edit';

    /** "Close by voice" on the job page. */
    case VoiceClose = 'voice_close';

    /** A possible part number when a job needs a part. */
    case PartLookup = 'part_lookup';

    /** A receipt photo in Expenses. */
    case Receipt = 'receipt';

    /** "Text again": a short follow-up when the customer stays quiet. */
    case SmsNudge = 'sms_nudge';

    /** Russian in the reply box → a short English SMS. */
    case Translate = 'translate';

    /** A To Do typed or said ("tomorrow buy an element at Marcone on Kingsway") → the task form. */
    case TaskParse = 'task_parse';

    /** The key in config('ai.models'). */
    public function modelKey(): string
    {
        return match ($this) {
            self::QuickBooking, self::PasteMessage, self::SmsTriage, self::BookingEdit, self::SmsNudge, self::TaskParse => 'booking',
            self::Assistant, self::FindProblems, self::SmsPhoto, self::VoiceClose, self::PartLookup, self::Receipt, self::Translate => 'assistant',
            self::AppReview => 'review',
        };
    }

    public function model(): string
    {
        return (string) config('ai.models.'.$this->modelKey());
    }
}
