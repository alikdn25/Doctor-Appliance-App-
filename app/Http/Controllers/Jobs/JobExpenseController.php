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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', DocumentRequest::moneyRule($currency)],
        ]);
        $lines = collect($data['lines'])
            ->map(fn (array $line) => ['description' => $line['description'] ?? null, 'amount' => Currencies::toMinor((string) ($line['amount'] ?? '0'), $currency)])
            ->filter(fn (array $line) => $line['amount'] > 0)->values()->all();
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('expenses.job_block.nothing')]);
        }

        DB::transaction(fn () => $expenses->add($job, $request->user(), $lines));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('expenses.job_block.saved')]);

        return back();
    }

    public function update(Request $request, ServiceJob $job, BusinessExpense $expense, JobExpenses $expenses): RedirectResponse
    {
        $this->authorizeLine($request, $job, $expense, 'update');
        $request->replace(MoneyInput::cleanPaths($request->input(), ['amount']));
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', DocumentRequest::moneyRule($expense->currency)],
        ]);

        $expenses->update($expense, $data['description'] ?? null, Currencies::toMinor((string) $data['amount'], $expense->currency));

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
