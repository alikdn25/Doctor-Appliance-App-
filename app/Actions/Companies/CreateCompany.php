<?php

namespace App\Actions\Companies;

use App\Actions\Members\AddMember;
use App\Enums\UserRole;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Service;
use App\Services\AuditLogger;
use App\Support\Locale\Countries;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a tenant together with its first Owner (used by the super-admin panel and seeders).
 */
class CreateCompany
{
    public function __construct(
        private readonly AddMember $addMember,
        private readonly CurrentCompany $currentCompany,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Country defaults (currency, regional format, time zone) apply to whatever is not given.
     *
     * @param  array{name: string, country?: string, vertical?: string, timezone?: string|null, currency?: string, locale?: string, plan?: string|null, subscription_status?: string|null}  $data
     * @param  array{name: string, email: string}  $owner
     */
    public function handle(array $data, array $owner): Company
    {
        return DB::transaction(function () use ($data, $owner) {
            // Without a time zone the company starts on the default one until the Owner's browser reports theirs.
            $pending = blank($data['timezone'] ?? null);
            $data['country'] = strtoupper($data['country'] ?? (string) config('fieldservice.default_country'));

            $company = Company::create([
                // Missing values (vertical, currency, regional format) fall back to the defaults.
                ...array_filter($data, fn ($value) => $value !== null),
                'timezone' => $pending ? Countries::timezone($data['country']) : $data['timezone'],
                'timezone_pending' => $pending,
                'slug' => $this->uniqueSlug($data['name']),
                'business_hours' => Company::defaultBusinessHours(),
            ]);

            $this->audit->record('company.created', $company, ['name' => $company->name], $company->id);

            $this->currentCompany->runAs($company, function () use ($company, $owner) {
                ChecklistTemplate::createDefaults();
                Service::createDefaults();
                $this->addMember->handle($company, $owner['name'], $owner['email'], UserRole::Owner);
            });

            return $company;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $i = 2;

        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
