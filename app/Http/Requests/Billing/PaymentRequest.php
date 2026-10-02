<?php

namespace App\Http\Requests\Billing;

use App\Enums\PaymentMethod;
use App\Support\Locale\Currencies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual payment: amount (major units of the invoice currency, e.g. dollars), method, reference for the own terminal, note for "other".
 */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordPayment', $this->route('invoice'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', DocumentRequest::moneyRule($this->currency())],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::manual()))],
            'reference' => ['nullable', 'string', 'max:100', Rule::requiredIf($this->input('method') === PaymentMethod::CardTerminal->value)],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('method') === PaymentMethod::Other->value)],
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
        return Currencies::toMinor($this->validated('amount'), $this->currency());
    }

    private function currency(): string
    {
        return $this->route('invoice')->currency;
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('method'));
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
