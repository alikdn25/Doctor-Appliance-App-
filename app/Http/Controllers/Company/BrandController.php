<?php

namespace App\Http\Controllers\Company;

use App\Actions\Brands\SaveBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\BrandRequest;
use App\Models\Brand;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Brand::class);

        return Inertia::render('brands/index', [
            'brands' => Brand::query()
                ->with(['addresses' => fn ($q) => $q->where('is_primary', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (Brand $brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'logo_url' => $brand->logo_url,
                    'primary_color' => $brand->primary_color,
                    'phone' => $brand->phone,
                    'email' => $brand->email,
                    'is_active' => $brand->is_active,
                    'city' => $brand->addresses->first()?->city,
                ]),
            'canCreate' => Gate::allows('create', Brand::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Brand::class);

        return Inertia::render('brands/form', ['brand' => null]);
    }

    public function store(BrandRequest $request, SaveBrand $save): RedirectResponse
    {
        $brand = $save->handle(null, $request->brandAttributes(), $request->addresses(), $request->file('logo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('brands.created')]);

        return to_route('brands.edit', $brand);
    }

    public function edit(Brand $brand): Response
    {
        Gate::authorize('view', $brand);

        return Inertia::render('brands/form', [
            'brand' => $brand->load('addresses'),
            'canUpdate' => Gate::allows('update', $brand),
        ]);
    }

    public function update(BrandRequest $request, Brand $brand, SaveBrand $save): RedirectResponse
    {
        $save->handle(
            $brand,
            $request->brandAttributes(),
            $request->addresses(),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('brands.updated')]);

        return to_route('brands.edit', $brand);
    }

    public function destroy(Brand $brand, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('delete', $brand);

        $brand->delete();
        $audit->record('brand.deleted', $brand, ['name' => $brand->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('brands.deleted')]);

        return to_route('brands.index');
    }
}
