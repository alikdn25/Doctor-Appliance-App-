<?php

namespace App\Enums;

/**
 * What the Owner lets an Office member (role "admin") do. A new Office member starts with everything on.
 * Purchase costs and profit are never part of it: they stay with the Owner.
 */
enum OfficePermission: string
{
    case Schedule = 'schedule';
    case Customers = 'customers';
    case Estimates = 'estimates';
    case Invoices = 'invoices';
    case Messages = 'messages';
    case Reports = 'reports';
    case Expenses = 'expenses';
    case Catalog = 'catalog';
    case Team = 'team';

    public function label(): string
    {
        return __("team.permissions.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $p) => [
            'value' => $p->value, 'label' => $p->label(), 'hint' => __("team.permissions_hints.{$p->value}"),
        ], self::cases());
    }
}
