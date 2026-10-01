<?php

namespace App\Policies\Concerns;

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

trait ChecksTenant
{
    /**
     * Defense in depth on top of the global scope: the record must belong to the current company.
     */
    protected function inCurrentCompany(Model $model): bool
    {
        $current = app(CurrentCompany::class)->id();

        return $current !== null && (int) $model->getAttribute('company_id') === $current;
    }
}
