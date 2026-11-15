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

    /** The key in config('ai.models'). */
    public function modelKey(): string
    {
        return match ($this) {
            self::QuickBooking, self::PasteMessage => 'booking',
            self::Assistant, self::FindProblems => 'assistant',
            self::AppReview => 'review',
        };
    }

    public function model(): string
    {
        return (string) config('ai.models.'.$this->modelKey());
    }
}
