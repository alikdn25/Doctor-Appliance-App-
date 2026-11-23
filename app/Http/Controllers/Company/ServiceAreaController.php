<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Service area: the south/north/west/east box that Google address suggestions are limited to (a hard
 * limit, not a preference), in every address field. All four empty = anywhere in the company's country.
 */
class ServiceAreaController extends Controller
{
    public function edit(): Response
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        return Inertia::render('company/service-area', [
            'area' => $company->serviceArea(),
            'defaultArea' => Company::defaultServiceArea(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        $data = $request->validate([
            'south' => ['nullable', 'required_with:north,west,east', 'numeric', 'between:-90,90'],
            'north' => ['nullable', 'required_with:south,west,east', 'numeric', 'between:-90,90', 'gt:south'],
            'west' => ['nullable', 'required_with:south,north,east', 'numeric', 'between:-180,180'],
            'east' => ['nullable', 'required_with:south,north,west', 'numeric', 'between:-180,180', 'gt:west'],
        ]);

        return $this->save($company, $data['south'] === null ? null : array_map('floatval', $data), $audit);
    }

    /** "Reset to default": the installation's default box, or no box when none is configured. */
    public function reset(AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        return $this->save($company, Company::defaultServiceArea(), $audit);
    }

    /** @param  array{south: float, north: float, west: float, east: float}|null  $area */
    private function save(Company $company, ?array $area, AuditLogger $audit): RedirectResponse
    {
        $company->fill([
            'service_area_south' => $area['south'] ?? null,
            'service_area_north' => $area['north'] ?? null,
            'service_area_west' => $area['west'] ?? null,
            'service_area_east' => $area['east'] ?? null,
        ]);
        $changes = array_keys($company->getDirty());
        $company->save();

        if ($changes !== []) {
            $audit->record('company.service_area_updated', $company, ['fields' => $changes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.service_area.saved')]);

        return to_route('company.service-area.edit');
    }
}
