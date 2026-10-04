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
 * Company → Google reviews: the company's locations (Google Business Profiles) and their review links (SPEC §8),
 * optionally tied to a brand. The Owner adds and removes them; the technician picks one when asking for a review.
 */
class GoogleProfileController extends Controller
{
    public function edit(): Response
    {
        Gate::authorize('update', currentCompany());

        return Inertia::render('company/google-profiles', [
            'profiles' => GoogleProfile::query()->orderBy('id')->get(['id', 'label', 'review_url', 'brand_id']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])->map(fn (Brand $b) => ['value' => (string) $b->id, 'label' => $b->name])->values(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', currentCompany());

        $validated = $request->validate([
            'profiles' => ['present', 'array', 'max:100'],
            'profiles.*.id' => ['nullable', 'integer'],
            'profiles.*.label' => ['required', 'string', 'max:100'],
            'profiles.*.review_url' => ['required', 'url:https', 'max:500'],
            'profiles.*.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('company_id', currentCompany()->id)],
        ], [], [
            'profiles.*.label' => __('reviews.fields.label'),
            'profiles.*.review_url' => __('reviews.fields.review_url'),
        ]);

        DB::transaction(function () use ($validated) {
            $keep = [];

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
            }

            GoogleProfile::query()->whereNotIn('id', $keep)->delete();
        });

        $audit->record('google_profiles.updated', null, ['count' => count($validated['profiles'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('reviews.saved')]);

        return to_route('company.google-profiles.edit');
    }
}
