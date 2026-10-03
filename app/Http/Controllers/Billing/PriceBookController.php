<?php

namespace App\Http\Controllers\Billing;

use App\Enums\LineKind;
use App\Enums\WarrantyUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Models\EstimateItem;
use App\Models\InvoiceItem;
use App\Models\JobCostItem;
use App\Models\Service;
use App\Support\Billing\CostAccess;
use App\Support\Jobs\JobPresenter;
use App\Support\Locale\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Parts and materials typed freely on lines: the company's cost history for a part number or name (so a price rise
 * shows), and "Save to price book".
 */
class PriceBookController extends Controller
{
    /**
     * Earlier costs of a part or material, newest first: date, cost per unit, supplier, part number.
     */
    public function history(Request $request): JsonResponse
    {
        abort_unless(CostAccess::canEnterPrivate($request->user()), 403);

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['history' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';
        $match = fn ($query) => $query->whereNotNull('unit_cost')->whereIn('kind', [LineKind::Part->value, LineKind::Material->value])
            ->where(fn ($q) => $q->whereRaw('lower(part_number) like ?', [$like])->orWhereRaw('lower(description) like ?', [$like]))
            ->latest('id')->limit(15);

        /** @var Collection<int, EstimateItem|InvoiceItem|JobCostItem> $rows */
        $rows = collect()
            ->merge($match(InvoiceItem::query()->where('cost_owner_id', $request->user()->id))->get())
            ->merge($match(EstimateItem::query()->where('cost_owner_id', $request->user()->id))->get())
            ->merge($match(JobCostItem::query()->where('created_by', $request->user()->id))->get());

        return response()->json([
            'history' => $rows
                ->sortByDesc(fn ($row) => $row->created_at)
                ->take(15)
                ->map(fn ($row) => [
                    'description' => $row->description,
                    'part_number' => $row->part_number,
                    'supplier' => $row->supplier,
                    'unit' => $row->unit,
                    'unit_cost' => $row->unit_cost,
                    'date' => JobPresenter::iso($row->created_at),
                ])
                ->values(),
        ]);
    }

    /**
     * "Save to price book" from a line typed on a job, estimate or invoice.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(CostAccess::canEnterPrivate($request->user()), 403);

        $currency = currentCompany()->currency;
        $data = $request->validate([
            'kind' => ['required', Rule::enum(LineKind::class)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'part_number' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_cost' => ['nullable', 'numeric', DocumentRequest::moneyRule($currency)],
            'unit_price' => ['nullable', 'numeric', DocumentRequest::moneyRule($currency)],
            'taxable' => ['boolean'],
            'warranty_value' => ['nullable', 'integer', 'min:0', 'max:999'],
            'warranty_unit' => ['nullable', Rule::enum(WarrantyUnit::class)],
        ]);

        $service = Service::query()->create([
            ...$data,
            'category' => filled(trim($data['category'] ?? '')) ? trim($data['category']) : null,
            'unit_cost' => filled($data['unit_cost'] ?? null) ? Currencies::toMinor($data['unit_cost'], $currency) : null,
            'unit_price' => filled($data['unit_price'] ?? null) ? Currencies::toMinor($data['unit_price'], $currency) : null,
            'taxable' => (bool) ($data['taxable'] ?? true),
            'sort_order' => (int) Service::query()->max('sort_order') + 1,
        ]);

        return response()->json(['id' => $service->id, 'message' => __('billing.line.saved_to_pricebook')], 201);
    }
}
