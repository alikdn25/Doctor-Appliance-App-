<?php

namespace App\Actions\Members;

use App\Enums\UserRole;
use App\Models\Membership;

class EnsureCompanyKeepsOwner
{
    /**
     * True when the membership is the only active Owner of its company.
     */
    public static function isLastOwner(Membership $membership): bool
    {
        return ! Membership::query()
            ->where('company_id', $membership->company_id)
            ->where('role', UserRole::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($membership->id)
            ->exists();
    }
}
