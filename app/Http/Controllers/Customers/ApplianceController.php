<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\SaveAppliance;
use App\Enums\ApplianceType;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\ApplianceRequest;
use App\Models\Appliance;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Jobs\JobPresenter;
use App\Support\PrivateMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplianceController extends Controller
{
    public function store(ApplianceRequest $request, Property $property, SaveAppliance $save): RedirectResponse
    {
        $appliance = $save->handle($property, null, $request->applianceAttributes(), $request->file('rating_plate'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appliances.created')]);

        return to_route('appliances.show', $appliance);
    }

    public function show(Request $request, Appliance $appliance): Response
    {
        Gate::authorize('view', $appliance);

        $user = $request->user();
        $visibleIds = ServiceJob::query()->visibleTo($user)->whereHas('appliances', fn ($q) => $q->whereKey($appliance->id))->pluck('id');

        // Repair history across all jobs: date, type and what was done. No prices.
        $history = ServiceJob::query()
            ->whereHas('appliances', fn ($q) => $q->whereKey($appliance->id))
            ->with(['visits', 'invoices.items'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ServiceJob $job) => [
                'id' => $job->id,
                'number' => $job->number,
                'date' => JobPresenter::iso($job->completed_at ?? $job->visits->last()?->scheduled_start ?? $job->created_at),
                'job_type_label' => $job->job_type->label(),
                'status' => $job->status->value,
                'status_label' => $job->status->label(),
                'description' => $job->description,
                'work_done' => $job->tech_notes,
                'can_open' => $visibleIds->contains($job->id),
                // Warranties of the billed lines (no prices).
                'warranties' => $job->invoices->where('status', '!=', InvoiceStatus::Void)
                    ->flatMap(fn ($invoice) => $invoice->items)
                    ->filter(fn ($item) => $item->bill_to_customer && $item->warranty_ends_on !== null)
                    ->map(fn ($item) => [
                        'description' => $item->description,
                        'warranty' => $item->warrantyLabel(),
                        'ends_on' => $item->warranty_ends_on->toDateString(),
                    ])->values(),
            ])
            ->values();

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
                'can_view' => Gate::allows('view', $customer),
            ],
            'history' => $history,
            'canUpdate' => Gate::allows('update', $appliance),
            'applianceTypes' => ApplianceType::options(),
            'manufacturers' => CustomerController::manufacturers(),
        ]);
    }

    /**
     * Rating plate photo, for anyone who may see the appliance (office, or a technician through their jobs).
     */
    public function ratingPlate(Appliance $appliance): StreamedResponse
    {
        Gate::authorize('view', $appliance);
        abort_if($appliance->rating_plate_path === null, 404);

        return PrivateMedia::response($appliance->rating_plate_path);
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
