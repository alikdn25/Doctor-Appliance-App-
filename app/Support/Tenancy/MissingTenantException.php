<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a tenant-owned model is queried or written without a current company.
 * Tenant scoping fails closed: no company context means no data.
 */
class MissingTenantException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("No current company is set while accessing tenant-owned model [{$model}].");
    }

    public static function crossTenantWrite(string $model): self
    {
        return new self("Attempted to write [{$model}] belonging to a different company than the current one.");
    }
}
