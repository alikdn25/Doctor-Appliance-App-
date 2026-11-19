<?php

namespace App\Http\Controllers\Parts;

use App\Http\Controllers\Controller;
use App\Jobs\GeocodeAddress;
use App\Models\Supplier;
use App\Support\Parts\SupplierTasks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Suppliers (Owner and Admin): the parts suppliers with their addresses. The Parts page groups by them and
 * the automatic "Buy at {supplier}" task gets the address.
 */
class SupplierController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manageCatalog');

        return Inertia::render('company/suppliers', [
            'suppliers' => Supplier::query()->withCount(['parts as open_parts' => fn ($q) => $q->where('status', '!=', 'on_hand')])
                ->orderBy('name')->get()
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'address' => $s->address, 'open_parts' => (int) $s->open_parts])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageCatalog');
        $supplier = Supplier::query()->create($this->validated($request));
        $this->placed($supplier);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('parts.suppliers.saved')]);

        return back();
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('manageCatalog');
        $supplier->fill($this->validated($request))->save();
        $this->placed($supplier);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('parts.suppliers.saved')]);

        return back();
    }

    /** Soft delete; its parts keep their rows (without a supplier group from now on). */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        Gate::authorize('manageCatalog');
        $supplier->parts()->update(['supplier_id' => null]);
        $supplier->delete();
        SupplierTasks::sync();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('parts.suppliers.deleted')]);

        return back();
    }

    /**
     * @return array{name: string, address: string|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        return ['name' => trim($data['name']), 'address' => filled($data['address'] ?? null) ? trim($data['address']) : null];
    }

    private function placed(Supplier $supplier): void
    {
        if ($supplier->address !== null && $supplier->latitude === null) {
            GeocodeAddress::dispatch(Supplier::class, $supplier->id)->afterCommit();
        }
        SupplierTasks::sync();
    }
}
