<?php

namespace App\Actions\Companies;

use App\Actions\Members\AddMember;
use App\Enums\UserRole;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Services\AuditLogger;
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
     * @param  array{name: string, timezone?: string|null, currency?: string, plan?: string|null, subscription_status?: string|null}  $data
     * @param  array{name: string, email: string}  $owner
     */
    public function handle(array $data, array $owner): Company
    {
        return DB::transaction(function () use ($data, $owner) {
            // Without a time zone the company starts on the default one until the Owner's browser reports theirs.
            $pending = blank($data['timezone'] ?? null);

            $company = Company::create([
                ...$data,
                'timezone' => $pending ? config('fieldservice.default_timezone') : $data['timezone'],
                'timezone_pending' => $pending,
                'slug' => $this->uniqueSlug($data['name']),
                'business_hours' => Company::defaultBusinessHours(),
            ]);

            $this->audit->record('company.created', $company, ['name' => $company->name], $company->id);

            $this->currentCompany->runAs($company, function () use ($company, $owner) {
                ChecklistTemplate::createDefaults();
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
