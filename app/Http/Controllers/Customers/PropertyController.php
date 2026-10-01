<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\SaveProperty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\PropertyRequest;
use App\Models\Customer;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PropertyController extends Controller
{
    public function store(PropertyRequest $request, Customer $customer, SaveProperty $save): RedirectResponse
    {
        $save->handle($customer, null, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('properties.created')]);

        return to_route('customers.show', $customer);
    }

    public function update(PropertyRequest $request, Property $property, SaveProperty $save): RedirectResponse
    {
        $customer = $property->customer()->firstOrFail();
        $save->handle($customer, $property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('properties.updated')]);

        return to_route('customers.show', $customer);
    }

    public function destroy(Property $property): RedirectResponse
    {
        Gate::authorize('delete', $property);

        $customer = $property->customer()->firstOrFail();
        $property->delete();
        SaveProperty::ensurePrimary($customer);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('properties.deleted')]);

        return to_route('customers.show', $customer);
    }
}
