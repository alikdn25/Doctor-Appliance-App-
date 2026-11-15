<?php

namespace Tests\Fakes;

use App\Services\AI\AiResponse;
use App\Services\AI\AiTransport;
use App\Services\AI\AiUnavailable;
use Closure;

/**
 * Stands in for the Anthropic API in tests: records every request and answers with the given closure (which gets the
 * request and returns an AiResponse, an array for a JSON answer, a string for a text answer, or throws).
 */
class FakeAiTransport implements AiTransport
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function __construct(private readonly Closure $answer) {}

    public static function install(Closure|array|string|null $answer): self
    {
        $fake = new self($answer instanceof Closure ? $answer : fn () => $answer ?? throw new AiUnavailable('Down'));
        app()->instance(AiTransport::class, $fake);
        config(['ai.key' => 'test-key']);

        return $fake;
    }

    public function send(array $request): AiResponse
    {
        $this->requests[] = $request;
        $answer = ($this->answer)($request);

        if ($answer instanceof AiResponse) {
            return $answer;
        }

        return new AiResponse(
            content: [['type' => 'text', 'text' => is_array($answer) ? json_encode($answer) : (string) $answer]],
            stopReason: 'end_turn',
            usage: ['input_tokens' => 1000, 'output_tokens' => 200],
            model: $request['model'],
        );
    }
}
