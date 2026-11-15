<?php

namespace App\Services\AI;

use App\Models\AiRequest;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one way the app talks to the AI (Anthropic API). Each feature uses its own model (config/ai.php) with no
 * automatic switching. Every request is checked against the company's daily limit and written to the request log
 * (company, user, feature, model, tokens, cost, time). Supports tools (tool use) for the assistant. The API key
 * stays on the server.
 */
class AiService
{
    public function __construct(private readonly AiTransport $transport) {}

    public function configured(): bool
    {
        return (string) config('ai.key') !== '';
    }

    /**
     * Requests the company has left today (its own time zone).
     */
    public function remainingToday(Company $company): int
    {
        return max(0, (int) config('ai.daily_limit') - $this->usedToday($company));
    }

    /**
     * Sends one request.
     *
     * @param  list<array<string, mixed>>  $messages  Messages API messages (SDK shape: tool results use `toolUseID`).
     * @param  list<array<string, mixed>>  $tools  Tool definitions: name, description, inputSchema.
     * @param  array<string, mixed>|null  $schema  JSON schema for a structured (JSON) answer.
     *
     * @throws AiUnavailable When there is no key, the limit is reached, or the AI cannot answer.
     */
    public function send(
        AiFeature $feature,
        Company $company,
        ?User $user,
        string $system,
        array $messages,
        array $tools = [],
        ?array $schema = null,
        int $maxTokens = 4000,
        ?string $effort = null,
    ): AiResponse {
        if (! $this->configured()) {
            throw new AiUnavailable('The AI is not set up (ANTHROPIC_API_KEY).');
        }

        if ($this->remainingToday($company) <= 0) {
            throw new AiLimitReached('The daily AI limit is reached.');
        }

        $model = $feature->model();
        $started = hrtime(true);

        try {
            $response = $this->transport->send([
                'model' => $model,
                'system' => $system,
                'messages' => $messages,
                'tools' => $tools,
                'schema' => $schema,
                'max_tokens' => $maxTokens,
                'effort' => $effort,
            ]);
        } catch (Throwable $e) {
            $this->log($feature, $company, $user, $model, [], $started, 'error', $e->getMessage());
            Log::warning('AI request failed', ['company' => $company->id, 'feature' => $feature->value, 'error' => $e->getMessage()]);

            throw $e instanceof AiUnavailable ? $e : new AiUnavailable($e->getMessage(), previous: $e);
        }

        $refused = $response->stopReason === 'refusal';
        $this->log($feature, $company, $user, $model, $response->usage, $started, $refused ? 'refused' : 'ok');

        if ($refused) {
            throw new AiUnavailable('The request was declined.');
        }

        return $response;
    }

    /**
     * USD for the tokens of one request at the model's prices (config ai.prices).
     *
     * @param  array{input_tokens?: int, output_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int}  $usage
     */
    public static function cost(string $model, array $usage): float
    {
        $price = config("ai.prices.{$model}");

        if (! is_array($price)) {
            return 0.0;
        }

        return round((
            ($usage['input_tokens'] ?? 0) * ($price['input'] ?? 0)
            + ($usage['output_tokens'] ?? 0) * ($price['output'] ?? 0)
            + ($usage['cache_read_tokens'] ?? 0) * ($price['cache_read'] ?? 0)
            + ($usage['cache_write_tokens'] ?? 0) * ($price['cache_write'] ?? 0)
        ) / 1_000_000, 6);
    }

    private function usedToday(Company $company): int
    {
        $start = CarbonImmutable::now($company->timezone ?: 'UTC')->startOfDay()->utc();

        return AiRequest::withoutCompanyScope()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $start)
            ->count();
    }

    /**
     * @param  array<string, int>  $usage
     */
    private function log(AiFeature $feature, Company $company, ?User $user, string $model, array $usage, int|float $started, string $status, ?string $error = null): void
    {
        AiRequest::withoutCompanyScope()->forceCreate([
            'company_id' => $company->id,
            'user_id' => $user?->id,
            'feature' => $feature->value,
            'model' => $model,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cache_read_tokens' => $usage['cache_read_tokens'] ?? 0,
            'cache_write_tokens' => $usage['cache_write_tokens'] ?? 0,
            'cost_usd' => self::cost($model, $usage),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'created_at' => now(),
        ]);
    }
}
