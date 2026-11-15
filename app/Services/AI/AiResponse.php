<?php

namespace App\Services\AI;

/**
 * An answer from the AI, in plain arrays: content blocks (text, tool_use, thinking), why it stopped and the tokens.
 */
final readonly class AiResponse
{
    /**
     * @param  list<array<string, mixed>>  $content  ['type' => 'text', 'text' => …], ['type' => 'tool_use', 'id' => …, 'name' => …, 'input' => […]], ['type' => 'thinking', …]
     * @param  array{input_tokens?: int, output_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int}  $usage
     */
    public function __construct(
        public array $content,
        public string $stopReason,
        public array $usage = [],
        public ?string $model = null,
    ) {}

    /** All text blocks joined. */
    public function text(): string
    {
        return trim(implode("\n", array_map(
            fn (array $block) => (string) $block['text'],
            array_filter($this->content, fn (array $block) => ($block['type'] ?? null) === 'text'),
        )));
    }

    /**
     * The answer read as a JSON object (structured output).
     *
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    public function json(): array
    {
        $data = json_decode($this->text(), true);

        if (! is_array($data)) {
            throw new AiUnavailable('No JSON in the answer.');
        }

        return $data;
    }

    /**
     * @return list<array{type: string, id: string, name: string, input: array<string, mixed>}>
     */
    public function toolUses(): array
    {
        return array_values(array_filter($this->content, fn (array $block) => ($block['type'] ?? null) === 'tool_use'));
    }
}
