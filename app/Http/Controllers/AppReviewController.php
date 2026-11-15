<?php

namespace App\Http\Controllers;

use App\Models\AppReview;
use App\Models\AppReviewSuggestion;
use App\Services\AI\AiLimitReached;
use App\Services\AI\AiUnavailable;
use App\Services\AI\Review\AppReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App review (Owner only), only when the Owner presses the button: past reviews, one review with its suggestions and
 * their statuses (Done / Not needed / Later).
 */
class AppReviewController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('runAppReview');

        return Inertia::render('assistant/reviews', [
            'reviews' => AppReview::query()->withCount('suggestions')->latest('id')->limit(100)->get()->map(fn (AppReview $r) => [
                'id' => $r->id,
                'created_at' => $r->created_at->toIso8601String(),
                'period_from' => $r->period_from->toIso8601String(),
                'period_to' => $r->period_to->toIso8601String(),
                'overview' => $r->report['overview'] ?? '',
                'suggestions' => $r->suggestions_count,
                'cost' => (float) $r->cost_usd,
            ])->values(),
            'periods' => AppReviewer::PERIODS,
        ]);
    }

    public function show(AppReview $review): Response
    {
        Gate::authorize('runAppReview');

        return Inertia::render('assistant/review', [
            'review' => [
                'id' => $review->id,
                'created_at' => $review->created_at->toIso8601String(),
                'period_from' => $review->period_from->toIso8601String(),
                'period_to' => $review->period_to->toIso8601String(),
                'overview' => $review->report['overview'] ?? '',
                'claude_task' => $review->report['claude_task'] ?? '',
                'cost' => (float) $review->cost_usd,
                'suggestions' => $review->suggestions->map(fn (AppReviewSuggestion $s) => $s->only(['id', 'importance', 'title', 'why', 'fix', 'status']))->values(),
            ],
        ]);
    }

    public function store(Request $request, AppReviewer $reviewer): RedirectResponse
    {
        Gate::authorize('runAppReview');

        $period = $request->validate(['period' => ['required', Rule::in(AppReviewer::PERIODS)]])['period'];

        try {
            $review = $reviewer->run($request->user(), $period);
        } catch (AiLimitReached) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('assistant.limit')]);

            return back();
        } catch (AiUnavailable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('assistant.review.failed')]);

            return back();
        }

        return to_route('app-reviews.show', $review);
    }

    public function status(Request $request, AppReviewSuggestion $suggestion): RedirectResponse
    {
        Gate::authorize('runAppReview');

        $status = $request->validate(['status' => ['nullable', Rule::in(AppReviewSuggestion::STATUSES)]])['status'] ?? null;
        $suggestion->forceFill(['status' => $status, 'status_at' => $status === null ? null : now()])->save();

        return back();
    }
}
