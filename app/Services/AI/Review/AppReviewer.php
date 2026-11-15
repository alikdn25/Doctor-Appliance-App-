<?php

namespace App\Services\AI\Review;

use App\Models\AppReview;
use App\Models\AppReviewSuggestion;
use App\Models\InconvenienceReport;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\AI\AiFeature;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * App review, only when the Owner presses the button: the code builds a summary of the period from the action log
 * (main flows: time and taps, average and worst; abandoned forms; frequent "back"; failed searches; errors; every
 * "Inconvenient" note), then one request to AI_MODEL_REVIEW, together with earlier suggestions and their statuses so
 * nothing done or declined is suggested again. The answer and its cost are kept; the AI changes nothing.
 */
class AppReviewer
{
    public const PERIODS = ['since_last', '1', '3', '7', '30'];

    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(string $period): array
    {
        $to = CarbonImmutable::now();

        if ($period === 'since_last') {
            $last = AppReview::query()->latest('id')->value('period_to');

            return [$last !== null ? CarbonImmutable::parse($last) : $to->subDays(30), $to];
        }

        return [$to->subDays((int) $period), $to];
    }

    /**
     * @throws AiUnavailable
     */
    public function run(User $user, string $period): AppReview
    {
        [$from, $to] = $this->period($period);
        $summary = $this->summary($from, $to);
        $previous = $this->previousSuggestions();

        $response = $this->ai->send(
            AiFeature::AppReview,
            currentCompany(),
            $user,
            self::instructions(),
            [['role' => 'user', 'content' => json_encode(['summary' => $summary, 'previous_suggestions' => $previous], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            schema: self::schema(),
            maxTokens: 16000,
            effort: 'high',
        );
        $answer = $response->json();

        return DB::transaction(function () use ($user, $from, $to, $period, $summary, $answer, $response) {
            $review = AppReview::create([
                'user_id' => $user->id,
                'period_from' => $from,
                'period_to' => $to,
                'period' => $period,
                'summary' => $summary,
                'report' => [
                    'overview' => (string) ($answer['overview'] ?? ''),
                    'claude_task' => (string) ($answer['claude_task'] ?? ''),
                ],
                'cost_usd' => AiService::cost(AiFeature::AppReview->model(), $response->usage),
            ]);

            $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
            collect((array) ($answer['suggestions'] ?? []))
                ->filter(fn ($s) => is_array($s) && is_string($s['title'] ?? null))
                ->sortBy(fn (array $s) => $rank[$s['importance'] ?? 'medium'] ?? 1)
                ->values()
                ->each(fn (array $s, int $i) => $review->suggestions()->create([
                    'position' => $i + 1,
                    'importance' => in_array($s['importance'] ?? null, ['high', 'medium', 'low'], true) ? $s['importance'] : 'medium',
                    'title' => mb_substr($s['title'], 0, 300),
                    'why' => mb_substr((string) ($s['why'] ?? ''), 0, 4000),
                    'fix' => mb_substr((string) ($s['fix'] ?? ''), 0, 4000),
                ]));

            return $review;
        });
    }

    /**
     * What the code found in the period. Only actions, screens, roles, times and counts.
     *
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = UsageEvent::query()->whereBetween('occurred_at', [$from, $to]);
        $of = fn (string $type) => (clone $events)->where('type', $type)->get();

        $flows = $of('flow')->groupBy('name')->map(function (Collection $runs) {
            $seconds = $runs->map(fn (UsageEvent $e) => round(($e->value ?? 0) / 1000, 1));
            $taps = $runs->map(fn (UsageEvent $e) => (int) ($e->meta['taps'] ?? 0));

            return [
                'finished' => $runs->count(),
                'avg_seconds' => round((float) $seconds->avg(), 1),
                'avg_taps' => round((float) $taps->avg(), 1),
                'worst_seconds' => $seconds->sortDesc()->take(3)->values()->all(),
                'worst_taps' => $taps->sortDesc()->take(3)->values()->all(),
                'by_role' => $runs->groupBy('role')->map->count()->all(),
            ];
        });
        foreach ($of('flow_abandoned')->groupBy('name') as $name => $runs) {
            $flows[$name] = [...($flows[$name] ?? ['finished' => 0]), 'abandoned' => $runs->count()];
        }

        $starts = $of('form_start')->groupBy(fn (UsageEvent $e) => $e->screen.' '.$e->name)->map->count();
        $abandoned = $of('form_abandon')->groupBy(fn (UsageEvent $e) => $e->screen.' '.$e->name)
            ->map(fn (Collection $g, string $form) => ['form' => trim($form), 'abandoned' => $g->count(), 'started' => $starts[$form] ?? $g->count()])
            ->sortByDesc('abandoned')->take(15)->values();

        $views = $of('screen_view')->groupBy('screen')->map->count();
        $backs = $of('back')->groupBy('screen')
            ->map(fn (Collection $g, string $screen) => ['screen' => $screen, 'back' => $g->count(), 'views' => $views[$screen] ?? 0])
            ->sortByDesc('back')->take(15)->values();

        $searches = $of('search')->groupBy('name')->map(fn (Collection $g, string $name) => [
            'search' => $name, 'total' => $g->count(), 'not_found' => $g->filter(fn (UsageEvent $e) => ($e->meta['found'] ?? true) === false)->count(),
        ])->sortByDesc('not_found')->values();

        $errors = $of('error')->groupBy(fn (UsageEvent $e) => $e->screen.' '.$e->name)
            ->map(fn (Collection $g, string $key) => ['where' => trim($key), 'count' => $g->count()])
            ->sortByDesc('count')->take(20)->values();

        $time = $of('screen_time')->groupBy('screen')
            ->map(fn (Collection $g, string $screen) => ['screen' => $screen, 'avg_seconds' => round((float) $g->avg('value') / 1000, 1), 'views' => $g->count()])
            ->sortByDesc('avg_seconds')->take(15)->values();

        $notes = InconvenienceReport::query()->whereBetween('created_at', [$from, $to])->orderBy('id')->get()
            ->map(fn (InconvenienceReport $r) => [
                'when' => $r->created_at->toDateString(), 'role' => $r->role, 'screen' => $r->screen, 'text' => $r->text,
                'last_actions' => collect($r->recent_actions)->map(fn (array $a) => trim(($a['type'] ?? '').' '.($a['screen'] ?? '').' '.($a['name'] ?? '')))->values()->all(),
            ])->values();

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'people_by_role' => (clone $events)->select('role', DB::raw('count(distinct user_id) as people'))->groupBy('role')->pluck('people', 'role')->all(),
            'events' => (clone $events)->count(),
            'flows' => $flows->all(),
            'abandoned_forms' => $abandoned->all(),
            'back' => $backs->all(),
            'searches' => $searches->all(),
            'errors' => $errors->all(),
            'slow_screens' => $time->all(),
            'inconvenient_notes' => $notes->all(),
        ];
    }

    /**
     * Earlier suggestions with the Owner's status (done / not_needed / later / open).
     *
     * @return list<array<string, string>>
     */
    public function previousSuggestions(): array
    {
        return AppReviewSuggestion::query()->latest('id')->limit(100)->get()
            ->map(fn (AppReviewSuggestion $s) => ['title' => $s->title, 'fix' => $s->fix, 'status' => $s->status ?? 'open'])
            ->values()->all();
    }

    private static function instructions(): string
    {
        return <<<'TEXT'
            Ты разбираешь удобство мобильного приложения для небольшой ремонтной компании (ремонт бытовой техники, handyman).
            Владелец, офис и техники работают с телефона, часто одной рукой. На входе JSON: summary — сводка действий за период
            (главные сценарии с временем и числом нажатий: среднее и худшие случаи; брошенные формы; частые «назад»; поиски
            без результата; ошибки, которые видели люди; медленные экраны; записи «Неудобно» с последними действиями) и
            previous_suggestions — прошлые предложения со статусами.

            Правила:
            - Пиши по-русски, простыми словами. Названия экранов и кнопок оставляй как в сводке.
            - Каждое предложение — что неудобно и почему, с цифрами из сводки (секунды, нажатия, сколько раз), и что исправить.
              Не выдумывай ничего, чего нет в сводке. Если данных мало — так и скажи в overview.
            - importance: high — мешает каждый день или теряются записи и деньги; medium — заметно мешает; low — мелочь.
            - Не повторяй прошлые предложения со статусом done (сделано) или not_needed (не надо). Со статусом later или open
              повторяй, только если цифры стали хуже, и скажи об этом.
            - claude_task — готовое задание для Claude Code одним блоком: заголовки простым русским языком, под ними прямые
              приказы (что сделать на каком экране), без объяснений. В конце: «Добавь тесты.» Только то, что в suggestions.
            - overview — 2–4 предложения: главное за период.
            TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'overview' => ['type' => 'string'],
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'importance' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'title' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                            'fix' => ['type' => 'string'],
                        ],
                        'required' => ['importance', 'title', 'why', 'fix'],
                        'additionalProperties' => false,
                    ],
                ],
                'claude_task' => ['type' => 'string'],
            ],
            'required' => ['overview', 'suggestions', 'claude_task'],
            'additionalProperties' => false,
        ];
    }
}
