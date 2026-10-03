<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BusinessExpenseCategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class BusinessExpenseCategoryController extends Controller
{
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('create', BusinessExpenseCategory::class);
        $data = $this->data($request);
        $category = new BusinessExpenseCategory($data);
        $category->created_by = $request->user()->id;
        $category->save();
        $audit->record('business_expense_category.created', $category, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('expenses.category_saved')]);

        return back();
    }

    public function update(Request $request, BusinessExpenseCategory $category, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $category);
        $data = $this->data($request, $category);
        $before = $category->only(['name', 'is_active']);
        $category->update($data);
        $audit->record('business_expense_category.updated', $category, ['before' => $before, 'after' => $data]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('expenses.category_saved')]);

        return back();
    }

    private function data(Request $request, ?BusinessExpenseCategory $category = null): array
    {
        $name = $request->input('name');
        $request->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'is_active' => $request->boolean('is_active', true),
        ]);
        $request->validate([
            'name' => [
                'bail', 'required', 'string', 'max:80',
                function ($attribute, $value, $fail) use ($category) {
                    if (BusinessExpenseCategory::query()->where('normalized_name', mb_strtolower($value))
                        ->when($category, fn ($query) => $query->whereKeyNot($category->id))->exists()) {
                        $fail(__('expenses.category_exists'));
                    }
                },
            ],
            'is_active' => ['boolean'],
        ]);

        return $request->only(['name', 'is_active']);
    }
}
