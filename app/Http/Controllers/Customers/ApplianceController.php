<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\SaveAppliance;
use App\Enums\ApplianceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\ApplianceRequest;
use App\Models\Appliance;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApplianceController extends Controller
{
    public function store(ApplianceRequest $request, Property $property, SaveAppliance $save): RedirectResponse
    {
        $appliance = $save->handle($property, null, $request->applianceAttributes(), $request->file('rating_plate'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appliances.created')]);

        return to_route('appliances.show', $appliance);
    }

    public function show(Appliance $appliance): Response
    {
        Gate::authorize('view', $appliance);

        $property = $appliance->property()->firstOrFail();
        $customer = $property->customer()->firstOrFail();

        return Inertia::render('appliances/show', [
            'appliance' => [
                ...$appliance->only([
                    'id', 'manufacturer', 'model_number', 'serial_number', 'rating_plate_url',
                    'warranty_notes', 'notes',
                ]),
                'type' => $appliance->type->value,
                'type_label' => $appliance->type->label(),
                'install_date' => $appliance->install_date?->toDateString(),
                'purchase_date' => $appliance->purchase_date?->toDateString(),
                'warranty_expires_on' => $appliance->warranty_expires_on?->toDateString(),
                'under_warranty' => $appliance->isUnderWarranty(),
            ],
            'property' => [
                'id' => $property->id,
                'label' => $property->label,
                'full_address' => $property->fullAddress(),
            ],
            'customer' => [
                'id' => $customer->id,
                'display_name' => $customer->display_name,
            ],
            'canUpdate' => Gate::allows('update', $appliance),
            'applianceTypes' => ApplianceType::options(),
            'manufacturers' => CustomerController::manufacturers(),
        ]);
    }

    public function update(ApplianceRequest $request, Appliance $appliance, SaveAppliance $save): RedirectResponse
    {
        $save->handle(
            $appliance->property()->firstOrFail(),
            $appliance,
            $request->applianceAttributes(),
            $request->file('rating_plate'),
            $request->boolean('remove_rating_plate'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appliances.updated')]);

        return to_route('appliances.show', $appliance);
    }

    public function destroy(Appliance $appliance): RedirectResponse
    {
        Gate::authorize('delete', $appliance);

        $property = $appliance->property()->firstOrFail();
        $appliance->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appliances.deleted')]);

        return to_route('customers.show', $property->customer_id);
    }
}
