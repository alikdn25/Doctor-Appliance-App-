<?php

namespace App\Services\AI\Assistant;

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\ProblemReport;
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

        if ($last === null || ! $this->usable($last->conversation)) {
            return (string) Str::uuid();
        }

        return $last->conversation;
    }

    /**
     * A conversation can go on while it is short enough and was started with today's tools.
     */
    public function usable(string $conversation): bool
    {
        $messages = AssistantMessage::query()->where('conversation', $conversation);

        return (clone $messages)->count() < self::MAX_MESSAGES
            && ! (clone $messages)->where('tools_version', '!=', AssistantTools::VERSION)->exists();
    }

    /**
     * Asks one question and runs the tools the AI calls until it answers.
     *
     * @throws AiUnavailable
     */
    /**
     * @param  list<array{type: 'image', path: string, media_type: string}>  $photos  Stored photos sent with the question.
     */
    public function ask(User $user, string $conversation, string $question, array $photos = []): void
    {
        $company = currentCompany();
        $now = CarbonImmutable::now($company->timezone);
        $prefix = $photos === [] ? '' : '[Photos attached: '.count($photos)."]\n";
        $this->append($user, $conversation, 'user', [...$photos, ['type' => 'text', 'text' => "[Now: {$now->format('Y-m-d H:i, l')}]\n{$prefix}{$question}"]], $question);

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
            ->map(fn (AssistantMessage $m) => ['kind' => 'message', 'id' => 'm'.$m->id, 'from' => $m->role, 'text' => $m->text, 'photos' => self::photoUrls($m), 'at' => $m->created_at->toIso8601String(), 'sort' => [$m->id, 0, 0]]);

        $actions = AssistantAction::query()
            ->where('user_id', $user->id)->where('conversation', $conversation)
            ->orderBy('id')->get()
            ->map(fn (AssistantAction $a) => self::action($a) + ['sort' => [(int) $a->after_message_id, 1, $a->id]]);

        $reports = ProblemReport::query()
            ->where('user_id', $user->id)->where('conversation', $conversation)
            ->orderBy('id')->get()
            ->map(fn (ProblemReport $r) => self::report($r) + ['sort' => [(int) $r->after_message_id, 2, $r->id]]);

        return $messages->concat($actions)->concat($reports)->sort(fn (array $a, array $b) => $a['sort'] <=> $b['sort'])->map(fn (array $item) => collect($item)->except('sort')->all())->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function action(AssistantAction $a): array
    {
        return [
            'kind' => 'action', 'id' => 'a'.$a->id, 'action_id' => $a->id, 'type' => $a->type, 'summary' => $a->summary,
            'status' => $a->status, 'result' => $a->result, 'at' => $a->created_at->toIso8601String(),
            // The invoice preview card: customer, lines, taxes and total as calculated by the app.
            'invoice' => $a->type === AssistantAction::INVOICE ? [
                ...collect($a->params['draft'])->only(['customer', 'job_number', 'lines', 'taxes', 'subtotal', 'tax_total', 'total', 'currency', 'prices_include_tax', 'amounts_include_tax', 'notes'])->all(),
                'photos' => count((array) ($a->params['photos'] ?? [])),
            ] : null,
        ];
    }

    /**
     * Links to the photos sent with a question (served after an access check).
     *
     * @return list<string>
     */
    private static function photoUrls(AssistantMessage $message): array
    {
        if ($message->role !== 'user') {
            return [];
        }

        return array_map(
            fn (int $index) => route('assistant.photo', ['message' => $message->id, 'index' => $index]),
            array_keys(AssistantPhotos::of($message)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(ProblemReport $r): array
    {
        return ['kind' => 'problems', 'id' => 'p'.$r->id, 'summary' => $r->report['summary'] ?? '', 'problems' => $r->report['problems'] ?? [], 'at' => $r->created_at->toIso8601String()];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function append(User $user, string $conversation, string $role, array $content, ?string $text): void
    {
        AssistantMessage::create(['user_id' => $user->id, 'conversation' => $conversation, 'role' => $role, 'content' => $content, 'text' => $text, 'tools_version' => AssistantTools::VERSION]);
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
              opening a new booking, or an invoice (create_invoice). Use those tools; the owner sees a card with buttons.
              Never say an action is done: it is done only after they press Confirm. You cannot delete or change anything
              else; say so if asked.
            - Invoices from photos or screenshots (a work order, a note, a message): read the customer's name, phone, email,
              address and the work lines with their amounts, and whether the amounts include tax, then call create_invoice.
              Never add tax lines or a total yourself: the app calculates the company's taxes and the total. If the phone
              number or an amount is missing or unreadable, ask the owner for it; never invent or guess one.
            - "Today", "tomorrow", "this week" count from the current date in the company time zone.
            TEXT;
    }
}
