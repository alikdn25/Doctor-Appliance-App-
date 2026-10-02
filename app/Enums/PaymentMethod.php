<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use App\Models\Company;

/**
 * How a payment was made. The manual methods are available in every company;
 * "online" payments are recorded by a payment provider (SPEC §7.6).
 */
enum PaymentMethod: string
{
    use HasOptions;

    case Cash = 'cash';
    case Check = 'check';
    case BankTransfer = 'bank_transfer';
    case CardTerminal = 'card_terminal';
    case Other = 'other';
    case Online = 'online';

    public function label(): string
    {
        return __("payments.methods.{$this->value}");
    }

    /**
     * Manual methods; cash only when the company takes cash (company setting).
     *
     * @return list<self>
     */
    public static function manual(?Company $company = null): array
    {
        $methods = [self::Cash, self::Check, self::BankTransfer, self::CardTerminal, self::Other];

        return $company !== null && ! $company->accepts_cash
            ? array_values(array_filter($methods, fn (self $m) => $m !== self::Cash))
            : $methods;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function manualOptions(?Company $company = null): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::manual($company));
    }

    public function requiresReference(): bool
    {
        return $this === self::CardTerminal;
    }

    public function requiresNote(): bool
    {
        return $this === self::Other;
    }
}
