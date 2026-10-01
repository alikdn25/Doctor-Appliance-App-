<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\MissingTenantException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned.
 *
 * - Every query is filtered by the current company (fails closed when there is none).
 * - company_id is filled from the current company on create.
 * - Writing a record of another company while a company is set throws.
 *
 * Platform code (super-admin panel, seeders) bypasses the scope explicitly
 * with withoutCompanyScope() or CurrentCompany::runAs().
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        // "saving" runs before "creating", so company_id is filled and checked here.
        static::saving(function (Model $model) {
            $current = app(CurrentCompany::class)->id();

            if ($model->getAttribute('company_id') === null) {
                if ($current === null) {
                    throw MissingTenantException::forModel($model::class);
                }

                $model->setAttribute('company_id', $current);
            }

            if ($current !== null && (int) $model->getAttribute('company_id') !== $current) {
                throw MissingTenantException::crossTenantWrite($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Query across all companies. Only for platform-level code.
     *
     * @return Builder<static>
     */
    public static function withoutCompanyScope(): Builder
    {
        return static::withoutGlobalScope(CompanyScope::class);
    }
}
