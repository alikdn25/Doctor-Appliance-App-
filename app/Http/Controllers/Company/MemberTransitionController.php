<?php

namespace App\Http\Controllers\Company;

use App\Actions\Members\SyncMemberBrands;
use App\Actions\Members\TransferMemberJobs;
use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MemberTransitionController extends Controller
{
    use PasswordValidationRules;

    public function transfer(Request $request, Membership $membership, TransferMemberJobs $transfer)
    {
        Gate::authorize('update', $membership);
        $data = $request->validate(['replacement_id' => ['required', 'integer', Rule::exists('company_user', 'id')->where('company_id', currentCompany()->id)->where('is_active', true)]]);
        $target = Membership::query()->assignable()->findOrFail($data['replacement_id']);
        DB::transaction(fn () => $transfer->handle($membership, $target));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('team.transferred')]);

        return to_route('team.index');
    }

    public function replace(Request $request, Membership $membership, TransferMemberJobs $transfer, SyncMemberBrands $brands, AuditLogger $audit)
    {
        Gate::authorize('update', $membership);
        abort_unless($membership->role === UserRole::Technician && ! $membership->user->isSuperAdmin(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'password' => $this->passwordRules()]);

        DB::transaction(function () use ($membership, $data, $transfer, $brands, $audit) {
            $old = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($old->is_active, 403);
            // A shared account cannot be renamed or reset by just one of its companies.
            if ($old->memberships()->where('company_id', '!=', $membership->company_id)->exists()) {
                throw ValidationException::withMessages(['name' => __('team.errors.shared_account')]);
            }
            $email = $old->email;
            $verified = $old->email_verified_at;
            $brandIds = $old->brands()->withTrashed()->pluck('brands.id')->all();
            $old->forceFill(['email' => 'retired-'.Str::uuid().'@retired.invalid', 'is_active' => false, 'remember_token' => null])->save();
            $new = User::create(['name' => trim($data['name']), 'email' => $email, 'password' => $data['password']]);
            $new->forceFill(['email_verified_at' => $verified, 'current_company_id' => $membership->company_id])->save();
            $replacement = Membership::create(['company_id' => $membership->company_id, 'user_id' => $new->id, 'role' => UserRole::Technician, 'is_active' => true]);
            $brands->handle($replacement, $brandIds);
            $count = $transfer->handle($membership, $replacement);
            $membership->update(['is_active' => false]);
            DB::table('sessions')->where('user_id', $old->id)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            $audit->record('member.replaced', $membership, ['old_user_id' => $old->id, 'new_user_id' => $new->id, 'old_name' => $old->name, 'login_email' => $email, 'transferred_visits' => $count]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => __('team.replaced')]);

        return to_route('team.index');
    }
}
