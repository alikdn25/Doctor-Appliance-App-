<?php

namespace App\Services\AI\Sms;

use App\Enums\ApplianceType;
use App\Models\Appliance;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Message;
use App\Support\Billing\Money;
use App\Support\Billing\PaymentReminders;

/**
 * What the AI's reply drafts may say, and the parts of a draft written by code (owner's task of November 19):
 *
 * - Prices and answers (company settings): the diagnostic fee, service areas, business hours, free notes. A price that
 *   is not there is never named.
 * - A text about a breakdown with few details: at most 2 short questions (cut by code).
 * - The customer's appliance has no model number yet: always ask for a photo of the sticker with the model number,
 *   with where to find it on that appliance (added by code, never left to the AI).
 * - An answer to a soft payment reminder: the draft has the amount and the invoice link (added by code when missing).
 *
 * Must run in a tenant context.
 */
class ReplyGuide
{
    public const MAX_QUESTIONS = 2;

    /**
     * The block of facts the AI may use, for its instructions.
     */
    public static function facts(Company $company): string
    {
        $lines = array_filter([
            $company->diagnostic_fee !== null ? 'Diagnostic fee: '.Money::format($company->diagnostic_fee, $company->currency, $company->locale) : null,
            filled($company->service_areas) ? 'Service areas: '.trim((string) $company->service_areas) : null,
            filled($company->hours_text) ? 'Business hours: '.trim((string) $company->hours_text) : null,
            filled($company->answer_notes) ? 'Notes: '.trim((string) $company->answer_notes) : null,
        ]);

        return $lines === [] ? '(none given)' : implode("\n", $lines);
    }

    /**
     * Whether the customer has an appliance with a known model number (on their open jobs or at their addresses).
     */
    public static function modelKnown(Message $message): bool
    {
        if ($message->customer_id === null) {
            return false;
        }

        return Appliance::query()->whereHas('property', fn ($q) => $q->where('customer_id', $message->customer_id))
            ->whereNotNull('model_number')->where('model_number', '!=', '')->exists();
    }

    /**
     * Where the sticker with the model number is, for the appliance named (or the customer's own).
     */
    public static function stickerLine(?string $type, Message $message): string
    {
        $type = ApplianceType::tryFrom((string) $type);
        if ($type === null || $type === ApplianceType::Other) {
            $type = $message->customer_id === null ? null : Appliance::query()
                ->whereHas('property', fn ($q) => $q->where('customer_id', $message->customer_id))->latest('id')->first()?->type;
        }

        $where = match ($type) {
            ApplianceType::Refrigerator, ApplianceType::Freezer, ApplianceType::WineCooler => 'fridge',
            ApplianceType::Washer, ApplianceType::WasherDryerCombo => 'washer',
            ApplianceType::Dryer => 'dryer',
            ApplianceType::Dishwasher => 'dishwasher',
            ApplianceType::Range, ApplianceType::Oven, ApplianceType::Cooktop => 'range',
            default => null,
        };

        return $where === null ? __('answers.sticker.any') : __('answers.sticker.ask', ['where' => __("answers.sticker.where.{$where}")]);
    }

    /**
     * The final draft: the AI's reply, at most 2 of its questions, the sticker request when no model is known and the
     * text is about a breakdown, and the amount and link for an answer to a payment reminder.
     *
     * @param  array<string, mixed>  $raw  The AI's answer
     * @return array{reply_draft: string|null, questions: list<string>, sticker: bool, clarifying: string|null}
     */
    public static function compose(array $raw, Message $message, ?Invoice $invoice): array
    {
        $draft = trim((string) ($raw['reply_draft'] ?? ''));
        $breakdown = ($raw['about_breakdown'] ?? false) === true;
        $questions = $breakdown ? array_slice(array_values(array_filter(array_map(
            fn ($q) => mb_substr(trim((string) $q), 0, 160), (array) ($raw['questions'] ?? []),
        ))), 0, self::MAX_QUESTIONS) : [];
        $sticker = $breakdown && ! self::modelKnown($message);
        $stickerLine = $sticker ? self::stickerLine(is_string($raw['appliance_type'] ?? null) ? $raw['appliance_type'] : null, $message) : null;

        $extra = array_values(array_filter([...$questions, $stickerLine]));
        $clarifying = $extra === [] ? null : implode(' ', $extra);

        if ($invoice !== null) {
            $facts = PaymentReminders::facts($invoice);
            if (! str_contains($draft, $facts['link'])) {
                $draft = trim($draft.' '.__('answers.debt_line', ['amount' => $facts['amount'], 'link' => $facts['link']]));
            }
        }

        $full = trim($draft.($clarifying !== null ? ' '.$clarifying : ''));

        return [
            'reply_draft' => $full !== '' ? mb_substr($full, 0, 900) : null,
            'questions' => $questions,
            'sticker' => $sticker,
            'clarifying' => $clarifying,
        ];
    }
}
