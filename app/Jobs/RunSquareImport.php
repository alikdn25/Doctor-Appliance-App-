<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\SquareImport;
use App\Services\SquareImport\SquareImporter;
use App\Support\Audit\ActionSource;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the reviewed customers and the invoice history from Square. A failure is shown on the import screen.
 */
class RunSquareImport implements ShouldQueue
{
    use Queueable;

    /** Large accounts take a while; one attempt, the Owner can start again. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $importId, public int $companyId) {}

    public function handle(SquareImporter $importer, CurrentCompany $tenancy, ActionSource $source): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($importer, $source) {
            $import = SquareImport::query()->find($this->importId);

            if ($import === null || ! $import->isRunning()) {
                return;
            }

            // The change log shows the person who started the import.
            if ($import->user !== null) {
                auth()->setUser($import->user);
            }

            try {
                $source->as('web', fn () => $importer->apply($import));
            } catch (Throwable $e) {
                Log::warning('Square import failed', ['company' => $this->companyId, 'import' => $this->importId, 'error' => $e->getMessage()]);
                $import->forceFill(['status' => SquareImport::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            }
        });
    }
}
