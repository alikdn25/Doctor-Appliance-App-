<?php

namespace App\Http\Controllers\Company;

use App\Enums\JobType;
use App\Http\Controllers\Controller;
use App\Models\ChecklistTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checklist per job type. Changes apply to new jobs; existing jobs keep their own copy.
 */
class ChecklistController extends Controller
{
    public function edit(): Response
    {
        Gate::authorize('manage', ChecklistTemplate::class);

        $templates = ChecklistTemplate::query()->get()->keyBy(fn (ChecklistTemplate $t) => $t->job_type->value);

        return Inertia::render('company/checklists', [
            'jobTypes' => JobType::options(),
            'templates' => collect(JobType::cases())
                ->mapWithKeys(fn (JobType $type) => [$type->value => $templates->get($type->value)?->items ?? []])
                ->all(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', ChecklistTemplate::class);

        $request->merge([
            'templates' => collect((array) $request->input('templates', []))
                ->map(fn ($items) => collect(is_array($items) ? $items : [])
                    ->map(fn ($item) => trim((string) $item))
                    ->filter()
                    ->values()
                    ->all())
                ->all(),
        ]);

        $types = implode(',', array_column(JobType::cases(), 'value'));
        $validated = $request->validate([
            'templates' => ['required', "array:{$types}"],
            'templates.*' => ['array', 'max:30'],
            'templates.*.*' => ['string', 'max:200'],
        ], [], ['templates.*.*' => __('checklists.item')]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['templates'] as $type => $items) {
                ChecklistTemplate::query()->updateOrCreate(['job_type' => $type], ['items' => array_values($items)]);
            }
        });

        $audit->record('checklists.updated', null, ['job_types' => array_keys($validated['templates'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('checklists.saved')]);

        return to_route('company.checklists.edit');
    }
}
