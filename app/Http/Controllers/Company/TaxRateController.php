<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\TaxRateRequest;
use App\Models\TaxRate;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaxRateController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', TaxRate::class);

        return Inertia::render('taxes/index', [
            'taxRates' => TaxRate::query()->orderBy('sort_order')->orderBy('name')->get(),
            'canManage' => Gate::allows('create', TaxRate::class),
        ]);
    }

    public function store(TaxRateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $taxRate = DB::transaction(function () use ($request) {
            $taxRate = TaxRate::create($request->validated() + ['sort_order' => 0]);
            $this->ensureSingleDefault($taxRate);

            return $taxRate;
        });

        $audit->record('tax_rate.created', $taxRate, $taxRate->only(['name', 'rate']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('taxes.created')]);

        return to_route('taxes.index');
    }

    public function update(TaxRateRequest $request, TaxRate $taxRate, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $taxRate) {
            $taxRate->update($request->validated());
            $this->ensureSingleDefault($taxRate);
        });

        $audit->record('tax_rate.updated', $taxRate, $taxRate->only(['name', 'rate', 'is_active']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('taxes.updated')]);

        return to_route('taxes.index');
    }

    public function destroy(TaxRate $taxRate, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('delete', $taxRate);

        $taxRate->delete();
        $audit->record('tax_rate.deleted', $taxRate, $taxRate->only(['name', 'rate']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('taxes.deleted')]);

        return to_route('taxes.index');
    }

    private function ensureSingleDefault(TaxRate $taxRate): void
    {
        if ($taxRate->is_default) {
            TaxRate::query()->whereKeyNot($taxRate->id)->update(['is_default' => false]);
        }
    }
}
