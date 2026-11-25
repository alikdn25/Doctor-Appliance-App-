<?php

namespace App\Support\Billing;

use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Str;

/**
 * The customer's online page of an estimate or invoice: /d/{token} (an invoice's link: /i/{token}, see url()). The token is long and random (the only
 * secret in the link); "e" or "i" in front says which table it belongs to.
 */
class PublicDocument
{
    public static function token(Estimate|Invoice $document): string
    {
        if ($document->public_token === null) {
            $document->public_token = ($document instanceof Invoice ? 'i' : 'e').Str::random(47);
            $document->saveQuietly();
        }

        return $document->public_token;
    }

    /**
     * The link given to the customer. An invoice's goes through /i/{token}, which records the first and the last time
     * the customer followed it and then opens the page (no open pixels; only a click counts).
     */
    public static function url(Estimate|Invoice $document): string
    {
        return $document instanceof Invoice
            ? route('invoices.open', self::token($document))
            : route('documents.public', self::token($document));
    }

    /**
     * Finds the document of a token across companies (the caller then runs in its company).
     */
    public static function find(string $token): Estimate|Invoice|null
    {
        if (strlen($token) !== 48) {
            return null;
        }

        $model = match ($token[0]) {
            'i' => Invoice::class,
            'e' => Estimate::class,
            default => null,
        };

        return $model === null ? null : $model::query()->withoutGlobalScope(CompanyScope::class)->where('public_token', $token)->first();
    }
}
