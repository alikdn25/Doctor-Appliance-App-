<?php

namespace App\Services\AI;

use App\Models\Company;
use App\Models\User;

/**
 * "Translate to English" under a field whose text goes to the customer (owner's task of November 25): the text, in any
 * language, comes back in English from AI_MODEL_BOOKING. The page puts it in the field; "Undo" puts back the old text.
 */
class TextTranslator
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @throws AiUnavailable
     */
    public function toEnglish(string $text, Company $company, ?User $user): string
    {
        $answer = $this->ai->send(AiFeature::TranslateText, $company, $user, self::instructions($company),
            [['role' => 'user', 'content' => $text]], schema: self::schema(), maxTokens: 4000, effort: 'low')->json();

        $english = trim((string) ($answer['text'] ?? ''));
        if ($english === '') {
            throw new AiUnavailable('Empty translation.');
        }

        return $english;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }

    private static function instructions(Company $company): string
    {
        return <<<TEXT
            The person works for {$company->name}, a repair company, and wrote a text for a customer (a service report,
            a message, an invoice or estimate description). Translate it into clear, natural English with the same
            meaning. Keep line breaks, lists, names, numbers, times, dates, prices, addresses, model and part numbers,
            placeholders in curly braces like {first_name} and links exactly. Add nothing. English text stays as it is
            (only fix obvious typos). Return only the English text.
            TEXT;
    }
}
