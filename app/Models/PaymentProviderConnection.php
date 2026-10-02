<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A company's own account at an online payment provider, connected by OAuth (SPEC §7.6).
 * Tokens are encrypted at rest with the app key and never sent to the browser.
 *
 * @property int $id
 * @property int $company_id
 * @property string $provider
 * @property string $account_id
 * @property string|null $account_name
 * @property string|null $location_id
 * @property string|null $location_name
 * @property string|null $currency
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property int|null $connected_by
 * @property Carbon|null $created_at
 */
class PaymentProviderConnection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'provider', 'account_id', 'account_name', 'location_id', 'location_name', 'currency',
        'access_token', 'refresh_token', 'token_expires_at', 'connected_by',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }
}
