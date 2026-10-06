<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\LineKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Models\JobCostItem;
use App\Models\ServiceJob;
use App\Models\SupplierReceipt;
use App\Models\SupplierReceiptLink;
use App\Models\TaxRate;
use App\Services\AuditLogger;
use App\Support\Billing\CostAccess;
use App\Support\Billing\MoneyInput;
use App\Support\Locale\Currencies;
use App\Support\PrivateMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Internal costs of a job: cost lines that are on no invoice, and supplier receipts (photo/PDF) linked to one or more
 * jobs. Only for people who see costs; never shown to customers. Receipts are kept for years: they cannot be
 * deleted, except by the uploader within an hour (a wrong file).
 */
class JobCostController extends Controller
{
    public function storeCost(Request $request, ServiceJob $job): RedirectResponse
    {
        $this->authorizeCosts($request, $job);

        $item = new JobCostItem;
        $item->service_job_id = $job->id;
        $item->currency = currentCompany()->currency;
        $item->created_by = $request->user()->id;
        $this->fill($request, $item)->save();

        return back();
    }

    public function updateCost(Request $request, ServiceJob $job, JobCostItem $cost): RedirectResponse
    {
        $this->authorizeCosts($request, $job);
        abort_unless($cost->service_job_id === $job->id, 404);

        $this->fill($request, $cost)->save();

        return back();
    }

    /** Validates the cost line form ("$15.5" is read as 15.50) and fills the line. */
    private function fill(Request $request, JobCostItem $item): JobCostItem
    {
        $currency = $item->currency ?? currentCompany()->currency;
        $request->replace(MoneyInput::cleanPaths($request->input(), ['unit_cost', 'supplier_taxes.*.amount']));
        $data = $request->validate([
            'kind' => ['required', Rule::in([LineKind::Part->value, LineKind::Material->value])],
            'description' => ['required', 'string', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_cost' => ['required', DocumentRequest::moneyRule($currency)],
            'supplier_taxes' => ['nullable', 'array'],
            'supplier_taxes.*.tax_rate_id' => ['required', 'integer', Rule::exists('tax_rates', 'id')->where('company_id', currentCompany()->id)],
            'supplier_taxes.*.amount' => ['required', DocumentRequest::moneyRule($currency)],
        ], [
            'description.required' => __('costs.errors.description'),
            'unit_cost.required' => __('costs.errors.cost'),
            'quantity.*' => __('costs.errors.quantity'),
        ]);
        $rates = TaxRate::query()->get()->keyBy('id');

        $item->fill([
            ...$data,
            'unit_cost' => Currencies::toMinor($data['unit_cost'], $currency),
            'supplier_taxes' => array_values(array_map(fn (array $tax) => [
                'tax_rate_id' => (int) $tax['tax_rate_id'],
                'name' => $rates[(int) $tax['tax_rate_id']]?->name,
                'amount' => Currencies::toMinor($tax['amount'], $currency),
                'recoverable' => (bool) ($rates[(int) $tax['tax_rate_id']]?->is_recoverable ?? true),
            ], array_filter($data['supplier_taxes'] ?? [], fn (array $t) => (float) $t['amount'] > 0))) ?: null,
        ]);

        return $item;
    }

    public function destroyCost(Request $request, ServiceJob $job, JobCostItem $cost, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeCosts($request, $job);
        abort_unless($cost->service_job_id === $job->id, 404);

        $cost->delete();
        $audit->record('job.cost_deleted', $job, ['description' => $cost->description, 'amount' => $cost->totalCost()]);

        return back();
    }

    public function storeReceipt(Request $request, ServiceJob $job): RedirectResponse
    {
        $this->authorizeCosts($request, $job);

        $currency = currentCompany()->currency;
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:15360'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'receipt_date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'numeric', DocumentRequest::moneyRule($currency)],
            'line_label' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $request->file('file');

        $receipt = SupplierReceipt::query()->create([
            'path' => $file->store('companies/'.currentCompany()->id.'/receipts', PrivateMedia::diskName()),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'supplier' => $data['supplier'] ?? null,
            'receipt_date' => $data['receipt_date'] ?? null,
            'amount' => filled($data['amount'] ?? null) ? Currencies::toMinor($data['amount'], $currency) : null,
            'uploaded_by' => $request->user()->id,
        ]);
        $this->link($receipt, $job, $data['line_label'] ?? null);

        return back();
    }

    /**
     * One receipt for parts of several jobs: link it to another job by its number.
     */
    public function linkReceipt(Request $request, SupplierReceipt $receipt): RedirectResponse
    {
        abort_unless(CostAccess::canSee($request->user()), 403);

        $number = $request->validate(['job_number' => ['required', 'integer']])['job_number'];
        $job = ServiceJob::query()->withTrashed()->where('number', $number)->first();

        if ($job === null || ! Gate::allows('view', $job)) {
            return back()->withErrors(['job_number' => __('costs.receipts.job_not_found')]);
        }

        $this->link($receipt, $job, null);

        return back();
    }

    public function showReceipt(Request $request, SupplierReceipt $receipt): StreamedResponse
    {
        abort_unless(CostAccess::canSee($request->user()), 403);

        return PrivateMedia::response($receipt->path);
    }

    public function destroyReceipt(Request $request, SupplierReceipt $receipt, AuditLogger $audit): RedirectResponse
    {
        abort_unless(
            $receipt->uploaded_by === $request->user()->id && $receipt->created_at?->isAfter(now()->subHour()),
            403,
        );

        SupplierReceiptLink::query()->where('supplier_receipt_id', $receipt->id)->delete();
        PrivateMedia::disk()->delete($receipt->path);
        $receipt->delete();
        $audit->record('receipt.deleted', null, ['name' => $receipt->original_name]);

        return back();
    }

    private function link(SupplierReceipt $receipt, ServiceJob $job, ?string $label): void
    {
        $link = SupplierReceiptLink::query()->firstOrNew(['supplier_receipt_id' => $receipt->id, 'service_job_id' => $job->id]);
        $link->line_label ??= $label;
        $link->save();
    }

    private function authorizeCosts(Request $request, ServiceJob $job): void
    {
        Gate::authorize('work', $job);
        abort_unless(CostAccess::canSee($request->user()), 403);
    }
}
