<?php

namespace App\Http\Controllers;

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Services\AI\AiLimitReached;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\Assistant\AssistantActions;
use App\Services\AI\Assistant\AssistantChat;
use App\Services\AI\QuickBookingParser;
use App\Support\Customers\CustomerFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The AI assistant (More → Assistant), Owner only: a chat like the SMS screen. It reads the company's data with
 * read-only tools and proposes actions that run only after Confirm.
 */
class AssistantController extends Controller
{
    public function index(Request $request, AssistantChat $chat, AiService $ai): Response
    {
        Gate::authorize('useAssistant');

        $conversation = $chat->current($request->user());

        return Inertia::render('assistant/index', [
            'conversation' => $conversation,
            'items' => $chat->items($request->user(), $conversation),
            'configured' => $ai->configured(),
        ]);
    }

    public function ask(Request $request, AssistantChat $chat): JsonResponse
    {
        Gate::authorize('useAssistant');

        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation' => ['required', 'uuid'],
        ]);
        $user = $request->user();
        $conversation = $data['conversation'];

        // A conversation id belongs to one person; another person's id is refused.
        abort_if(AssistantMessage::withoutCompanyScope()->where('conversation', $conversation)->where('user_id', '!=', $user->id)->exists(), 403);

        try {
            $chat->ask($user, $conversation, trim($data['question']));
            $error = null;
            $status = 200;
        } catch (AiLimitReached) {
            [$error, $status] = [__('assistant.limit'), 429];
        } catch (AiUnavailable) {
            [$error, $status] = [__('assistant.failed'), 503];
        }

        return response()->json(['items' => $chat->items($user, $conversation), 'message' => $error], $status);
    }

    public function fresh(): JsonResponse
    {
        Gate::authorize('useAssistant');

        return response()->json(['conversation' => (string) Str::uuid(), 'items' => []]);
    }

    public function confirm(Request $request, AssistantAction $action, AssistantActions $actions, QuickBookingParser $parser, CustomerFinder $finder): JsonResponse
    {
        Gate::authorize('useAssistant');
        abort_unless($action->user_id === $request->user()->id, 403);

        if ($action->status !== 'pending') {
            return response()->json(['action' => AssistantChat::action($action)], 409);
        }

        // Taken first, so a double tap cannot run it twice.
        $taken = AssistantAction::query()->whereKey($action->id)->where('status', 'pending')->update(['status' => 'running']);
        abort_if($taken === 0, 409);

        try {
            $result = $actions->confirm($action, $request->user(), $parser, $finder);
            $action->forceFill(['status' => 'confirmed', 'result' => $result['message'], 'decided_at' => now()])->save();
        } catch (ValidationException $e) {
            $action->forceFill(['status' => 'failed', 'result' => collect($e->errors())->flatten()->first(), 'decided_at' => now()])->save();
        } catch (AiUnavailable) {
            $action->forceFill(['status' => 'failed', 'result' => __('assistant.failed'), 'decided_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
            $action->forceFill(['status' => 'failed', 'result' => __('assistant.actions.not_possible'), 'decided_at' => now()])->save();
        }

        return response()->json(['action' => AssistantChat::action($action), 'booking' => $result['booking'] ?? null]);
    }

    public function cancel(Request $request, AssistantAction $action): JsonResponse
    {
        Gate::authorize('useAssistant');
        abort_unless($action->user_id === $request->user()->id, 403);

        AssistantAction::query()->whereKey($action->id)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'decided_at' => now()]);

        return response()->json(['action' => AssistantChat::action($action->refresh())]);
    }
}
