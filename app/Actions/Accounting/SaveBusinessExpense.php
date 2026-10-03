<?php

namespace App\Actions\Accounting;

use App\Http\Requests\Accounting\BusinessExpenseRequest;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Services\AuditLogger;
use App\Support\PrivateMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaveBusinessExpense
{
    public function handle(BusinessExpenseRequest $request, ?BusinessExpense $expense, AuditLogger $audit): BusinessExpense
    {
        $path = null;

        try {
            return DB::transaction(function () use ($request, $expense, $audit, &$path) {
                $creating = $expense === null;
                $expense ??= new BusinessExpense;
                $before = $expense->exists ? $expense->getAttributes() : null;
                $data = $request->expenseData();

                if (empty($data['category_id'])) {
                    $name = $request->validated('new_category');
                    $category = BusinessExpenseCategory::query()->firstOrCreate(
                        ['normalized_name' => mb_strtolower($name)], ['name' => $name],
                    );
                    if (! $category->is_active) {
                        throw ValidationException::withMessages(['new_category' => __('expenses.category_archived')]);
                    }
                    if ($category->wasRecentlyCreated) {
                        $category->forceFill(['created_by' => $request->user()->id])->save();
                        $audit->record('business_expense_category.created', $category, ['name' => $category->name]);
                    }
                    $data['category_id'] = $category->id;
                }

                $expense->fill($data);
                if ($creating) {
                    $expense->forceFill(['currency' => currentCompany()->currency, 'created_by' => $request->user()->id]);
                }
                if ($file = $request->file('receipt')) {
                    $path = $file->store('companies/'.currentCompany()->id.'/business-expenses', PrivateMedia::diskName());
                    if (! is_string($path)) {
                        throw ValidationException::withMessages(['receipt' => __('expenses.receipt_failed')]);
                    }
                    $expense->forceFill([
                        'receipt_path' => $path,
                        'receipt_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'receipt_mime' => (string) $file->getMimeType(),
                    ]);
                }
                $expense->save();
                // Original receipt files are retained, including after replacement or soft deletion.
                $audit->record($creating ? 'business_expense.created' : 'business_expense.updated', $expense, [
                    'before' => $before, 'after' => $expense->getAttributes(),
                ]);

                return $expense;
            });
        } catch (Throwable $error) {
            if (is_string($path)) {
                PrivateMedia::disk()->delete($path);
            }
            throw $error;
        }
    }
}
