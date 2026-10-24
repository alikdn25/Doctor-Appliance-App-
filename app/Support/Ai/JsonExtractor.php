<?php

namespace App\Support\Ai;

/**
 * An AI provider that reads free text and returns JSON matching a schema (structured output).
 */
interface JsonExtractor
{
    /**
     * @param  array<string, mixed>  $schema  JSON schema of the answer (an object).
     * @return array<string, mixed>
     *
     * @throws AiUnavailable When the provider cannot answer (no key, network, refusal, invalid JSON).
     */
    public function extract(string $instructions, string $text, array $schema): array;
}
