<?php

use App\Enums\UserRole;
use App\Models\AppReview;
use App\Models\Company;
use App\Models\InconvenienceReport;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeAiTransport;

/**
 * App review: the action log keeps no customer data, "Inconvenient" keeps the screen and the last actions, the review
 * runs only on the button with AI_MODEL_REVIEW and earlier suggestions' statuses, Owner only.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    config(['ai.models.review' => 'claude-opus-5-5', 'ai.daily_limit' => 100]);
    $this->actingAs($this->owner);
});

function events(): array
{
    return inCompany(test()->company, fn () => UsageEvent::query()->orderBy('id')->get()->toArray());
}

test('the action log never stores customer data', function () {
    $this->postJson(route('usage.store'), ['session' => 'abc', 'events' => [
        ['type' => 'screen_view', 'screen' => 'customers/show', 'at' => now()->getTimestampMs()],
        ['type' => 'tap', 'screen' => 'customers/show', 'name' => 'Jane Cooper', 'at' => now()->getTimestampMs()],
        ['type' => 'tap', 'screen' => 'jobs/show', 'name' => '6045550199', 'at' => now()->getTimestampMs()],
        ['type' => 'tap', 'screen' => 'jobs/show', 'name' => 'anna@example.com', 'at' => now()->getTimestampMs()],
        ['type' => 'tap', 'screen' => 'jobs/show', 'name' => 'amount-250', 'at' => now()->getTimestampMs()],
        ['type' => 'search', 'screen' => 'customers/index', 'name' => 'customers', 'meta' => ['found' => false, 'query' => 'Jane Cooper', 'address' => '123 Main St'], 'at' => now()->getTimestampMs()],
        ['type' => 'tap', 'screen' => '123 Main St', 'name' => 'booking-finish', 'at' => now()->getTimestampMs()],
        ['type' => 'secret', 'screen' => 'jobs/show', 'name' => 'x'],
    ]])->assertNoContent();

    $stored = events();
    $all = json_encode($stored);

    expect($stored)->toHaveCount(7)
        ->and($all)->not->toContain('Jane')->not->toContain('6045550199')->not->toContain('anna@')
        ->not->toContain('Main St')->not->toContain('250')
        ->and($stored[1]['name'])->toBeNull()
        ->and($stored[5]['meta'])->toBe(['found' => false])
        ->and($stored[6]['screen'])->toBeNull()
        ->and($stored[6]['name'])->toBe('booking-finish')
        ->and($stored[0]['role'])->toBe('owner')
        ->and($stored[0]['user_id'])->toBe($this->owner->id);
});

test('Inconvenient keeps the screen and the last 20 actions, for every role', function () {
    $tech = memberOf($this->company, UserRole::Technician);
    $recent = collect(range(1, 25))->map(fn ($i) => ['type' => 'tap', 'screen' => 'jobs/show', 'name' => $i % 2 ? 'visit-start' : 'Jane Cooper', 'at' => now()->getTimestampMs()])->all();

    $this->actingAs($tech)->postJson(route('usage.inconvenient'), [
        'text' => 'Too many taps to start a visit',
        'screen' => 'jobs/show',
        'recent' => array_slice($recent, 0, 20),
    ])->assertOk();

    $report = inCompany($this->company, fn () => InconvenienceReport::query()->sole());
    expect($report->screen)->toBe('jobs/show')
        ->and($report->role)->toBe('technician')
        ->and($report->text)->toBe('Too many taps to start a visit')
        ->and($report->recent_actions)->toHaveCount(20)
        ->and(json_encode($report->recent_actions))->not->toContain('Jane');

    $this->actingAs($tech)->postJson(route('usage.inconvenient'), ['text' => 'x', 'recent' => $recent])->assertUnprocessable();
});

test('the log is kept 90 days', function () {
    inCompany($this->company, function () {
        UsageEvent::create(['type' => 'tap', 'occurred_at' => now()->subDays(91)]);
        UsageEvent::create(['type' => 'tap', 'occurred_at' => now()->subDays(89)]);
    });

    Artisan::call('usage:prune');

    expect(events())->toHaveCount(1);
});

test('the review uses the review model, the summary of the period and earlier suggestions with their statuses', function () {
    inCompany($this->company, function () {
        foreach ([[30000, 8], [90000, 20], [45000, 10]] as [$ms, $taps]) {
            UsageEvent::create(['type' => 'flow', 'name' => 'booking', 'role' => 'owner', 'value' => $ms, 'meta' => ['taps' => $taps], 'occurred_at' => now()->subDay()]);
        }
        UsageEvent::create(['type' => 'search', 'name' => 'customers', 'meta' => ['found' => false], 'occurred_at' => now()->subDay()]);
        UsageEvent::create(['type' => 'form_abandon', 'screen' => 'billing/form', 'occurred_at' => now()->subDay()]);
        UsageEvent::create(['type' => 'flow', 'name' => 'booking', 'value' => 1000, 'meta' => ['taps' => 1], 'occurred_at' => now()->subDays(40)]);
        InconvenienceReport::create(['role' => 'technician', 'screen' => 'jobs/show', 'text' => 'Start button too small', 'recent_actions' => []]);

        $old = AppReview::create(['period_from' => now()->subDays(60), 'period_to' => now()->subDays(30), 'period' => '30', 'summary' => [], 'report' => ['overview' => 'Old']]);
        $old->suggestions()->create(['position' => 1, 'importance' => 'high', 'title' => 'Bigger Start button', 'why' => 'x', 'fix' => 'y', 'status' => 'done']);
        $old->suggestions()->create(['position' => 2, 'importance' => 'low', 'title' => 'Dark mode', 'why' => 'x', 'fix' => 'y', 'status' => 'not_needed']);
    });

    $fake = FakeAiTransport::install(fn () => [
        'overview' => 'Booking takes 55 s on average.',
        'suggestions' => [
            ['importance' => 'low', 'title' => 'Shorter estimate form', 'why' => '1 abandoned', 'fix' => 'Fewer fields'],
            ['importance' => 'high', 'title' => 'Faster booking', 'why' => 'Worst 90 s, 20 taps', 'fix' => 'Fewer steps'],
        ],
        'claude_task' => "## Запись клиента\nСократи форму Book customer.\nДобавь тесты.",
    ]);

    $this->post(route('app-reviews.store'), ['period' => 'since_last'])->assertRedirect();

    expect($fake->requests)->toHaveCount(1)->and($fake->requests[0]['model'])->toBe('claude-opus-5-5');
    $input = json_decode($fake->requests[0]['messages'][0]['content'], true);
    expect($input['summary']['flows']['booking'])->toMatchArray(['finished' => 3, 'avg_seconds' => 55.0, 'avg_taps' => 12.7, 'worst_seconds' => [90.0, 45.0, 30.0], 'worst_taps' => [20, 10, 8]])
        ->and($input['summary']['searches'][0])->toMatchArray(['search' => 'customers', 'not_found' => 1])
        ->and($input['summary']['abandoned_forms'][0]['abandoned'])->toBe(1)
        ->and($input['summary']['inconvenient_notes'][0]['text'])->toBe('Start button too small')
        ->and(collect($input['previous_suggestions'])->pluck('status', 'title')->all())->toBe(['Dark mode' => 'not_needed', 'Bigger Start button' => 'done']);

    $review = inCompany($this->company, fn () => AppReview::query()->latest('id')->first());
    expect($review->period_from->toDateString())->toBe(now()->subDays(30)->toDateString())
        ->and($review->report['claude_task'])->toContain('## Запись клиента')
        ->and((float) $review->cost_usd)->toBeGreaterThan(0)
        ->and($review->suggestions->pluck('title')->all())->toBe(['Faster booking', 'Shorter estimate form']);

    $this->get(route('app-reviews.show', $review))->assertInertia(fn ($page) => $page->component('assistant/review')->where('review.suggestions.0.title', 'Faster booking'));
});

test('each suggestion gets Done, Not needed or Later', function () {
    $suggestion = inCompany($this->company, function () {
        $review = AppReview::create(['period_from' => now()->subDay(), 'period_to' => now(), 'period' => '1', 'summary' => [], 'report' => []]);

        return $review->suggestions()->create(['position' => 1, 'importance' => 'high', 'title' => 'A', 'why' => 'x', 'fix' => 'y']);
    });

    foreach (['done', 'not_needed', 'later'] as $status) {
        $this->put(route('app-reviews.suggestion', $suggestion), ['status' => $status])->assertRedirect();
        expect(inCompany($this->company, fn () => $suggestion->fresh()->status))->toBe($status);
    }
    $this->put(route('app-reviews.suggestion', $suggestion), ['status' => 'whatever'])->assertSessionHasErrors('status');
});

test('periods: 1, 3, 7 and 30 days', function () {
    $fake = FakeAiTransport::install(fn () => ['overview' => 'ok', 'suggestions' => [], 'claude_task' => '']);

    foreach (['1', '3', '7', '30'] as $days) {
        $this->post(route('app-reviews.store'), ['period' => $days])->assertRedirect();
        expect(inCompany($this->company, fn () => AppReview::query()->latest('id')->first()->period_from->toDateString()))->toBe(now()->subDays((int) $days)->toDateString());
    }
    $this->post(route('app-reviews.store'), ['period' => '365'])->assertSessionHasErrors('period');
    expect($fake->requests)->toHaveCount(4);
});

test('only the Owner can run or see reviews', function () {
    FakeAiTransport::install(fn () => ['overview' => 'ok', 'suggestions' => [], 'claude_task' => '']);

    foreach ([UserRole::Admin, UserRole::Technician] as $role) {
        $user = memberOf($this->company, $role);
        $this->actingAs($user)->get(route('app-reviews.index'))->assertForbidden();
        $this->actingAs($user)->post(route('app-reviews.store'), ['period' => '7'])->assertForbidden();
    }
    expect(AppReview::withoutCompanyScope()->count())->toBe(0);
});

test('the review never runs on a schedule', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) ($e->command ?? $e->description ?? ''))->implode("\n");

    expect($events)->not->toContain('review')->not->toContain('Review')
        ->and($events)->toContain('usage:prune');
});
