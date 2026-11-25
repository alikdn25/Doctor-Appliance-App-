<?php

namespace App\Http\Requests\Billing;

use App\Enums\PaymentMethod;
use App\Support\Billing\MoneyInput;
use App\Support\Locale\Currencies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual payment: amount (major units of the invoice currency, e.g. dollars), method, optional reference, note for "other".
 */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPayment', $this->route('invoice'));
    }

    protected function prepareForValidation(): void
    {
        $this->replace(MoneyInput::cleanPaths($this->input(), ['amount', 'tip', 'cash_received']));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Nothing is required (owner's task of November 25): no amount is the balance, no method is "Other".
            'amount' => ['nullable', 'numeric', 'gt:0', DocumentRequest::moneyRule($this->currency())],
            // "Other" is not offered; it is only what a payment marked without a method is saved as.
            'method' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::manual(currentCompany())))],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'image', 'max:8192'],
            'received_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => __('payments.fields.amount'),
            'method' => __('payments.fields.method'),
            'reference' => $this->input('method') === PaymentMethod::CardTerminal->value
                ? __('payments.fields.transaction_reference')
                : __('payments.fields.reference'),
            'note' => __('payments.fields.note'),
            'received_on' => __('payments.fields.received_on'),
        ];
    }

    public function amount(): int
    {
        $amount = $this->validated('amount');

        return filled($amount)
            ? Currencies::toMinor($amount, $this->currency())
            : max(0, (int) $this->route('invoice')->balance);
    }

    private function currency(): string
    {
        return $this->route('invoice')->currency;
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->validated('method')) ?? PaymentMethod::Other;
    }

    /**
     * Today means now; an earlier date (a check received yesterday) is noon of that day in the company's zone.
     */
    public function receivedAt(): CarbonInterface
    {
        $date = $this->validated('received_on');

        if ($date === null || $date === $this->today()) {
            return now();
        }

        return CarbonImmutable::parse("{$date} 12:00", currentCompany()->timezone)->utc();
    }

    private function today(): string
    {
        return CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d');
    }
}
