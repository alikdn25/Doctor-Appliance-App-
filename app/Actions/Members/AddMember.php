<?php

namespace App\Actions\Members;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\AddedToCompany;
use App\Notifications\MemberInvited;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds a person to a company. Emails are unique platform-wide: an existing user
 * simply gets a new membership; a new user is created and invited to set a password.
 * Must run in the context of $company (CurrentCompany).
 */
class AddMember
{
    public function __construct(
        private readonly SyncMemberBrands $syncBrands,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $brandIds
     */
    public function handle(Company $company, string $name, string $email, UserRole $role, array $brandIds = []): Membership
    {
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($company, $name, $email, $role, $brandIds) {
            $user = User::withTrashed()->where('email', $email)->first();

            if ($user?->isSuperAdmin()) {
                throw ValidationException::withMessages(['email' => __('team.errors.super_admin')]);
            }

            if ($user !== null && $user->membershipFor($company) !== null) {
                throw ValidationException::withMessages(['email' => __('team.errors.already_member')]);
            }

            $isNew = $user === null;

            if ($isNew) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Str::password(32),
                ]);
            } elseif ($user->trashed()) {
                $user->restore();
            }

            $membership = Membership::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role' => $role,
                'is_active' => true,
            ]);

            $this->syncBrands->handle($membership, $brandIds);

            $this->audit->record('member.added', $membership, [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $role->value,
            ], $company->id);

            DB::afterCommit(function () use ($user, $company, $isNew) {
                $isNew
                    ? $user->notify(new MemberInvited($company->name, Password::broker()->createToken($user)))
                    : $user->notify(new AddedToCompany($company->name));
            });

            return $membership;
        });
    }
}
