<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Models\ChecklistTemplate;
use App\Models\Service;
use App\Services\AuditLogger;
use App\Support\Locale\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's services (start of the price book, SPEC §7.11). The starting list comes from the
 * vertical; the office sets names and prices. Edited as one list, like checklists.
 */
class ServiceController extends Controller
{
    public function edit(): Response
    {
        Gate::authorize('manage', ChecklistTemplate::class);

        return Inertia::render('company/services', [
            'services' => Service::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'unit_price' => $service->unit_price,
                    'taxable' => $service->taxable,
                    'is_active' => $service->is_active,
                ])
                ->values(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', ChecklistTemplate::class);

        $currency = currentCompany()->currency;
        $validated = $request->validate([
            'services' => ['present', 'array', 'max:300'],
            'services.*.id' => ['nullable', 'integer'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.description' => ['nullable', 'string', 'max:500'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0', DocumentRequest::moneyRule($currency)],
            'services.*.taxable' => ['boolean'],
            'services.*.is_active' => ['boolean'],
        ], [], [
            'services.*.name' => __('services.fields.name'),
            'services.*.unit_price' => __('services.fields.unit_price'),
        ]);

        DB::transaction(function () use ($validated, $currency) {
            $keep = [];

            foreach (array_values($validated['services']) as $position => $row) {
                // Ids of another company are not found (tenant scope) and become new rows.
                $service = isset($row['id']) ? Service::query()->find($row['id']) : null;
                $service ??= new Service;
                $service->fill([
                    'name' => trim($row['name']),
                    'description' => $row['description'] ?? null,
                    'unit_price' => filled($row['unit_price'] ?? null) ? Currencies::toMinor($row['unit_price'], $currency) : null,
                    'taxable' => (bool) ($row['taxable'] ?? true),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'sort_order' => $position + 1,
                ])->save();
                $keep[] = $service->id;
            }

            Service::query()->whereNotIn('id', $keep)->delete();
        });

        $audit->record('services.updated', null, ['count' => count($validated['services'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('services.saved')]);

        return to_route('company.services.edit');
    }
}
