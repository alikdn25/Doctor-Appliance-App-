<?php

namespace App\Support\Ai;

use App\Models\Company;

/**
 * Which AI reads pasted texts: the provider configured for the app (AI_PROVIDER), or none.
 */
class AiProvider
{
    public const ANTHROPIC = 'anthropic';

    public const XAI = 'xai';

    /**
     * The extractor for the company, or null when no provider with a key is set.
     */
    public function for(Company $company): ?JsonExtractor
    {
        $provider = (string) config('services.ai.provider');
        $key = (string) config("services.ai.{$provider}.key");

        if ($key === '') {
            return null;
        }

        return match ($provider) {
            self::ANTHROPIC => new AnthropicExtractor($key, (string) config('services.ai.anthropic.model')),
            self::XAI => new XaiExtractor($key, (string) config('services.ai.xai.model'), (string) config('services.ai.xai.url')),
            default => null,
        };
    }
}
