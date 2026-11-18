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

    /** The key in config('ai.models'). */
    public function modelKey(): string
    {
        return match ($this) {
            self::QuickBooking, self::PasteMessage, self::SmsTriage, self::BookingEdit => 'booking',
            self::Assistant, self::FindProblems, self::SmsPhoto, self::VoiceClose, self::PartLookup, self::Receipt => 'assistant',
            self::AppReview => 'review',
        };
    }

    public function model(): string
    {
        return (string) config('ai.models.'.$this->modelKey());
    }
}
