<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Trip;
use App\Support\Maps\DistanceMatrix;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fills in the road distance of a trip from the map service, unless it was typed by hand meanwhile.
 */
class MeasureTrip implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tripId, public int $companyId) {}

    public function handle(DistanceMatrix $distances, CurrentCompany $tenancy): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($distances) {
            $trip = Trip::query()->find($this->tripId);

            if ($trip === null || $trip->distance_edited || ! $trip->from_address || ! $trip->to_address) {
                return;
            }

            $km = $distances->kilometres($trip->from_address, $trip->to_address);

            if ($km !== null) {
                $trip->forceFill(['distance_km' => $km])->save();
            }
        });
    }
}
