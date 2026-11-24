<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\ApplianceCategory;
use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use App\Support\Statistics\ApplianceGuess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The job's fields for Statistics (owner's task of November 24), from the job page: appliance type, brand and
 * "Only diagnostic". All optional; the office and the technician on the job may set them.
 */
class JobStatsFieldsController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job): RedirectResponse
    {
        abort_unless(Gate::any(['update', 'work'], $job), 403);

        $data = $request->validate([
            'appliance_category' => ['sometimes', 'nullable', Rule::enum(ApplianceCategory::class)],
            'appliance_brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'only_diagnostic' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('appliance_category', $data)) {
            $job->appliance_category = $data['appliance_category'] !== null ? ApplianceCategory::from($data['appliance_category']) : null;
        }
        if (array_key_exists('appliance_brand', $data)) {
            $job->appliance_brand = ApplianceGuess::normalizeBrand($data['appliance_brand']);
        }
        if (array_key_exists('only_diagnostic', $data)) {
            $job->only_diagnostic = (bool) $data['only_diagnostic'];
        }
        $job->save();

        return back();
    }
}
