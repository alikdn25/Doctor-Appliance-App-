<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Billing\Money;
use Illuminate\Support\Str;

/**
 * Change log entries as the Owner reads them: who, when, what, and each changed field with its old and new value.
 */
class HistoryPresenter
{
    /** Columns holding amounts in minor units of the record's currency. */
    private const MONEY = [
        'amount', 'tip_amount', 'processing_fee', 'subtotal', 'tax_total', 'total', 'discount_total', 'deposit_amount',
        'amount_paid', 'balance', 'unit_price', 'unit_cost', 'from', 'to',
    ];

    /** Columns not worth showing to a person (internal links and flags). */
    private const HIDDEN = ['revision_root_id', 'revised_from_id', 'assignment_is_explicit', 'number_sequence'];

    /**
     * @return array<string, mixed>
     */
    public static function entry(AuditLog $log): array
    {
        $changes = $log->changes ?? [];
        $currency = $changes['new']['currency'] ?? $changes['old']['currency'] ?? $changes['currency'] ?? null;

        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'action' => $log->action,
            'event' => Str::afterLast($log->action, '.'),
            'type' => Str::before($log->action, '.'),
            'record' => $changes['label'] ?? $changes['number'] ?? $changes['name'] ?? null,
            'record_id' => $log->auditable_id,
            'who' => self::who($log),
            'source' => $log->source ?? 'web',
            'fields' => self::fields($changes, is_string($currency) ? $currency : null),
        ];
    }

    private static function who(AuditLog $log): ?string
    {
        $name = $log->user?->name;

        if ($log->impersonator !== null) {
            return __('history.support_as', ['admin' => $log->impersonator->name, 'name' => $name ?? '—']);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private static function fields(array $changes, ?string $currency): array
    {
        if (isset($changes['new']) && is_array($changes['new'])) {
            $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
            $keys = array_keys($changes['new']);

            return collect($keys)
                ->reject(fn (string $key) => in_array($key, self::HIDDEN, true) || ($old === [] && $changes['new'][$key] === null))
                ->map(fn (string $key) => [
                    'field' => self::fieldName($key),
                    'old' => array_key_exists($key, $old) ? self::value($key, $old[$key], $currency) : null,
                    'new' => self::value($key, $changes['new'][$key], $currency),
                ])
                ->values()
                ->all();
        }

        // Older entries and named actions keep their own details (e.g. "reason", "amount").
        return collect($changes)
            ->except(['label', 'number', 'name', 'currency'])
            ->reject(fn ($value) => is_array($value))
            ->map(fn ($value, string $key) => ['field' => self::fieldName($key), 'old' => null, 'new' => self::value($key, $value, $currency)])
            ->values()
            ->all();
    }

    private static function fieldName(string $key): string
    {
        $translated = __("history.fields.{$key}");

        return $translated === "history.fields.{$key}" ? Str::ucfirst(str_replace('_', ' ', Str::beforeLast($key, '_id') === $key ? $key : Str::beforeLast($key, '_id'))) : $translated;
    }

    private static function value(string $key, mixed $value, ?string $currency): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? __('history.yes') : __('history.no');
        }

        if ($currency !== null && in_array($key, self::MONEY, true) && is_numeric($value)) {
            return Money::format((int) $value, $currency);
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        return (string) $value;
    }
}
