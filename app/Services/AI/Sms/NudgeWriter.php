<?php

namespace App\Services\AI\Sms;

use App\Models\Company;
use App\Models\Message;
use App\Models\SmsThread;
use App\Models\User;
use App\Services\AI\AiFeature;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;

/**
 * "Text again" on a "customer is quiet" reminder (owner's task of November 19): a short, polite follow-up to our
 * unanswered text, written by AI_MODEL_BOOKING in the language of the conversation. It only fills the reply box; the
 * person sends it with one tap. Without the AI (or past the daily limit) a plain follow-up from the translations.
 * Must run in a tenant context.
 */
class NudgeWriter
{
    public function __construct(private readonly AiService $ai) {}

    public function draft(SmsThread $thread, Company $company, ?User $user): string
    {
        if ($thread->silent_draft !== null) {
            return $thread->silent_draft;
        }

        $ours = Message::query()->with('customer')->find($thread->silent_message_id);
        $name = $ours?->customer?->first_name;
        $fallback = $name ? __('automation.quiet.fallback_named', ['name' => $name]) : __('automation.quiet.fallback');

        try {
            $history = Message::query()->where(fn ($q) => $q->where('from', $thread->phone)->orWhere('to', $thread->phone))
                ->where('channel', Message::SMS)->latest('id')->limit(6)->get()->reverse()
                ->map(fn (Message $m) => ($m->direction === Message::INBOUND ? 'Customer: ' : 'Company: ').mb_substr($m->body, 0, 400))
                ->implode("\n");
            $text = trim((string) ($this->ai->send(AiFeature::SmsNudge, $company, $user, self::instructions($company),
                [['role' => 'user', 'content' => "Conversation:\n{$history}\n\nCustomer's first name: ".($name ?: 'unknown')]],
                schema: self::schema(), maxTokens: 300, effort: 'low')->json()['text'] ?? ''));
        } catch (AiUnavailable) {
            $text = '';
        }

        $draft = mb_substr($text !== '' ? $text : $fallback, 0, 320);
        $thread->forceFill(['silent_draft' => $draft])->save();

        return $draft;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }

    private static function instructions(Company $company): string
    {
        return <<<TEXT
            You write a follow-up text message (SMS) for {$company->name}, a repair company. The company's last text got
            no answer. Write one short, friendly reminder (max 160 characters) in the language of the conversation,
            asking the customer to reply to the company's last message. No pressure, no new promises, no prices, no
            links. Use the first name when known. Return only the text.
            TEXT;
    }
}
