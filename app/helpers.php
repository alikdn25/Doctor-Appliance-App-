<?php

use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\MissingTenantException;

if (! function_exists('currentCompany')) {
    /**
     * The company of the current request. Throws when there is none,
     * so it is only used in tenant routes.
     */
    function currentCompany(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new MissingTenantException('No current company.');
    }
}
