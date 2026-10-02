<?php

namespace App\Models\Concerns;

use App\Enums\LineKind;
use App\Support\Billing\Warranty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Shared parts of estimate and invoice lines: kind (service / part / material), cost and supplier, internal lines
 * (not billed to the customer) and the warranty.
 *
 * @mixin Model
 *
 * @property LineKind $kind
 * @property int|null $service_id
 * @property string|null $part_number
 * @property string|null $supplier
 * @property string|null $unit
 * @property int|null $unit_cost Per unit, minor units
 * @property list<array{tax_rate_id: int|null, name: string, amount: int, recoverable: bool}>|null $supplier_taxes
 * @property bool $bill_to_customer
 * @property int|null $warranty_value 0 = no warranty
 * @property string|null $warranty_unit
 * @property Carbon|null $warranty_ends_on
 */
trait IsBillingLine
{
    /** Fields of a line besides description/quantity/price/taxable. */
    public const LINE_FIELDS = [
        'tax_rate_ids', 'kind', 'service_id', 'part_number', 'supplier', 'unit', 'unit_cost', 'supplier_taxes', 'bill_to_customer',
        'warranty_value', 'warranty_unit',
    ];

    /** Fields only people who see costs may set. */
    public const COST_FIELDS = ['unit_cost', 'supplier_taxes', 'supplier'];

    public function initializeIsBillingLine(): void
    {
        $this->mergeFillable([...self::LINE_FIELDS, 'warranty_ends_on']);
        $this->mergeCasts([
            'tax_rate_ids' => 'array',
            'kind' => LineKind::class,
            'unit_cost' => 'integer',
            'supplier_taxes' => 'array',
            'bill_to_customer' => 'boolean',
            'warranty_value' => 'integer',
            'warranty_ends_on' => 'date:Y-m-d',
        ]);
        $this->attributes['kind'] ??= LineKind::Service->value;
        $this->attributes['bill_to_customer'] ??= true;
    }

    /**
     * What the line cost the company: quantity × unit cost, plus supplier tax that is not recoverable.
     */
    public function totalCost(): int
    {
        $cost = (int) round((float) $this->quantity * (int) ($this->unit_cost ?? 0));

        foreach ($this->supplier_taxes ?? [] as $tax) {
            if (! ($tax['recoverable'] ?? true)) {
                $cost += (int) ($tax['amount'] ?? 0);
            }
        }

        return $cost;
    }

    public function warrantyLabel(): string
    {
        return Warranty::label($this->warranty_value, $this->warranty_unit);
    }
}

