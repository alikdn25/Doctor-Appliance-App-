<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;

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
    case Crypto = 'crypto';
    case Other = 'other';
    case Online = 'online';
    // Card reader paired with the technician's phone (Square Mobile Payments SDK); recorded by the provider.
    case CardReader = 'card_reader';

    public function label(): string
    {
        // Canadian bank transfers are Interac e-Transfers; elsewhere the generic name is used.
        if ($this === self::BankTransfer && app(CurrentCompany::class)->get()?->country === 'CA') {
            return __('payments.methods.e_transfer');
        }

        return __("payments.methods.{$this->value}");
    }

    /**
     * Manual methods (owner's task of November 24): E-transfer, Card, Cash; cash only when the company takes cash
     * (company setting). Check, crypto and other stay on older payments only.
     *
     * @return list<self>
     */
    public static function manual(?Company $company = null): array
    {
        $methods = [self::BankTransfer, self::CardTerminal, self::Cash];

        return $company !== null && $company->accepts_cash === false
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

    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    /**
     * The group Statistics counts the payment in: card (reader, terminal, online, Square), e_transfer, cash or other.
     */
    public function statsGroup(): string
    {
        return match ($this) {
            self::CardReader, self::CardTerminal, self::Online => 'card',
            self::BankTransfer => 'e_transfer',
            self::Cash => 'cash',
            default => 'other',
        };
    }
}
