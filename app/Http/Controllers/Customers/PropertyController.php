<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\SaveProperty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\PropertyRequest;
use App\Models\Customer;
use App\Models\Property;
use App\Support\Customers\AddressHistory;
use App\Support\Ui\SavedToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PropertyController extends Controller
{
    public function store(PropertyRequest $request, Customer $customer, SaveProperty $save): RedirectResponse
    {
        $save->handle($customer, null, $request->validated());

        SavedToast::flash(route('customers.show', $customer), __('properties.created'));

        return to_route('customers.show', $customer);
    }

    public function update(PropertyRequest $request, Property $property, SaveProperty $save): RedirectResponse
    {
        $customer = $property->customer()->firstOrFail();
        // Closed jobs keep the address as it was; open jobs follow the correction.
        AddressHistory::beforeChange($property, $request->validated());
        $save->handle($customer, $property, $request->validated());

        SavedToast::flash(route('customers.show', $customer), __('properties.updated'));

        // From the job page ("Edit this address") back there; else the customer's page.
        return back(fallback: route('customers.show', $customer));
    }

    public function destroy(Property $property): RedirectResponse
    {
        Gate::authorize('delete', $property);

        $customer = $property->customer()->firstOrFail();

        // Open jobs at it get the customer's other address (or none); closed jobs keep it as it was.
        AddressHistory::delete($property);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('properties.deleted')]);

        return back(fallback: route('customers.show', $customer));
    }
}
