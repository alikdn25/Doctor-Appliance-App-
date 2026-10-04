<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Brands\SaveBrand;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->accessibleCompanies()->isNotEmpty()) {
            return to_route('dashboard');
        }

        return Inertia::render('workspaces', ['companies' => Company::query()->where('status', CompanyStatus::Active)->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request, Company $company, CurrentCompany $context, SaveBrand $brands, AuditLogger $audit)
    {
        abort_unless($company->status === CompanyStatus::Active, 403);
        DB::transaction(function () use ($request, $company, $context, $brands, $audit) {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $membership = $user->membershipFor($company);
            if ($membership) {
                abort_unless($membership->is_active, 403);
            } else {
                // A workspace is chosen once. Other companies' data is reached only through audited support access.
                abort_if($user->memberships()->exists(), 403);
                $context->runAs($company, fn () => Membership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => UserRole::Owner, 'is_active' => true]));
                $audit->record('workspace.joined', $company, ['user_id' => $user->id], $company->id);
            }
            $user->forceFill(['current_company_id' => $company->id])->save();
            $context->runAs($company, function () use ($company, $brands, $user) {
                if (! Brand::query()->where('is_active', true)->exists()) {
                    $brands->handle(null, ['name' => $company->name, 'email' => $user->email, 'sender_name' => $company->name], []);
                }
            });
        });

        return to_route('dashboard');
    }
}
