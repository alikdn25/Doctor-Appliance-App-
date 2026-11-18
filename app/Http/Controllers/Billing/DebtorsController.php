<?php

namespace App\Http\Controllers\Billing;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Message;
use App\Services\AuditLogger;
use App\Support\Billing\DebtorImport;
use App\Support\Billing\Money;
use App\Support\Billing\PaymentReminders;
use App\Support\Jobs\JobPresenter;
use App\Support\Jobs\MorningSms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Debtors (owner's task of November 19): the CSV import of unpaid work (Owner only; preview → Confirm), the Unpaid
 * screen (open invoices; not reminded / reminded / answered; Completed jobs without an invoice) and soft payment
 * reminders for one or several invoices (preview → Confirm; no amount and no link in the text).
 */
class DebtorsController extends Controller
{
    public const FILTERS = ['all', PaymentReminders::NOT_REMINDED, PaymentReminders::REMINDED, PaymentReminders::ANSWERED, 'no_invoice'];

    public function import(): Response
    {
        Gate::authorize('importDebtors');

        return Inertia::render('invoices/import-debtors', ['columns' => DebtorImport::COLUMNS]);
    }

    /**
     * The file is read and shown; nothing is saved.
     */
    public function preview(Request $request, DebtorImport $import): JsonResponse
    {
        Gate::authorize('importDebtors');
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);
        $company = currentCompany();
        $rows = $import->read((string) file_get_contents($request->file('file')->getRealPath()));

        return response()->json(['rows' => array_map(fn (array $row) => [
            ...$row,
            'amount_text' => $row['amount'] === null ? null : Money::format($row['amount'], $company->currency, $company->locale),
        ], $rows)]);
    }

    public function store(Request $request, DebtorImport $import, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('importDebtors');
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.DebtorImport::MAX_ROWS],
            ...collect(DebtorImport::COLUMNS)->mapWithKeys(fn (string $c) => ["rows.*.{$c}" => ['nullable', 'string', 'max:2000']])->all(),
        ]);

        $invoices = $import->confirm($data['rows'], $request->user());
        $audit->record('invoices.debtors_imported', currentCompany(), ['count' => count($invoices)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('debtors.import.done', count($invoices))]);

        return to_route('invoices.unpaid', ['filter' => PaymentReminders::NOT_REMINDED]);
    }

    public function unpaid(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';
        $company = currentCompany();
        $user = $request->user();

        $invoices = Invoice::query()->with(['customer', 'items'])
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->whereHas('job', fn ($q) => $q->visibleTo($user))
            ->when($filter === PaymentReminders::NOT_REMINDED, fn ($q) => $q->whereNull('reminder_sent_at'))
            ->when($filter === PaymentReminders::REMINDED, fn ($q) => $q->whereNotNull('reminder_sent_at')->whereNull('reminder_answered_at'))
            ->when($filter === PaymentReminders::ANSWERED, fn ($q) => $q->whereNotNull('reminder_answered_at'))
            ->when($filter === 'no_invoice', fn ($q) => $q->whereRaw('false'))
            ->orderBy('issued_on')->limit(300)->get();

        $noInvoice = MorningSms::completedWithoutInvoice()->visibleTo($user)->with('customer')->latest('id')->limit(100)->get();

        return Inertia::render('invoices/unpaid', [
            'filter' => $filter,
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'customer' => $invoice->customer?->display_name,
                'issued_on' => $invoice->issued_on->toDateString(),
                'balance' => Money::format($invoice->balance, $invoice->currency, $company->locale),
                'needs_amount' => $invoice->items->contains('needs_amount', true),
                'sent' => $invoice->sent_at !== null,
                'status' => PaymentReminders::status($invoice),
                'reminded_at' => JobPresenter::iso($invoice->reminder_sent_at),
            ])->values(),
            'noInvoice' => $filter === 'no_invoice' || $filter === 'all' ? $noInvoice->map(fn ($job) => [
                'id' => $job->id, 'number' => $job->number, 'customer' => $job->customer?->display_name,
                'description' => $job->description,
            ])->values() : [],
            'noInvoiceCount' => $noInvoice->count(),
            'canImport' => Gate::allows('importDebtors'),
            'canRemind' => Gate::allows('create', Message::class),
        ]);
    }

    /**
     * The texts that Confirm would send; nothing is sent.
     */
    public function reminderPreview(Request $request): JsonResponse
    {
        Gate::authorize('create', Message::class);
        $invoices = $this->chosen($request);

        return response()->json(['texts' => $invoices->map(fn (Invoice $invoice) => [
            'id' => $invoice->id, 'number' => $invoice->number, 'customer' => $invoice->customer?->display_name,
            'phone' => $invoice->customer?->primaryPhone?->number,
            'text' => PaymentReminders::text($invoice, $request->user()),
        ])->values()]);
    }

    public function remind(Request $request, PaymentReminders $reminders): RedirectResponse
    {
        Gate::authorize('create', Message::class);
        $result = $reminders->send($this->chosen($request), $request->user());

        Inertia::flash('toast', [
            'type' => $result['blocked'] === [] ? 'success' : 'warning',
            'message' => trans_choice('debtors.reminder.sent', $result['sent']).($result['blocked'] === [] ? '' : ' '.implode('; ', $result['blocked'])),
        ]);

        return back();
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function chosen(Request $request)
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['integer', 'distinct', Rule::exists('invoices', 'id')->where('company_id', currentCompany()->id)],
        ]);

        return Invoice::query()->with(['customer.primaryPhone', 'job.appliances', 'brand'])->whereKey($data['invoice_ids'])
            ->whereHas('job', fn ($q) => $q->visibleTo($request->user()))->orderBy('id')->get();
    }
}
