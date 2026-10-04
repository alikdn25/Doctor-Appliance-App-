<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\SaveBusinessExpense;
use App\Enums\OfficePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\BusinessExpenseRequest;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Membership;
use App\Services\AuditLogger;
use App\Support\Billing\BillingPresenter;
use App\Support\Locale\Currencies;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', BusinessExpense::class);
        $filters = $this->filters($request);
        $period = $this->query($request, $filters, withCategory: false);
        $totals = (clone $period)->select('category_id', 'currency')
            ->selectRaw('sum(amount) as price, sum(tax_amount) as tax, sum(amount + tax_amount) as total')
            ->groupBy('category_id', 'currency')->get()->groupBy('category_id');
        $categories = BusinessExpenseCategory::query()->orderBy('name')->get()->map(fn (BusinessExpenseCategory $category) => [
            ...$this->category($category),
            'totals' => ($totals[$category->id] ?? collect())->map(fn ($total) => [
                'currency' => $total->currency, 'price' => (int) $total->price,
                'tax' => (int) $total->tax, 'total' => (int) $total->total,
            ])->sortBy('currency')->values(),
        ]);
        $companyView = $request->user()->canOffice(OfficePermission::Expenses);
        $employees = $companyView ? Membership::query()->with('user')->get()
            ->filter(fn (Membership $member) => $member->user !== null)
            ->map(fn (Membership $member) => ['id' => $member->user_id, 'name' => $member->user->name])
            ->concat(BusinessExpense::query()->select('created_by')->distinct()->with('creator')->get()
                ->filter(fn ($expense) => $expense->created_by !== null)
                ->map(fn ($expense) => ['id' => $expense->created_by, 'name' => $expense->creator?->name ?? __('expenses.former_employee')]))
            ->unique('id')
            ->sortBy('name')->values() : collect();
        $employeeTotals = $companyView ? $this->query($request, $filters)->with('creator')
            ->select('created_by', 'currency')
            ->selectRaw('sum(amount) as price, sum(tax_amount) as tax, sum(amount + tax_amount) as total')
            ->groupBy('created_by', 'currency')->get()->map(fn ($total) => [
                'id' => $total->created_by, 'name' => $total->creator?->name ?? __('expenses.former_employee'),
                'currency' => $total->currency, 'price' => (int) $total->price, 'tax' => (int) $total->tax, 'total' => (int) $total->total,
            ])->sortBy(fn ($total) => $total['name'].' '.$total['currency'])->values() : collect();
        $expenses = $this->query($request, $filters)->with(['category', 'creator'])
            ->orderByDesc('spent_on')->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn (BusinessExpense $expense) => $this->row($expense));

        return Inertia::render('expenses/index', [
            'expenses' => $expenses, 'categories' => $categories, 'filters' => $filters,
            'currency' => currentCompany()->currency,
            'employees' => $employees, 'employeeTotals' => $employeeTotals, 'companyView' => $companyView,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', BusinessExpense::class);

        return $this->form($request);
    }

    public function edit(Request $request, BusinessExpense $expense): Response
    {
        Gate::authorize('update', $expense);

        return $this->form($request, $expense);
    }

    public function store(BusinessExpenseRequest $request, SaveBusinessExpense $save, AuditLogger $audit): RedirectResponse
    {
        $expense = $save->handle($request, null, $audit);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('expenses.saved')]);

        return $this->savedRedirect($expense);
    }

    public function update(BusinessExpenseRequest $request, BusinessExpense $expense, SaveBusinessExpense $save, AuditLogger $audit): RedirectResponse
    {
        $save->handle($request, $expense, $audit);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('expenses.saved')]);

        return $this->savedRedirect($expense);
    }

    public function destroy(BusinessExpense $expense, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('delete', $expense);
        $expense->delete();
        $audit->record('business_expense.deleted', $expense, $expense->only(['description', 'amount', 'tax_amount', 'currency', 'receipt_path']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('expenses.removed')]);

        return back();
    }

    public function receipt(BusinessExpense $expense): StreamedResponse
    {
        Gate::authorize('view', $expense);
        abort_if($expense->receipt_path === null, 404);

        return PrivateMedia::response($expense->receipt_path);
    }

    private function savedRedirect(BusinessExpense $expense): RedirectResponse
    {
        // Keep a backdated expense visible immediately after it is saved.
        return to_route('expenses.index', [
            'from' => $expense->spent_on->copy()->startOfMonth()->toDateString(),
            'to' => $expense->spent_on->copy()->endOfMonth()->toDateString(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', BusinessExpense::class);
        $filters = $this->filters($request);
        $query = $this->query($request, $filters)->with(['category', 'creator'])->orderBy('spent_on')->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Category', 'Description', 'Merchant', 'Price', 'Tax', 'Total', 'Currency', 'Entered by', 'Notes', 'Receipt', 'Tax breakdown']);
            foreach ($query->lazy(500) as $expense) {
                $currency = $expense->currency;
                $minor = fn (int $amount) => number_format($amount / Currencies::factor($currency), Currencies::decimals($currency), '.', '');
                // User text is exported literally, without executing spreadsheet formulas.
                $text = fn (?string $value) => preg_match('/^[\s]*[=+@\-]/u', $value ?? '') ? "'".$value : ($value ?? '');
                fputcsv($out, [
                    $expense->spent_on->toDateString(), $text($expense->category->name), $text($expense->description),
                    $text($expense->merchant), $minor($expense->amount), $minor($expense->tax_amount), $minor($expense->total()),
                    $currency, $text($expense->creator?->name), $text($expense->notes),
                    $expense->receipt_path ? route('expenses.receipt', $expense) : '',
                    $text(implode('; ', array_map(fn ($tax) => $tax['name'].' ('.$tax['rate'].'%): '.$minor($tax['amount']), $expense->taxes ?? []))),
                ]);
            }
            fclose($out);
        }, 'business-expenses-'.$filters['from'].'-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    private function form(Request $request, ?BusinessExpense $expense = null): Response
    {
        $categories = BusinessExpenseCategory::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $expense?->category_id ?? 0))
            ->orderBy('name')->get()->map(fn (BusinessExpenseCategory $category) => $this->category($category));
        $data = $expense ? $this->row($expense->load(['category', 'creator'])) + ['notes' => $expense->notes] : null;

        return Inertia::render('expenses/form', [
            'expense' => $data, 'categories' => $categories,
            'taxRates' => BillingPresenter::taxOptions($expense?->taxes ?? []),
            'currency' => $expense?->currency ?? currentCompany()->currency,
            'today' => CarbonImmutable::now(currentCompany()->timezone)->toDateString(),
        ]);
    }

    private function row(BusinessExpense $expense): array
    {
        return [
            ...$expense->only(['id', 'category_id', 'description', 'merchant', 'amount', 'tax_amount', 'taxes', 'currency']),
            'spent_on' => $expense->spent_on->toDateString(), 'category' => $expense->category->name,
            'creator' => $expense->creator?->name, 'total' => $expense->total(),
            'receipt_url' => $expense->receipt_path ? route('expenses.receipt', $expense) : null,
            'receipt_name' => $expense->receipt_name,
            'can_update' => Gate::allows('update', $expense), 'can_delete' => Gate::allows('delete', $expense),
        ];
    }

    private function category(BusinessExpenseCategory $category): array
    {
        return [...$category->only(['id', 'name', 'is_active']), 'can_update' => Gate::allows('update', $category)];
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', 'integer', Rule::exists('business_expense_categories', 'id')->where('company_id', currentCompany()->id)],
            'search' => ['nullable', 'string', 'max:255'],
            'employee' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query
                ->whereIn('id', Membership::query()->select('user_id'))
                ->orWhereIn('id', BusinessExpense::query()->select('created_by')))],
        ]);
        $now = CarbonImmutable::now(currentCompany()->timezone);
        $from = $data['from'] ?? $now->startOfMonth()->toDateString();
        $to = $data['to'] ?? $now->toDateString();
        validator(['from' => $from, 'to' => $to], ['to' => ['after_or_equal:from']])->validate();

        return ['from' => $from, 'to' => $to, 'category' => (string) ($data['category'] ?? ''), 'search' => trim($data['search'] ?? ''),
            'employee' => $request->user()->canOffice(OfficePermission::Expenses) ? (string) ($data['employee'] ?? '') : '',
        ];
    }

    /** @return Builder<BusinessExpense> */
    private function query(Request $request, array $filters, bool $withCategory = true): Builder
    {
        $like = '%'.addcslashes($filters['search'], '%_\\').'%';

        return BusinessExpense::query()->visibleTo($request->user())
            ->when($filters['employee'] !== '', fn ($query) => $query->where('created_by', (int) $filters['employee']))
            ->whereBetween('spent_on', [$filters['from'], $filters['to']])
            ->when($withCategory && $filters['category'] !== '', fn ($query) => $query->where('category_id', (int) $filters['category']))
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('description', 'ilike', $like)->orWhere('merchant', 'ilike', $like)));
    }
}
