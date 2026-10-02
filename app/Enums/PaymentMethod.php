<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

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
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Cash, self::Check, self::BankTransfer, self::CardTerminal, self::Other];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function manualOptions(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::manual());
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
