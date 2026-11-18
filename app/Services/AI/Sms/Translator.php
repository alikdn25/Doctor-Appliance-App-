<?php

namespace App\Services\AI\Sms;

use App\Models\Company;
use App\Models\User;
use App\Services\AI\AiFeature;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;

/**
 * "I say it in Russian, the customer gets English" (owner's task of November 19): text in the reply box written in
 * Russian is turned by AI_MODEL_ASSISTANT into a short, polite English SMS and put back into the box; the person
 * sends it. English text is left alone. Must run in a tenant context.
 */
class Translator
{
    public function __construct(private readonly AiService $ai) {}

    public static function isRussian(string $text): bool
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $text);

        return $letters > 0 && $cyrillic / $letters >= 0.3;
    }

    /**
     * @throws AiUnavailable
     */
    public function toEnglish(string $text, Company $company, ?User $user): string
    {
        $answer = $this->ai->send(AiFeature::Translate, $company, $user, self::instructions($company),
            [['role' => 'user', 'content' => $text]], schema: self::schema(), maxTokens: 600, effort: 'low')->json();

        $english = trim((string) ($answer['text'] ?? ''));
        if ($english === '') {
            throw new AiUnavailable('Empty translation.');
        }

        return mb_substr($english, 0, 1600);
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
            The person works for {$company->name}, a repair company, and wrote a text message (SMS) to a customer in
            Russian (maybe mixed with English). Turn it into one short, polite, natural English SMS with the same meaning.
            Keep names, numbers, times, dates, prices, addresses, model numbers and links exactly. Add nothing, promise
            nothing new. Return only the English text.
            TEXT;
    }
}
