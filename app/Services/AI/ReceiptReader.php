<?php

namespace App\Services\AI;

use App\Models\BusinessExpenseCategory;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Locale\Currencies;
use App\Support\Locale\RelativeDate;
use Illuminate\Http\UploadedFile;

/**
 * A receipt photo in Expenses / My expenses (owner's task of November 18): AI_MODEL_ASSISTANT reads the merchant, the
 * date, the total, the taxes printed on it (GST, PST, …) and picks a category. Code turns it into the expense form:
 * taxes are matched to the company's tax names, the amount before tax is the total minus those taxes. Nothing is
 * saved here: the filled form is checked and saved by the person, with the photo as the receipt.
 */
class ReceiptReader
{
    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array<string, mixed> Expense form fields (amounts as decimal strings in the company currency).
     *
     * @throws AiUnavailable
     */
    public function read(UploadedFile $photo, User $user, bool $withTaxes): array
    {
        $company = currentCompany();
        $categories = BusinessExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $type = in_array($photo->getMimeType(), self::MEDIA_TYPES, true) ? (string) $photo->getMimeType() : 'image/jpeg';

        $raw = $this->ai->send(
            AiFeature::Receipt,
            $company,
            $user,
            'You read a purchase receipt for the bookkeeping of a repair company. Copy what is printed; never guess an '
            .'amount ("" / 0 when it cannot be read). total = the amount paid including taxes. taxes = each sales tax line '
            .'printed (name as printed, e.g. GST, PST, HST, Sales tax, and its amount). date as YYYY-MM-DD. category: one '
            .'of the company categories when one fits, else a short new name. description: what was bought, short. '
            .'Currency: '.$company->currency.'. Company categories: '.($categories->pluck('name')->implode(', ') ?: '(none)').'.',
            [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $type, 'data' => base64_encode((string) file_get_contents($photo->getRealPath()))]],
                ['type' => 'text', 'text' => 'Read this receipt.'],
            ]]],
            schema: self::schema(),
            maxTokens: 800,
        )->json();

        $currency = $company->currency;
        $factor = Currencies::factor($currency);
        $minor = fn ($value) => max(0, (int) round(((float) $value) * $factor));
        $major = fn (int $value) => number_format($value / $factor, Currencies::decimals($currency), '.', '');
        $total = $minor($raw['total'] ?? 0);

        // Taxes printed on the receipt, matched to the company's taxes by name.
        $rates = TaxRate::query()->where('is_active', true)->get();
        $named = [];
        $unmatched = 0;
        foreach ((array) ($raw['taxes'] ?? []) as $tax) {
            $amount = $minor($tax['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $rate = $rates->first(fn (TaxRate $r) => mb_strtolower(trim($r->name)) === mb_strtolower(trim((string) ($tax['name'] ?? ''))));
            if ($rate !== null && ! isset($named[$rate->id])) {
                $named[$rate->id] = $amount;
            } else {
                $unmatched += $amount;
            }
        }
        $taxTotal = array_sum($named) + $unmatched;

        $categoryName = trim((string) ($raw['category'] ?? ''));
        $category = $categories->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower($categoryName));

        $fields = [
            'merchant' => mb_substr(trim((string) ($raw['merchant'] ?? '')), 0, 150),
            'spent_on' => RelativeDate::resolve((string) ($raw['date'] ?? ''), $company),
            'description' => mb_substr(trim((string) ($raw['description'] ?? '')) ?: trim((string) ($raw['merchant'] ?? '')), 0, 255),
            'category_id' => $category?->id,
            'new_category' => $category === null && $categoryName !== '' ? mb_substr($categoryName, 0, 80) : null,
        ];

        // My expenses (technicians): only what was paid.
        if (! $withTaxes) {
            return [...$fields, 'amount' => $major($total), 'use_named_taxes' => false, 'tax_rate_ids' => [], 'tax_amounts' => [], 'tax_amount' => '0'];
        }

        $useNamed = $unmatched === 0 && $named !== [];

        return [
            ...$fields,
            'amount' => $major(max(0, $total - $taxTotal)),
            'use_named_taxes' => $useNamed,
            'tax_rate_ids' => $useNamed ? array_keys($named) : [],
            'tax_amounts' => $useNamed ? array_map($major, $named) : [],
            'tax_amount' => $major($taxTotal),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'merchant' => $text,
                'date' => $text,
                'total' => ['type' => 'number'],
                'taxes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['name' => $text, 'amount' => ['type' => 'number']],
                    'required' => ['name', 'amount'],
                    'additionalProperties' => false,
                ]],
                'category' => $text,
                'description' => $text,
            ],
            'required' => ['merchant', 'date', 'total', 'taxes', 'category', 'description'],
            'additionalProperties' => false,
        ];
    }
}
