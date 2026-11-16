<?php

namespace App\Services\AI\Assistant;

use App\Enums\JobStatus;
use App\Enums\LineKind;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Support\Billing\DocumentTotals;
use App\Support\Billing\Money;
use App\Support\Locale\Currencies;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * An invoice the assistant read from photos or text (tool create_invoice), checked and priced by code: the AI only
 * passes the customer, the lines with their amounts and whether those amounts include tax. The company's default
 * taxes and the total are worked out here (DocumentTotals, as on the invoice form), never by the AI. Nothing is
 * stored: the result is the preview card's data, saved only after Confirm (AssistantInvoice).
 */
class InvoiceDraft
{
    /** Job statuses that count as closed: an invoice is never attached to these. */
    public const OPEN_JOB_EXCLUDED = [JobStatus::Draft, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled];

    /**
     * The tool's input schema (no nullable enums: missing text is "").
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => (object) [
                'customer_name' => ['type' => 'string', 'description' => 'First and last name (or company), as written. "" if not given.'],
                'phone' => ['type' => 'string', 'description' => 'Phone as written. "" if not given: then ask, never invent one.'],
                'email' => ['type' => 'string', 'description' => '"" if not given.'],
                'address' => [
                    'type' => 'object',
                    'description' => 'Service address; "" for any part not given.',
                    'properties' => (object) ['line1' => $text, 'unit' => $text, 'city' => $text, 'region' => $text, 'postal_code' => $text],
                    'required' => ['line1', 'unit', 'city', 'region', 'postal_code'],
                    'additionalProperties' => false,
                ],
                'lines' => [
                    'type' => 'array',
                    'description' => 'Work lines with their amounts, as written. Taxes are NOT lines: the app adds them.',
                    'items' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'description' => $text,
                            'amount' => ['type' => 'number', 'description' => 'Amount of the line in the currency, e.g. 150 or 89.99.'],
                        ],
                        'required' => ['description', 'amount'],
                        'additionalProperties' => false,
                    ],
                ],
                'amounts_include_tax' => ['type' => 'boolean', 'description' => 'true when the amounts given already include tax (e.g. "total $112 with tax"), false when tax is to be added on top.'],
                'notes' => ['type' => 'string', 'description' => 'A note for the invoice; "" if none.'],
            ],
            'required' => ['customer_name', 'phone', 'email', 'address', 'lines', 'amounts_include_tax', 'notes'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Checks the AI's input and prices it. Anything missing that cannot be guessed (the phone, an amount) comes back
     * as an error telling the AI what to ask. An address is optional: a job without one keeps an empty address.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> The preview: customer, lines (minor units, in the invoice's price mode), taxes, totals.
     *
     * @throws AssistantError
     */
    public static function build(array $input, Company $company): array
    {
        $rawPhone = trim((string) ($input['phone'] ?? ''));
        if ($rawPhone === '' || ! PhoneNumber::isPossible($rawPhone, $company->country)) {
            throw new AssistantError('The customer\'s phone number is missing or not valid. Ask the user for it; never invent a phone number.');
        }
        $phone = PhoneNumber::normalize($rawPhone, $company->country);

        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        $factor = Currencies::factor($company->currency);
        $lines = [];
        foreach ((array) ($input['lines'] ?? []) as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            $amount = (int) round(((float) ($line['amount'] ?? 0)) * $factor);

            if ($amount <= 0) {
                throw new AssistantError('A line has no amount'.($description !== '' ? " (\"{$description}\")" : '').'. Ask the user for the amount; never invent one.');
            }

            $lines[] = ['description' => $description !== '' ? mb_substr($description, 0, 500) : __('assistant.invoice.default_line'), 'amount' => $amount];
        }

        if ($lines === []) {
            throw new AssistantError('There are no work lines with amounts. Ask the user what the work was and how much it costs.');
        }

        $address = collect((array) ($input['address'] ?? []))
            ->only(['line1', 'unit', 'city', 'region', 'postal_code'])
            ->map(fn ($value) => trim((string) $value))
            ->all();
        $hasAddress = ($address['line1'] ?? '') !== '';

        [$customer, $matchedBy] = self::findCustomer($phone, $email);
        $name = trim((string) ($input['customer_name'] ?? ''));

        if ($customer === null && $name === '') {
            throw new AssistantError('The customer\'s name is missing. Ask the user for it.');
        }

        $job = $customer === null ? null : self::openJob($customer);

        $includeTax = (bool) ($input['amounts_include_tax'] ?? false);
        $taxes = self::taxes();
        $pricesIncludeTax = (bool) $company->prices_include_tax;
        $prices = self::prices(array_column($lines, 'amount'), $taxes, $includeTax, $pricesIncludeTax, $factor);

        $totals = DocumentTotals::calculate(
            array_map(fn (int $price) => ['quantity' => 1, 'unit_price' => $price, 'taxable' => true], $prices),
            null,
            0,
            $taxes,
            $pricesIncludeTax,
            $factor,
        );

        [$first, $last] = self::splitName($name);

        return [
            'customer' => [
                'id' => $customer?->id,
                'matched_by' => $matchedBy,
                'name' => $customer?->display_name ?? $name,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'email' => $email !== '' ? $email : null,
                'address' => $hasAddress ? $address : null,
            ],
            'job_id' => $job?->id,
            'job_number' => $job?->number,
            'lines' => array_map(fn (array $line, int $price, int $total) => [
                'description' => $line['description'],
                'unit_price' => $price,
                'total' => $total,
            ], $lines, $prices, $totals['item_totals']),
            'tax_rate_ids' => array_values(array_filter(array_column($taxes, 'tax_rate_id'))),
            'taxes' => $totals['taxes'],
            'subtotal' => $totals['subtotal'],
            'tax_total' => $totals['tax_total'],
            'total' => $totals['total'],
            'currency' => $company->currency,
            'prices_include_tax' => $pricesIncludeTax,
            'amounts_include_tax' => $includeTax,
            'notes' => ($notes = trim((string) ($input['notes'] ?? ''))) !== '' ? mb_substr($notes, 0, 2000) : null,
        ];
    }

