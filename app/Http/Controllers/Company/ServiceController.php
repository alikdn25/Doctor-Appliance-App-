<?php

namespace App\Http\Controllers\Company;

use App\Enums\LineKind;
use App\Enums\WarrantyUnit;
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
use Illuminate\Validation\Rule;
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
                    'category' => $service->category,
                    'unit_price' => $service->unit_price,
                    'taxable' => $service->taxable,
                    'is_active' => $service->is_active,
                    'kind' => $service->kind->value,
                    'part_number' => $service->part_number,
                    'supplier' => $service->supplier,
                    'unit' => $service->unit,
                    'unit_cost' => $service->unit_cost,
                    'warranty_value' => $service->warranty_value,
                    'warranty_unit' => $service->warranty_unit,
                ])
                ->values(),
            'warrantyUnits' => WarrantyUnit::options(),
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
            'services.*.category' => ['nullable', 'string', 'max:80'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0', DocumentRequest::moneyRule($currency)],
            'services.*.taxable' => ['boolean'],
            'services.*.is_active' => ['boolean'],
            'services.*.kind' => ['nullable', Rule::enum(LineKind::class)],
            'services.*.part_number' => ['nullable', 'string', 'max:100'],
            'services.*.supplier' => ['nullable', 'string', 'max:150'],
            'services.*.unit' => ['nullable', 'string', 'max:20'],
            'services.*.unit_cost' => ['nullable', 'numeric', 'min:0', DocumentRequest::moneyRule($currency)],
            'services.*.warranty_value' => ['nullable', 'integer', 'min:0', 'max:999'],
            'services.*.warranty_unit' => ['nullable', Rule::enum(WarrantyUnit::class)],
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
                    'category' => trim($row['category'] ?? '') ?: null,
                    'unit_price' => filled($row['unit_price'] ?? null) ? Currencies::toMinor($row['unit_price'], $currency) : null,
                    'taxable' => (bool) ($row['taxable'] ?? true),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'sort_order' => $position + 1,
                    'kind' => $row['kind'] ?? 'service',
                    'part_number' => $row['part_number'] ?? null,
                    'supplier' => $row['supplier'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'unit_cost' => filled($row['unit_cost'] ?? null) ? Currencies::toMinor($row['unit_cost'], $currency) : null,
                    // Empty = the company's default warranty.
                    'warranty_value' => $row['warranty_value'] ?? null,
                    'warranty_unit' => isset($row['warranty_value']) ? ($row['warranty_unit'] ?? 'days') : null,
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
