<?php

namespace App\Http\Controllers\Company;

use App\Actions\Jobs\AssignJob;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Solo / Team (owner's update, October 24): the first row of Settings, for the Owner only. Solo: no technician
 * choice, no Transfer and no Team section, every job is the Owner's. Team: everything is on.
 */
class WorkModeController extends Controller
{
    public function __invoke(Request $request, AssignJob $assign, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->hasRole(UserRole::Owner), 403);
        $mode = $request->validate(['mode' => ['required', Rule::in(['solo', 'team'])]])['mode'];

        $company = currentCompany();
        $company->works_alone = $mode === 'solo';

        if ($company->isDirty('works_alone')) {
            $company->save();
            $audit->record('company.settings_updated', $company, ['fields' => ['works_alone']]);

            // Switched to Solo: open visits nobody has go to the Owner.
            if ($company->works_alone) {
                $assign->giveUnassignedToSoloWorker($request->user());
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __("company.work_mode.saved_{$mode}")]);

        return back();
    }
}
