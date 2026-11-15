<?php

namespace App\Services\AI;

/**
 * Sends one Messages API request. The real one is AnthropicTransport; tests bind a fake.
 */
interface AiTransport
{
    /**
     * @param  array{model: string, system: string, messages: list<array<string, mixed>>, tools: list<array<string, mixed>>, schema: array<string, mixed>|null, max_tokens: int, effort: string|null}  $request
     *
     * @throws AiUnavailable
     */
    public function send(array $request): AiResponse;
}
