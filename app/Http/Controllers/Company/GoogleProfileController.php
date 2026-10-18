<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\GoogleProfile;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Google reviews: the company's Google Business Profiles and their review links (SPEC §8).
 * Each brand picks its default profile here ("Default for <brand>") or on the brand form. Edited as one list by
 * the office.
 */
class GoogleProfileController extends Controller
{
    public function edit(): Response
    {
        Gate::authorize('manageCatalog');

        $brands = Brand::query()->orderBy('name')->get(['id', 'name', 'google_profile_id']);

        return Inertia::render('company/google-profiles', [
            'profiles' => GoogleProfile::query()->orderBy('id')->get(['id', 'label', 'review_url', 'brand_id'])
                ->map(fn (GoogleProfile $profile) => [
                    ...$profile->only(['id', 'label', 'review_url', 'brand_id']),
                    // The brand this location is the default for (its own brand, or the only brand).
                    'is_default' => $brands->contains(fn (Brand $b) => $b->google_profile_id === $profile->id
                        && ($profile->brand_id === $b->id || ($profile->brand_id === null && $brands->count() === 1))),
                ]),
            'brands' => $brands->map(fn (Brand $b) => ['value' => (string) $b->id, 'label' => $b->name])->values(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manageCatalog');

        $validated = $request->validate([
            'profiles' => ['present', 'array', 'max:100'],
            'profiles.*.id' => ['nullable', 'integer'],
            'profiles.*.label' => ['required', 'string', 'max:100'],
            'profiles.*.review_url' => ['required', 'url:https', 'max:500'],
            'profiles.*.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('company_id', currentCompany()->id)],
            'profiles.*.is_default' => ['sometimes', 'boolean'],
        ], [], [
            'profiles.*.label' => __('reviews.fields.label'),
            'profiles.*.review_url' => __('reviews.fields.review_url'),
        ]);

        DB::transaction(function () use ($validated) {
            $keep = [];
            $defaults = [];
            $brands = Brand::query()->get(['id', 'google_profile_id']);
            // A location without a brand can be the default only when the company has one brand.
            $onlyBrand = $brands->count() === 1 ? $brands->first()->id : null;

            foreach ($validated['profiles'] as $row) {
                // Ids of another company are not found (tenant scope) and become new rows.
                $profile = isset($row['id']) ? GoogleProfile::query()->find($row['id']) : null;
                $profile ??= new GoogleProfile;
                $profile->fill([
                    'label' => trim($row['label']),
                    'review_url' => trim($row['review_url']),
                    'brand_id' => $row['brand_id'] ?? null,
                ])->save();
                $keep[] = $profile->id;

                $brandId = $profile->brand_id ?? $onlyBrand;

                if (array_key_exists('is_default', $row) && $brandId !== null) {
                    $defaults[$brandId] ??= null;

                    if ($row['is_default']) {
                        $defaults[$brandId] = $profile->id;
                    }
                }
            }

            GoogleProfile::query()->whereNotIn('id', $keep)->delete();

            // "Default for <brand>": set where ticked; cleared where this list held the default and none is ticked.
            foreach ($brands as $brand) {
                if (! array_key_exists($brand->id, $defaults)) {
                    continue;
                }

                if ($defaults[$brand->id] !== null || in_array($brand->google_profile_id, $keep, true)) {
                    $brand->update(['google_profile_id' => $defaults[$brand->id]]);
                }
            }
        });

        $audit->record('google_profiles.updated', null, ['count' => count($validated['profiles'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('reviews.saved')]);

        return to_route('company.google-profiles.edit');
    }
}
