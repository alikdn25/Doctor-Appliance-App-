<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's SMS subaccount and phone number at the platform's provider. The platform owns the provider
 * account; companies never need their own. The subaccount token is encrypted at rest.
 *
 * @property int $id
 * @property int $company_id
 * @property string $provider
 * @property string $account_sid
 * @property string $auth_token
 * @property string|null $phone_number E.164
 * @property string|null $phone_number_sid
 */
class SmsAccount extends Model
{
    use BelongsToCompany;

    protected $fillable = ['provider', 'account_sid', 'auth_token', 'phone_number', 'phone_number_sid'];

    protected $hidden = ['auth_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['auth_token' => 'encrypted'];
    }
}
