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
    /**
     * @param  list<string>|null  $permissions  Office permissions (null = all, and for other roles)
     */
    public function handle(Membership $membership, UserRole $role, bool $isActive, array $brandIds, ?array $permissions = null): Membership
    {
        return DB::transaction(function () use ($membership, $role, $isActive, $brandIds, $permissions) {
            $losesOwner = $membership->role === UserRole::Owner
                && $membership->is_active
                && ($role !== UserRole::Owner || ! $isActive);

            if ($losesOwner && EnsureCompanyKeepsOwner::isLastOwner($membership)) {
                throw ValidationException::withMessages(['role' => __('team.errors.last_owner')]);
            }

            $before = ['role' => $membership->role->value, 'is_active' => $membership->is_active];
            $beforePermissions = $membership->officePermissions();
            $permissions = $role === UserRole::Admin ? $permissions : null;

            $membership->update(['role' => $role, 'is_active' => $isActive, 'permissions' => $permissions]);
            $this->syncBrands->handle($membership, $brandIds);

            $after = ['role' => $role->value, 'is_active' => $isActive];
            // An Office member's access changes are logged too, as the list of areas before and after.
            $afterPermissions = $membership->fresh()->officePermissions();
            if ($before['role'] === UserRole::Admin->value && $role === UserRole::Admin && $beforePermissions !== $afterPermissions) {
                $before['permissions'] = $beforePermissions;
                $after['permissions'] = $afterPermissions;
            }

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
