<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Support\Locale\Spelling;
use Closure;

/**
 * Holds the company (tenant) the current request or job runs for.
 * Registered as a scoped singleton, so it resets between requests and queued jobs.
 * The translation locale follows the company's regional format (Labor / Labour spelling, see Spelling).
 */
class CurrentCompany
{
    private ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
        self::applyLocale($company);
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
        self::applyLocale(null);
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
        self::applyLocale($company);

        try {
            return $callback();
        } finally {
            $this->company = $previous;
            self::applyLocale($previous);
        }
    }

    private static function applyLocale(?Company $company): void
    {
        // Plain unit tests run without a booted application (no config, no translator).
        if (! app()->bound('translator')) {
            return;
        }

        $locale = Spelling::appLocale($company?->locale);

        if (app()->getLocale() !== $locale) {
            app()->setLocale($locale);
        }
    }
}
