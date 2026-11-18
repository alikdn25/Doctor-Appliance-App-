<?php

use App\Models\AiRequest;
use App\Models\Company;
use App\Services\AI\AiFeature;
use App\Services\AI\AiLimitReached;
use App\Services\AI\AiResponse;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use Tests\Fakes\FakeAiTransport;

/**
 * The one AI service: each feature on its own model, every request logged with tokens, cost and time, the daily
 * limit per company, and tool use passed through.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = memberOf($this->company);
    config([
        'ai.models.booking' => 'claude-haiku-5-5',
        'ai.models.assistant' => 'claude-sonnet-5-5',
        'ai.models.review' => 'claude-opus-5-5',
        'ai.daily_limit' => 3,
    ]);
});

test('each feature always uses its own model', function () {
    config(['ai.daily_limit' => 50]);
    $fake = FakeAiTransport::install('ok');
    $ai = app(AiService::class);

    foreach (AiFeature::cases() as $feature) {
        $ai->send($feature, $this->company, $this->user, 'system', [['role' => 'user', 'content' => 'hi']]);
    }

    $models = ['booking' => 'claude-haiku-5-5', 'assistant' => 'claude-sonnet-5-5', 'review' => 'claude-opus-5-5'];
    expect(array_slice(array_column($fake->requests, 'model'), 0, 5))->toBe([
        'claude-haiku-5-5', 'claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5',
    ])->and(array_column($fake->requests, 'model'))->toBe(array_map(fn (AiFeature $f) => $models[$f->modelKey()], AiFeature::cases()));
    // Reading a text and quick edits use the booking model; photos, voice and receipts the assistant model.
    expect(AiFeature::SmsTriage->modelKey())->toBe('booking')->and(AiFeature::SmsPhoto->modelKey())->toBe('assistant');
});

test('every request is logged with company, user, model, tokens, cost and time', function () {
    FakeAiTransport::install(fn () => new AiResponse([['type' => 'text', 'text' => 'hi']], 'end_turn', ['input_tokens' => 2_000_000, 'output_tokens' => 1_000_000]));

    app(AiService::class)->send(AiFeature::Assistant, $this->company, $this->user, 's', [['role' => 'user', 'content' => 'x']]);

    $row = AiRequest::withoutCompanyScope()->sole();
    expect($row->company_id)->toBe($this->company->id)
        ->and($row->user_id)->toBe($this->user->id)
        ->and($row->feature)->toBe('assistant')
        ->and($row->model)->toBe('claude-sonnet-5-5')
        ->and($row->input_tokens)->toBe(2_000_000)
        ->and($row->output_tokens)->toBe(1_000_000)
        // 2M × $2 + 1M × $10
        ->and((float) $row->cost_usd)->toBe(14.0)
        ->and($row->status)->toBe('ok')
        ->and($row->duration_ms)->toBeGreaterThanOrEqual(0);
});

test('a failed request is logged as an error', function () {
    FakeAiTransport::install(fn () => throw new AiUnavailable('Overloaded'));

    expect(fn () => app(AiService::class)->send(AiFeature::QuickBooking, $this->company, $this->user, 's', []))
        ->toThrow(AiUnavailable::class);

    expect(AiRequest::withoutCompanyScope()->sole())->status->toBe('error')->error->toBe('Overloaded');
});

test('the daily limit is per company: over it nothing is sent', function () {
    $fake = FakeAiTransport::install('ok');
    $ai = app(AiService::class);
    foreach (range(1, 3) as $ignored) {
        $ai->send(AiFeature::QuickBooking, $this->company, $this->user, 's', []);
    }

    expect(fn () => $ai->send(AiFeature::QuickBooking, $this->company, $this->user, 's', []))->toThrow(AiLimitReached::class)
        ->and($fake->requests)->toHaveCount(3)
        ->and($ai->remainingToday($this->company))->toBe(0);

    // Another company has its own limit.
    $other = Company::factory()->create();
    $ai->send(AiFeature::QuickBooking, $other, null, 's', []);
    expect($fake->requests)->toHaveCount(4);
});

test('yesterday\'s requests do not count against today', function () {
    FakeAiTransport::install('ok');
    AiRequest::withoutCompanyScope()->forceCreate([
        'company_id' => $this->company->id, 'feature' => 'quick_booking', 'model' => 'm', 'status' => 'ok',
        'created_at' => now($this->company->timezone)->subDay()->startOfDay(),
    ]);

    expect(app(AiService::class)->remainingToday($this->company))->toBe(3);
});

test('without a key nothing is sent', function () {
    $fake = FakeAiTransport::install('ok');
    config(['ai.key' => null]);

    expect(fn () => app(AiService::class)->send(AiFeature::Assistant, $this->company, $this->user, 's', []))->toThrow(AiUnavailable::class)
        ->and($fake->requests)->toBe([]);
});

test('tools are passed to the API and tool calls come back', function () {
    $fake = FakeAiTransport::install(fn () => new AiResponse(
        [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_customer', 'input' => ['query' => 'Kim']]],
        'tool_use',
    ));
    $tools = [['name' => 'find_customer', 'description' => 'Find a customer', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]]];

    $response = app(AiService::class)->send(AiFeature::Assistant, $this->company, $this->user, 's', [['role' => 'user', 'content' => 'Kim?']], tools: $tools);

    expect($fake->requests[0]['tools'])->toBe($tools)
        ->and($response->toolUses())->toHaveCount(1)
        ->and($response->toolUses()[0]['input'])->toBe(['query' => 'Kim']);
});

test('a refusal is logged and reported as unavailable', function () {
    FakeAiTransport::install(fn () => new AiResponse([], 'refusal'));

    expect(fn () => app(AiService::class)->send(AiFeature::Assistant, $this->company, $this->user, 's', []))->toThrow(AiUnavailable::class);
    expect(AiRequest::withoutCompanyScope()->sole()->status)->toBe('refused');
});
