<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Companies\CreateCompany;
use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\Vertical;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyStoreRequest;
use App\Http\Requests\Admin\CompanyUpdateRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ImpersonationLog;
use App\Models\Membership;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use App\Services\AuditLogger;
use App\Support\AccountEmail;
use App\Support\Locale\Countries;
use App\Support\Locale\Currencies;
use App\Support\Locale\Timezones;
use App\Support\Tenancy\CurrentCompany;
use App\Support\TimezoneDatabase;
use Carbon\CarbonImmutable;
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
            'emailAvailable' => AccountEmail::deliveryEnabled(),
            'timezones' => Timezones::options(),
            'countries' => Countries::options(),
            // Currency and regional format each country starts with (both can be changed).
            'countryDefaults' => collect(Countries::codes())
                ->mapWithKeys(fn (string $code) => [$code => ['currency' => Countries::currency($code), 'locale' => Countries::locale($code)]])
                ->all(),
            'currencies' => Currencies::options(),
            'locales' => Countries::localeOptions(),
            'verticals' => Vertical::options(),
            'subscriptionStatuses' => $this->subscriptionOptions(),
            'defaults' => [
                'timezone' => '',
                'country' => $country = (string) config('fieldservice.default_country'),
                'currency' => Countries::currency($country),
                'locale' => Countries::locale($country),
                'vertical' => Vertical::ApplianceRepair->value,
            ],
        ]);
    }

    public function store(CompanyStoreRequest $request, CreateCompany $createCompany): RedirectResponse
    {
        $data = $request->validated();

        $company = $createCompany->handle(
            collect($data)->only(['name', 'country', 'vertical', 'timezone', 'currency', 'locale', 'plan', 'subscription_status'])->all(),
            ['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['owner_password'] ?? null],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.company_created')]);

        return to_route('admin.companies.show', $company);
    }

    public function show(Company $company): Response
    {
        $company->loadCount(['brands']);
        $supportAccess = $company->impersonationLogs()->select('impersonated_user_id')
            ->selectRaw('max(started_at) as last_support_at')->groupBy('impersonated_user_id')
            ->pluck('last_support_at', 'impersonated_user_id');

        return Inertia::render('admin/companies/show', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'status' => $company->status->value,
                'plan' => $company->plan,
                'subscription_status' => $company->subscription_status?->value,
                'timezone' => $company->timezone,
                'timezone_label' => collect(Timezones::options())->firstWhere('value', $company->timezone)['label'] ?? $company->timezone,
                'locale' => $company->locale,
                'currency' => $company->currency,
                'country' => $company->country,
                'vertical' => $company->vertical->label(),
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
                    'last_login_at' => $m->user->last_login_at?->toIso8601String(),
                    'last_support_at' => $supportAccess->has($m->user_id) ? CarbonImmutable::parse($supportAccess->get($m->user_id), 'UTC')->toIso8601String() : null,
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
                    'started_at' => $log->started_at->toIso8601String(),
                    'ended_at' => $log->ended_at?->toIso8601String(),
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
                    'impersonator' => $log->impersonator_id !== $log->user_id ? $log->impersonator?->name : null,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            'statuses' => array_map(
                fn (CompanyStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                CompanyStatus::cases(),
            ),
            'subscriptionStatuses' => $this->subscriptionOptions(),
            'sms' => app(CurrentCompany::class)->runAs($company, function () use ($company) {
                $registration = SmsRegistration::query()->first();

                return [
                    'mode' => $company->sms_mode->label(),
                    'number' => SmsAccount::query()->value('phone_number'),
                    'registration' => $registration?->only(['status', 'business', 'brand_registration_sid', 'messaging_service_sid', 'campaign_sid', 'rejection_reason']),
                ];
            }),
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
