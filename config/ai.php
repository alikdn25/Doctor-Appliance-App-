<?php

/*
|--------------------------------------------------------------------------
| AI (Anthropic API)
|--------------------------------------------------------------------------
|
| Every AI request goes through App\Services\AI\AiService. Each feature always uses its own model; there is no
| automatic switching between models (no fallbacks). The key never leaves the server.
|
*/

return [
    'key' => env('ANTHROPIC_API_KEY'),

    'models' => [
        // Reading the text of a booking (Quick booking, Paste from message).
        'booking' => env('AI_MODEL_BOOKING', 'claude-haiku-5-5'),
        // The assistant and Find problems.
        'assistant' => env('AI_MODEL_ASSISTANT', 'claude-sonnet-5-5'),
        // App review only.
        'review' => env('AI_MODEL_REVIEW', 'claude-opus-5-5'),
    ],

    // Requests one company may make per day (its own time zone). 0 = no AI requests.
    'daily_limit' => (int) env('AI_DAILY_LIMIT_PER_COMPANY', 200),

    'timeout' => (int) env('AI_TIMEOUT_SECONDS', 60),

    // USD per million tokens, for the cost in the request log. Unknown models are logged with cost 0.
    'prices' => [
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
    ],
];
