<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Models\BusinessExpense;
use App\Models\ServiceJob;
use App\Services\AuditLogger;
use App\Support\Accounting\JobExpenses;
use App\Support\Billing\MoneyInput;
use App\Support\Locale\Currencies;
use App\Support\Ui\SavedToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "My expenses" block of a job (owner's task of November 24): one total without a name, or several lines (name +
 * amount). Each line is a business expense linked to the job. The Owner and the technicians assigned to the job.
 */
class JobExpenseController extends Controller
{
    public function store(Request $request, ServiceJob $job, JobExpenses $expenses): RedirectResponse
    {
        abort_unless(JobExpenses::canSee($request->user(), $job), 403);
        $currency = currentCompany()->currency;
        $request->replace(MoneyInput::cleanPaths($request->input(), ['lines.*.amount']));
        $data = $request->validate([
            'lines' => ['nullable', 'array', 'max:20'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', DocumentRequest::moneyRule($currency)],
        ]);
        // Nothing is required (owner's task of November 25): a line with a description or an amount is saved.
        $lines = collect($data['lines'] ?? [])
            ->filter(fn (array $line) => filled($line['description'] ?? null) || filled($line['amount'] ?? null))
            ->map(fn (array $line) => ['description' => $line['description'] ?? null, 'amount' => Currencies::toMinor(filled($line['amount'] ?? null) ? (string) $line['amount'] : '0', $currency)])
            ->values()->all();

        if ($lines !== []) {
            DB::transaction(fn () => $expenses->add($job, $request->user(), $lines));
        }
        SavedToast::flash(route('jobs.show', $job), __('expenses.job_block.saved'));

        return back();
    }

    public function update(Request $request, ServiceJob $job, BusinessExpense $expense, JobExpenses $expenses): RedirectResponse
    {
        $this->authorizeLine($request, $job, $expense, 'update');
        $request->replace(MoneyInput::cleanPaths($request->input(), ['amount']));
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0', DocumentRequest::moneyRule($expense->currency)],
        ]);

        $expenses->update($expense, $data['description'] ?? null, Currencies::toMinor(filled($data['amount'] ?? null) ? (string) $data['amount'] : '0', $expense->currency));

        return back();
    }

    public function destroy(Request $request, ServiceJob $job, BusinessExpense $expense, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeLine($request, $job, $expense, 'delete');
        $expense->delete();
        $audit->record('business_expense.deleted', $expense, ['description' => $expense->description]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('expenses.job_block.removed')]);

        return back();
    }

    private function authorizeLine(Request $request, ServiceJob $job, BusinessExpense $expense, string $ability): void
    {
        abort_unless(JobExpenses::canSee($request->user(), $job), 403);
        abort_unless($expense->service_job_id === $job->id, 404);
        Gate::authorize($ability, $expense);
    }
}
