<?php

namespace App\Services\Usage;

use App\Models\UsageEvent;
use Carbon\CarbonImmutable;

/**
 * Keeps only what App review may store: the kind of action, the screen (page name), a short technical name (a button
 * or form key), a number (milliseconds, taps) and a few flags. Anything that could be customer data is dropped:
 * free text, names, phones, emails, addresses and amounts never pass (no spaces, no "@", no runs of digits).
 */
class UsageEventSanitizer
{
    private const KEY = '/^[a-z][a-z0-9_.:\/-]{0,79}$/i';

    /** Booleans and small counts that may go with an event. */
    private const META = ['found' => 'bool', 'taps' => 'int', 'fields' => 'int', 'abandoned' => 'bool'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public function clean(array $raw): ?array
    {
        $type = $raw['type'] ?? null;

        if (! is_string($type) || ! in_array($type, UsageEvent::TYPES, true)) {
            return null;
        }

        $at = is_numeric($raw['at'] ?? null) ? CarbonImmutable::createFromTimestampMs((int) $raw['at']) : null;
        if ($at === null || $at->lt(now()->subDay()) || $at->gt(now()->addMinutes(5))) {
            $at = CarbonImmutable::now();
        }

        $meta = [];
        foreach (self::META as $key => $kind) {
            if (array_key_exists($key, (array) ($raw['meta'] ?? []))) {
                $value = $raw['meta'][$key];
                $meta[$key] = $kind === 'bool' ? (bool) $value : max(0, min(100000, (int) $value));
            }
        }

        return [
            'type' => $type,
            'screen' => $this->key($raw['screen'] ?? null),
            'name' => $this->key($raw['name'] ?? null),
            'value' => is_numeric($raw['value'] ?? null) ? max(0, min(86_400_000, (int) $raw['value'])) : null,
            'meta' => $meta === [] ? null : $meta,
            'occurred_at' => $at,
        ];
    }

    /**
     * A technical key, or null when the value could hold customer data.
     */
    public function key(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::KEY, $value) !== 1) {
            return null;
        }

        // Three digits in a row would let numbers through (phones, amounts, house numbers).
        if (preg_match('/\d{3,}/', $value) === 1) {
            return null;
        }

        return mb_strtolower($value);
    }
}
