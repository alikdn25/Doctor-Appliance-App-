<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\SaveJob;
use App\Enums\ApplianceType;
use App\Http\Controllers\Controller;
use App\Models\Appliance;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Field work on a job by anyone assigned to it (and the office): work notes and appliance details.
 */
class JobWorkController extends Controller
{
    public function notes(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('work', $job);

        $job->update($request->validate([
            'tech_notes' => ['nullable', 'string', 'max:5000'],
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.notes_saved')]);

        return back();
    }

    /**
     * Adds a new appliance at the job's property, or links one that is already there.
     */
    public function storeAppliance(Request $request, ServiceJob $job, SaveJob $save): RedirectResponse
    {
        Gate::authorize('work', $job);

        if ($request->filled('appliance_id')) {
            $appliance = Appliance::query()
                ->where('property_id', $job->property_id)
                ->findOrFail($request->integer('appliance_id'));

            $job->appliances()->syncWithoutDetaching([$appliance->id]);
        } else {
            $save->addAppliance($job, $request->validate([
                'type' => ['required', Rule::enum(ApplianceType::class)],
                ...self::detailRules(),
            ]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appliances.created')]);

        return back();
    }

    /**
     * Manufacturer, model and serial number of an appliance on the job (e.g. read off the rating plate).
     */
    public function updateAppliance(Request $request, ServiceJob $job, Appliance $appliance): RedirectResponse
    {
        Gate::authorize('work', $job);

        abort_unless($job->appliances()->whereKey($appliance->id)->exists(), 404);

        $appliance->update($request->validate(self::detailRules()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appliances.updated')]);

        return back();
    }

    /**
     * @return array<string, list<string>>
     */
    private static function detailRules(): array
    {
        return [
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model_number' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
