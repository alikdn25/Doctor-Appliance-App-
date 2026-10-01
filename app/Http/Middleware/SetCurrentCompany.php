<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\Impersonation;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the company (tenant) for the authenticated user and stores it in
 * CurrentCompany. Must run before route model binding (see bootstrap/app.php).
 */
class SetCurrentCompany
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly Impersonation $impersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.companies.index');
        }

        $company = $this->impersonation->isActive()
            ? $this->resolveImpersonatedCompany($user)
            : $this->resolveCompany($user);

        if ($company === null) {
            return Inertia::render('errors/no-company', [
                'hasSuspendedCompany' => $user->companies()->wherePivot('is_active', true)->exists(),
            ])->toResponse($request)->setStatusCode(403);
        }

        $this->currentCompany->set($company);

        return $next($request);
    }

    private function resolveCompany(User $user): ?Company
    {
        $accessible = $user->accessibleCompanies();

        $company = $accessible->firstWhere('id', $user->current_company_id) ?? $accessible->first();

        if ($company !== null && $user->current_company_id !== $company->id) {
            $user->forceFill(['current_company_id' => $company->id])->saveQuietly();
        }

        return $company;
    }

    /**
     * While impersonating, the company is fixed to the one chosen in the super-admin
     * panel (it may be suspended — support still needs access).
     */
    private function resolveImpersonatedCompany(User $user): ?Company
    {
        $companyId = $this->impersonation->companyId();

        if ($user->membershipFor($companyId) === null) {
            return null;
        }

        return Company::find($companyId);
    }
}
