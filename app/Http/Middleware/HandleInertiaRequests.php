<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Impersonation;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Translations;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props are closures so they resolve at render time, after the
     * tenant middleware has set the current company.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn () => $this->auth($request),
            'impersonation' => fn () => $this->impersonation($request),
            'locale' => app()->getLocale(),
            'translations' => Inertia::once(fn () => Translations::forLocale(app()->getLocale())),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auth(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return ['user' => null, 'company' => null, 'role' => null, 'companies' => [], 'can' => []];
        }

        $company = app(CurrentCompany::class)->get();
        $role = $user->currentRole();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_super_admin' => $user->is_super_admin,
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            ],
            'company' => $company ? [
                ...$company->only(['id', 'name', 'currency', 'timezone']),
                // The Owner's browser fills in the time zone of a new company.
                'timezone_pending' => $company->timezone_pending && $user->can('update', $company),
            ] : null,
            'role' => $role ? ['value' => $role->value, 'label' => $role->label()] : null,
            'companies' => $user->is_super_admin
                ? []
                : $user->accessibleCompanies()->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'can' => $company === null ? [] : [
                'viewCustomers' => $user->can('viewAny', Customer::class),
                'viewJobs' => $user->can('viewAny', ServiceJob::class),
                'viewInvoices' => $user->can('viewAny', Invoice::class),
                'viewMyJobs' => $user->can('viewMine', ServiceJob::class),
                'viewCalendar' => $user->can('dispatch', ServiceJob::class),
                'manageChecklists' => $user->can('manage', ChecklistTemplate::class),
                'manageCompany' => $user->can('update', $company),
                'viewBrands' => $user->can('viewAny', Brand::class),
                'manageTeam' => $user->can('viewAny', Membership::class),
                'viewTaxes' => $user->can('viewAny', TaxRate::class),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function impersonation(Request $request): ?array
    {
        $impersonation = app(Impersonation::class);

        if (! $impersonation->isActive() || $request->user() === null) {
            return null;
        }

        return [
            'userName' => $request->user()->name,
            'companyName' => Company::find($impersonation->companyId())?->name,
        ];
    }
}
