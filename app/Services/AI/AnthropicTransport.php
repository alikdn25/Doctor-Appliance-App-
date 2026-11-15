<?php

namespace App\Services\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;

/**
 * The Messages API through the official PHP SDK. No fallbacks: a feature always runs on its own model.
 */
class AnthropicTransport implements AiTransport
{
    public function send(array $request): AiResponse
    {
        $outputConfig = array_filter([
            'effort' => $request['effort'],
            'format' => $request['schema'] === null ? null : ['type' => 'json_schema', 'schema' => $request['schema']],
        ]);

        try {
            $message = (new Client(apiKey: (string) config('ai.key')))->messages->create(
                maxTokens: $request['max_tokens'],
                messages: $request['messages'],
                model: $request['model'],
                outputConfig: $outputConfig === [] ? null : $outputConfig,
                system: $request['system'],
                tools: $request['tools'] === [] ? null : $request['tools'],
                requestOptions: ['timeout' => (int) config('ai.timeout', 60)],
            );
        } catch (AnthropicException $e) {
            throw new AiUnavailable($e->getMessage(), previous: $e);
        }

        $content = [];
        foreach ($message->content as $block) {
            $content[] = match ($block->type) {
                'text' => ['type' => 'text', 'text' => $block->text],
                'tool_use' => ['type' => 'tool_use', 'id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input],
                'thinking' => ['type' => 'thinking', 'thinking' => $block->thinking, 'signature' => $block->signature],
                'redacted_thinking' => ['type' => 'redacted_thinking', 'data' => $block->data],
                default => ['type' => $block->type],
            };
        }

        $usage = $message->usage;

        return new AiResponse(
            content: $content,
            stopReason: (string) $message->stopReason,
            usage: [
                'input_tokens' => (int) $usage->inputTokens,
                'output_tokens' => (int) $usage->outputTokens,
                'cache_read_tokens' => (int) ($usage->cacheReadInputTokens ?? 0),
                'cache_write_tokens' => (int) ($usage->cacheCreationInputTokens ?? 0),
            ],
            model: $message->model,
        );
    }
}
