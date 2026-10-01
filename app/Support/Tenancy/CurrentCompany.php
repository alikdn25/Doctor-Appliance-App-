<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Closure;

/**
 * Holds the company (tenant) the current request or job runs for.
 * Registered as a scoped singleton, so it resets between requests and queued jobs.
 */
class CurrentCompany
{
    private ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function forget(): void
    {
        $this->company = null;
    }

    /**
     * Run a callback in the context of the given company, restoring the previous one afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Company $company, Closure $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
