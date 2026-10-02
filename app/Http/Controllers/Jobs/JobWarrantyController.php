<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\WarrantyUnit;
use App\Http\Controllers\Controller;
use App\Models\InvoiceItem;
use App\Models\ServiceJob;
use App\Support\Billing\Warranties;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Warranties of a job's invoice lines: the summary shown when the job is closed (change per line or "Apply to all
 * lines"), and which warranties of an original job are still running on the date of a callback.
 */
class JobWarrantyController extends Controller
{
    public function update(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('work', $job);

        $data = $request->validate([
            'items' => ['required', 'array', 'max:300'],
            'items.*.id' => ['required', 'integer'],
            'items.*.warranty_value' => ['required', 'integer', 'min:0', 'max:999'],
            'items.*.warranty_unit' => ['required', Rule::enum(WarrantyUnit::class)],
        ]);

        $invoiceIds = $job->invoices()->pluck('id');

        foreach ($data['items'] as $row) {
            // Lines of this job's invoices only (another job's id is ignored).
            InvoiceItem::query()->whereIn('invoice_id', $invoiceIds)->whereKey($row['id'])
                ->update(['warranty_value' => $row['warranty_value'], 'warranty_unit' => $row['warranty_unit']]);
        }

        Warranties::stampJob($job);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.warranty.saved')]);

        return to_route('jobs.show', $job);
    }

    /**
     * Lines of the job with their warranty on a date (default today, company time zone). For a callback form.
     */
    public function onDate(Request $request, ServiceJob $job): JsonResponse
    {
        Gate::authorize('view', $job);

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) === 1
            ? CarbonImmutable::parse((string) $request->query('date'))
            : CarbonImmutable::now(currentCompany()->timezone);

        return response()->json(['date' => $date->toDateString(), 'number' => $job->number, 'lines' => Warranties::onDate($job, $date)]);
    }
}
