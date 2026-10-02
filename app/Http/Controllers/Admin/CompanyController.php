<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Companies\CreateCompany;
use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyStoreRequest;
use App\Http\Requests\Admin\CompanyUpdateRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ImpersonationLog;
use App\Models\Membership;
use App\Services\AuditLogger;
use App\Support\TimezoneDatabase;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin panel: platform-level view of all companies. Tenant scope is
 * lifted explicitly via Company relations.
 */
class CompanyController extends Controller
{
    public function index(Request $request, TimezoneDatabase $tzdata): Response
    {
        $search = trim((string) $request->query('search', ''));

        $companies = Company::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->withCount([
                'brands',
                'memberships as members_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'plan' => $c->plan,
                'subscription_status' => $c->subscription_status?->label(),
                'brands_count' => $c->brands_count,
                'members_count' => $c->members_count,
                'created_at' => $c->created_at?->toDateString(),
            ]);

        return Inertia::render('admin/companies/index', [
            'companies' => $companies,
            'filters' => ['search' => $search],
            'tzdata' => [
                'version' => $tzdata->version(),
                'released' => $tzdata->estimatedReleaseDate()?->format('M Y'),
                'outdated' => $tzdata->isOutdated(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/companies/create', [
            'timezones' => DateTimeZone::listIdentifiers(),
            'currencies' => config('fieldservice.currencies'),
            'subscriptionStatuses' => $this->subscriptionOptions(),
            'defaults' => [
                'timezone' => '',
                'currency' => 'CAD',
            ],
        ]);
    }

    public function store(CompanyStoreRequest $request, CreateCompany $createCompany): RedirectResponse
    {
        $data = $request->validated();

        $company = $createCompany->handle(
            collect($data)->only(['name', 'timezone', 'currency', 'plan', 'subscription_status'])->all(),
            ['name' => $data['owner_name'], 'email' => $data['owner_email']],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.company_created')]);

        return to_route('admin.companies.show', $company);
    }

    public function show(Company $company): Response
    {
        $company->loadCount(['brands']);

        return Inertia::render('admin/companies/show', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'status' => $company->status->value,
                'plan' => $company->plan,
                'subscription_status' => $company->subscription_status?->value,
                'timezone' => $company->timezone,
                'currency' => $company->currency,
                'brands_count' => $company->brands_count,
                'created_at' => $company->created_at?->toDateTimeString(),
            ],
            'members' => $company->memberships()
                ->with('user')
                ->get()
                ->map(fn (Membership $m) => [
                    'user_id' => $m->user_id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'role' => $m->role->label(),
                    'is_active' => $m->is_active,
                    'last_login_at' => $m->user->last_login_at?->toDateTimeString(),
                ]),
            'impersonations' => $company->impersonationLogs()
                ->with(['superAdmin', 'impersonatedUser'])
                ->latest('started_at')
                ->limit(20)
                ->get()
                ->map(fn (ImpersonationLog $log) => [
                    'id' => $log->id,
                    'super_admin' => $log->superAdmin->name,
                    'user' => $log->impersonatedUser->name,
                    'reason' => $log->reason,
                    'started_at' => $log->started_at->toDateTimeString(),
                    'ended_at' => $log->ended_at?->toDateTimeString(),
                ]),
            'auditLogs' => $company->auditLogs()
                ->with(['user', 'impersonator'])
                ->latest('created_at')
                ->limit(30)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'user' => $log->user?->name,
                    'impersonator' => $log->impersonator?->name,
                    'created_at' => $log->created_at->toDateTimeString(),
                ]),
            'statuses' => array_map(
                fn (CompanyStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                CompanyStatus::cases(),
            ),
            'subscriptionStatuses' => $this->subscriptionOptions(),
        ]);
    }

    public function update(CompanyUpdateRequest $request, Company $company, AuditLogger $audit): RedirectResponse
    {
        $company->fill($request->validated());
        $changes = $company->getDirty();
        $original = collect($company->getOriginal())->only(array_keys($changes))->map(
            fn ($v) => $v instanceof \BackedEnum ? $v->value : $v,
        )->all();
        $company->save();

        if ($changes !== []) {
            $action = array_key_exists('status', $changes) ? 'company.status_changed' : 'company.updated';
            $audit->record($action, $company, ['before' => $original, 'after' => $changes], $company->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.company_updated')]);

        return to_route('admin.companies.show', $company);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function subscriptionOptions(): array
    {
        return array_map(
            fn (SubscriptionStatus $s) => ['value' => $s->value, 'label' => $s->label()],
            SubscriptionStatus::cases(),
        );
    }
}
