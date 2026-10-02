<?php

namespace App\Actions\Customers;

use App\Models\Appliance;
use App\Models\Property;
use App\Support\PrivateMedia;
use Illuminate\Http\UploadedFile;

/**
 * Creates or updates an appliance and its rating plate photo (private media).
 * Must run in a tenant context.
 */
class SaveAppliance
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        Property $property,
        ?Appliance $appliance,
        array $attributes,
        ?UploadedFile $ratingPlate = null,
        bool $removeRatingPlate = false,
    ): Appliance {
        $appliance ??= new Appliance;
        $appliance->fill($attributes);
        $appliance->property()->associate($property);
        $appliance->save();

        if ($removeRatingPlate || $ratingPlate !== null) {
            $this->deleteRatingPlate($appliance);
        }

        if ($ratingPlate !== null) {
            $appliance->rating_plate_path = $ratingPlate->store(
                "companies/{$appliance->company_id}/appliances/{$appliance->id}",
                PrivateMedia::diskName(),
            );
            $appliance->save();
        }

        return $appliance;
    }

    private function deleteRatingPlate(Appliance $appliance): void
    {
        if ($appliance->rating_plate_path === null) {
            return;
        }

        PrivateMedia::disk()->delete($appliance->rating_plate_path);
        $appliance->rating_plate_path = null;
        $appliance->save();
    }
}
