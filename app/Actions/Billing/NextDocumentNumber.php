<?php

namespace App\Actions\Billing;

use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;

/**
 * Next estimate or invoice number of the current company: prefix + counter (e.g. "INV-1042").
 * Must run inside a transaction; the row lock keeps numbers unique under concurrency.
 * Numbers already taken (after the counter was moved back in settings) are skipped.
 */
class NextDocumentNumber
{
    public function estimate(): string
    {
        return $this->next('estimate', Estimate::class);
    }

    public function invoice(): string
    {
        return $this->next('invoice', Invoice::class);
    }

    /**
     * @param  class-string<Estimate|Invoice>  $model
     */
    private function next(string $type, string $model): string
    {
        $company = Company::query()->lockForUpdate()->findOrFail(currentCompany()->id);
        $prefix = (string) $company->getAttribute("{$type}_prefix");
        $counter = (int) $company->getAttribute("{$type}_next_number");

        while ($model::query()->withTrashed()->where('number', $prefix.$counter)->exists()) {
            $counter++;
        }

        $company->forceFill(["{$type}_next_number" => $counter + 1])->save();

        return $prefix.$counter;
    }
}
