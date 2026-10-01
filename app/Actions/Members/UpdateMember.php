<?php

namespace App\Actions\Members;

use App\Enums\UserRole;
use App\Models\Membership;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateMember
{
    public function __construct(
        private readonly SyncMemberBrands $syncBrands,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $brandIds
     */
    public function handle(Membership $membership, UserRole $role, bool $isActive, array $brandIds): Membership
    {
        return DB::transaction(function () use ($membership, $role, $isActive, $brandIds) {
            $losesOwner = $membership->role === UserRole::Owner
                && $membership->is_active
                && ($role !== UserRole::Owner || ! $isActive);

            if ($losesOwner && EnsureCompanyKeepsOwner::isLastOwner($membership)) {
                throw ValidationException::withMessages(['role' => __('team.errors.last_owner')]);
            }

            $before = ['role' => $membership->role->value, 'is_active' => $membership->is_active];

            $membership->update(['role' => $role, 'is_active' => $isActive]);
            $this->syncBrands->handle($membership, $brandIds);

            $after = ['role' => $role->value, 'is_active' => $isActive];

            if ($before !== $after) {
                $this->audit->record('member.updated', $membership, [
                    'user_id' => $membership->user_id,
                    'before' => $before,
                    'after' => $after,
                ]);
            }

            return $membership;
        });
    }
}
