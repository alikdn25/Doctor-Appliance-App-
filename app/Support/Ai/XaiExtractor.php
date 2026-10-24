<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Grok (xAI API, chat completions) with a JSON schema response format.
 */
class XaiExtractor implements JsonExtractor
{
    public function __construct(private readonly string $apiKey, private readonly string $model, private readonly string $baseUrl) {}

    public function extract(string $instructions, string $text, array $schema): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(30)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'strict' => true, 'schema' => $schema]],
                ]);
        } catch (ConnectionException $e) {
            throw new AiUnavailable($e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiUnavailable('xAI answered '.$response->status());
        }

        $data = json_decode((string) $response->json('choices.0.message.content'), true);

        if (! is_array($data)) {
            throw new AiUnavailable('No JSON in the answer.');
        }

        return $data;
    }
}
