<?php

namespace App\Services\AI\Assistant;

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\AI\AiFeature;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The assistant's chat (always AI_MODEL_ASSISTANT). The conversation is kept exactly as sent to the AI and only ever
 * appended to; the system prompt and the tools never change inside a conversation (the current time goes with each
 * question instead), so earlier answers stay valid. Tools are read-only; actions are only proposed.
 */
class AssistantChat
{
    /** Tool rounds in one answer before the assistant stops. */
    private const MAX_ROUNDS = 8;

    /** Messages in one conversation before a new one starts by itself. */
    private const MAX_MESSAGES = 120;

    public function __construct(
        private readonly AiService $ai,
        private readonly AssistantTools $tools,
    ) {}

    /** The person's current conversation (the latest one), or a new id. */
    public function current(User $user): string
    {
        $last = AssistantMessage::query()->where('user_id', $user->id)->latest('id')->first();

        if ($last === null || AssistantMessage::query()->where('conversation', $last->conversation)->count() >= self::MAX_MESSAGES) {
            return (string) Str::uuid();
        }

        return $last->conversation;
    }

    /**
     * Asks one question and runs the tools the AI calls until it answers.
     *
     * @throws AiUnavailable
     */
    public function ask(User $user, string $conversation, string $question): void
    {
        $company = currentCompany();
        $now = CarbonImmutable::now($company->timezone);
        $this->append($user, $conversation, 'user', [['type' => 'text', 'text' => "[Now: {$now->format('Y-m-d H:i, l')}]\n{$question}"]], $question);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $response = $this->ai->send(
                AiFeature::Assistant,
                $company,
                $user,
                $this->system(),
                $this->history($user, $conversation),
                tools: AssistantTools::definitions(),
                maxTokens: 8000,
                effort: 'medium',
            );

            $text = $response->text();
            $this->append($user, $conversation, 'assistant', $response->content, $text !== '' ? $text : null);

            $calls = $response->toolUses();
            if ($response->stopReason !== 'tool_use' || $calls === []) {
                return;
            }

            $results = array_map(fn (array $call) => [
                'type' => 'tool_result',
                'toolUseID' => $call['id'],
                'content' => $this->tools->run($call['name'], (array) $call['input'], $user, $conversation),
            ], $calls);
            $this->append($user, $conversation, 'user', $results, null);
        }

        $this->append($user, $conversation, 'user', [['type' => 'text', 'text' => 'Stop using tools now and answer with what you have.']], null);
        $response = $this->ai->send(AiFeature::Assistant, $company, $user, $this->system(), $this->history($user, $conversation), tools: AssistantTools::definitions(), maxTokens: 4000, effort: 'medium');
        $this->append($user, $conversation, 'assistant', $response->content, $response->text() ?: __('assistant.gave_up'));
    }

    /**
     * What the chat screen shows: questions, answers and action cards, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function items(User $user, string $conversation): array
    {
        $messages = AssistantMessage::query()
            ->where('user_id', $user->id)->where('conversation', $conversation)->whereNotNull('text')
            ->orderBy('id')->get()
            ->map(fn (AssistantMessage $m) => ['kind' => 'message', 'id' => 'm'.$m->id, 'from' => $m->role, 'text' => $m->text, 'at' => $m->created_at->toIso8601String(), 'sort' => $m->created_at->getTimestampMs() * 10]);

        $actions = AssistantAction::query()
            ->where('user_id', $user->id)->where('conversation', $conversation)
            ->orderBy('id')->get()
            ->map(fn (AssistantAction $a) => self::action($a) + ['sort' => $a->created_at->getTimestampMs() * 10 + 5]);

        return $messages->concat($actions)->sortBy([['sort', 'asc']])->map(fn (array $item) => collect($item)->except('sort')->all())->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function action(AssistantAction $a): array
    {
        return ['kind' => 'action', 'id' => 'a'.$a->id, 'action_id' => $a->id, 'type' => $a->type, 'summary' => $a->summary, 'status' => $a->status, 'result' => $a->result, 'at' => $a->created_at->toIso8601String()];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function append(User $user, string $conversation, string $role, array $content, ?string $text): void
    {
        AssistantMessage::create(['user_id' => $user->id, 'conversation' => $conversation, 'role' => $role, 'content' => $content, 'text' => $text]);
    }

    /**
     * The conversation as Messages API messages, oldest first.
     *
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function history(User $user, string $conversation): array
    {
        return AssistantMessage::query()
            ->where('user_id', $user->id)->where('conversation', $conversation)
            ->orderBy('id')->get()
            ->map(fn (AssistantMessage $m) => ['role' => $m->role, 'content' => (array) $m->content])
            ->values()->all();
    }

    /** Fixed for the company, so it never changes inside a conversation. */
    private function system(): string
    {
        $company = currentCompany();

        return <<<TEXT
            You are the assistant inside the field service app of {$company->name}, a small repair company (appliance repair,
            handyman). You help the owner with their own business data. Company time zone: {$company->timezone}. Currency:
            {$company->currency}. Each question starts with the current date and time.

            - Answer in the language the owner writes in. Be short and concrete: numbers, names, dates. Use lists for several
              items. Mention job, invoice and estimate numbers so they can be found.
            - Look up data with the tools; never guess numbers. Tools only read data of this company.
            - You can only propose actions: an SMS payment reminder for an invoice, moving a booking to another date or window,
              or opening a new booking. Use the propose_* tools; the owner sees a card with Confirm and Cancel. Never say an
              action is done: it is done only after they press Confirm. You cannot delete or change anything else; say so if
              asked.
            - "Today", "tomorrow", "this week" count from the current date in the company time zone.
            TEXT;
    }
}
