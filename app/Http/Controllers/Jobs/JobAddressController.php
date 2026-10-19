<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Customers\SaveProperty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\PropertyRequest;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The job's address from the job page: "Add address" when it has none (saved on the job and on the customer), or
 * another address of the same customer.
 */
class JobAddressController extends Controller
{
    private const FIELDS = ['line1', 'unit', 'city', 'region', 'postal_code', 'country', 'google_place_id', 'latitude', 'longitude'];

    public function __invoke(Request $request, ServiceJob $job, SaveProperty $save): RedirectResponse
    {
        Gate::authorize('work', $job);
        $customer = $job->customer()->firstOrFail();

        if ($request->filled('property_id')) {
            Gate::authorize('update', $job);
            // Tenant-scoped, and only an address of this customer.
            $property = Property::query()->where('customer_id', $customer->id)->find($request->integer('property_id'));
            if ($property === null || blank($property->line1)) {
                throw ValidationException::withMessages(['property_id' => __('jobs.errors.invalid_property')]);
            }
            $job->forceFill(['property_id' => $property->id])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.address.changed')]);

            return back();
        }

        $request->merge(['country' => strtoupper((string) $request->input('country', currentCompany()->country))]);
        $rules = collect(PropertyRequest::addressRules())->only(self::FIELDS)->all();
        $data = $request->validate($rules, [], PropertyRequest::addressAttributes());
        $address = collect(self::FIELDS)->mapWithKeys(fn (string $f) => [$f => filled($data[$f] ?? null) ? $data[$f] : null])->all();

        DB::transaction(function () use ($job, $customer, $address, $save) {
            $current = $job->property;
            // The job's empty address is filled (it is the customer's too); otherwise the customer gets a new one.
            $property = $current !== null && blank($current->line1) ? $current : null;
            $property = $save->handle($customer, $property, $address);
            $job->forceFill(['property_id' => $property->id])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.address.saved')]);

        return back();
    }
}