    /**
     * The customer with this phone, else with this email (current company only).
     *
     * @return array{0: Customer|null, 1: 'phone'|'email'|null}
     */
    public static function findCustomer(string $phone, string $email): array
    {
        $customer = CustomerPhone::query()->where('number_normalized', $phone)->whereHas('customer')->with('customer')->orderBy('id')->first()?->customer;
        if ($customer !== null) {
            return [$customer, 'phone'];
        }

        if ($email !== '') {
            $customer = CustomerEmail::query()->whereRaw('LOWER(email) = ?', [$email])->whereHas('customer')->with('customer')->orderBy('id')->first()?->customer;
            if ($customer !== null) {
                return [$customer, 'email'];
            }
        }

        return [null, null];
    }

    /** The customer's latest job that is not closed (not invoiced, paid, cancelled or a draft booking). */
    public static function openJob(Customer $customer): ?ServiceJob
    {
        return ServiceJob::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', array_map(fn (JobStatus $s) => $s->value, self::OPEN_JOB_EXCLUDED))
            ->latest('id')
            ->first();
    }

    /**
     * The company's default taxes that apply to labour (work) lines, compound ones last.
     *
     * @return list<array{tax_rate_id: int, name: string, rate: string, compound: bool}>
     */
    public static function taxes(): array
    {
        /** @var Collection<int, TaxRate> $rates */
        $rates = TaxRate::query()->where('is_active', true)->where('is_default', true)
            ->orderBy('is_compound')->orderBy('sort_order')->orderBy('name')->get();

        return $rates->filter(fn (TaxRate $rate) => $rate->appliesTo(LineKind::Service))
            ->map(fn (TaxRate $rate) => ['tax_rate_id' => $rate->id, 'name' => $rate->name, 'rate' => (string) $rate->rate, 'compound' => $rate->is_compound])
            ->values()->all();
    }

    /**
     * Line prices in the invoice's price mode. Amounts given with tax on an invoice without tax in its prices: the
     * amount without tax is worked out from the total with tax (and spread over the lines), so that the invoice
     * total comes out as the total given. Amounts without tax on an invoice with tax in its prices get the tax added.
     *
     * @param  list<int>  $amounts
     * @param  list<array{tax_rate_id: int, name: string, rate: string, compound: bool}>  $taxes
     * @return list<int>
     */
    public static function prices(array $amounts, array $taxes, bool $amountsIncludeTax, bool $pricesIncludeTax, int $factor = 100): array
    {
        if ($taxes === [] || $amountsIncludeTax === $pricesIncludeTax) {
            return $amounts;
        }

        $gross = 1.0;
        foreach ($taxes as $tax) {
            $rate = (float) $tax['rate'] / 100;
            $gross += $tax['compound'] ? $gross * $rate : $rate;
        }

        if (! $amountsIncludeTax) {
            // Without tax given, prices with tax wanted: each line with its tax added.
            return array_map(fn (int $amount) => (int) round($amount * $gross), $amounts);
        }

        $target = array_sum($amounts);
        $net = (int) round($target / $gross);
        // Rounding of each tax can move the total by a cent: take the net that gives back the total given.
        foreach ([0, -1, 1, -2, 2] as $step) {
            $totals = DocumentTotals::calculate([['quantity' => 1, 'unit_price' => $net + $step, 'taxable' => true]], null, 0, $taxes, false, $factor);
            if ($totals['total'] === $target) {
                $net += $step;
                break;
            }
        }

        return self::spread($net, $amounts);
    }

    /**
     * Spreads a total over lines in proportion to their amounts; the last line takes the rounding.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private static function spread(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $result = [];
        $left = $total;

        foreach ($weights as $index => $weight) {
            $share = $index === array_key_last($weights) ? $left : (int) round($total * $weight / $sum);
            $result[] = $share;
            $left -= $share;
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [$parts[0] ?? '', isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null];
    }

    /**
     * One line for the change log and the chat card's text.
     *
     * @param  array<string, mixed>  $draft
     */
    public static function summary(array $draft, Company $company): string
    {
        return __('assistant.invoice.summary', [
            'customer' => $draft['customer']['name'],
            'phone' => $draft['customer']['phone'],
            'total' => Money::format($draft['total'], $draft['currency'], $company->locale),
        ]);
    }
}
