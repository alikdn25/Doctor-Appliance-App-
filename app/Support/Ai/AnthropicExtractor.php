<?php

namespace App\Support\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;

/**
 * Claude (Anthropic API) with structured outputs. Short extraction work: low effort, server-side fallback on a
 * refusal.
 */
class AnthropicExtractor implements JsonExtractor
{
    public function __construct(private readonly string $apiKey, private readonly string $model) {}

    public function extract(string $instructions, string $text, array $schema): array
    {
        try {
            $message = (new Client(apiKey: $this->apiKey))->beta->messages->create(
                maxTokens: 4000,
                messages: [['role' => 'user', 'content' => $text]],
                model: $this->model,
                system: $instructions,
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
                requestOptions: ['timeout' => 30],
            );
        } catch (AnthropicException $e) {
            throw new AiUnavailable($e->getMessage(), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiUnavailable('The request was declined.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);

                if (is_array($data)) {
                    return $data;
                }
            }
        }

        throw new AiUnavailable('No JSON in the answer.');
    }
}
