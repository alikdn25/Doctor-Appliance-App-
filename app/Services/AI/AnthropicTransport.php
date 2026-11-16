<?php

namespace App\Services\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Services\AI\Assistant\AssistantPhotos;

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
                messages: self::messages($request['messages']),
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

    /**
     * Stored messages back into the request: a tool call without arguments must stay a JSON object ({}), which
     * decoding turned into an empty list; a stored photo (image block with a path) goes as a base64 image.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function messages(array $messages): array
    {
        return array_map(function (array $message) {
            if (is_array($message['content'] ?? null)) {
                $message['content'] = array_map(
                    fn ($block) => match (true) {
                        is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? []) === [] => [...$block, 'input' => new \stdClass],
                        is_array($block) && ($block['type'] ?? null) === 'image' && isset($block['path']) => AssistantPhotos::toApi($block),
                        default => $block,
                    },
                    $message['content'],
                );
            }

            return $message;
        }, $messages);
    }
}
