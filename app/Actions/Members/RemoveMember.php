<?php

namespace App\Actions\Members;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a person from the company. The user account itself stays
 * (it may belong to other companies).
 */
class RemoveMember
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Membership $membership): void
    {
        DB::transaction(function () use ($membership) {
            if ($membership->role === UserRole::Owner && EnsureCompanyKeepsOwner::isLastOwner($membership)) {
                throw ValidationException::withMessages(['member' => __('team.errors.last_owner')]);
            }

            DB::table('brand_user')
                ->where('company_id', $membership->company_id)
                ->where('user_id', $membership->user_id)
                ->delete();

            $membership->user()->where('current_company_id', $membership->company_id)
                ->update(['current_company_id' => null]);

            $this->audit->record('member.removed', $membership, [
                'user_id' => $membership->user_id,
                'role' => $membership->role->value,
            ]);

            $membership->delete();
        });
    }
}
