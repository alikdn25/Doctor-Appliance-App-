<?php

namespace App\Support\Customers;

use App\Actions\Customers\SaveProperty;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A customer's address can be corrected or deleted at any time (owner's task of November 25). Closed jobs and jobs
 * with a sent invoice keep the address as it was: before a change, they move to a hidden (deleted) copy of the old
 * address. Open jobs follow the change.
 */
class AddressHistory
{
    /** Columns that make up the address a job shows. */
    public const ADDRESS_COLUMNS = ['line1', 'line2', 'unit', 'city', 'region', 'postal_code', 'country', 'latitude', 'longitude', 'google_place_id'];

    /** Statuses of a job that is finished: it keeps its address as it was. */
    public static function closedStatuses(): array
    {
        return [JobStatus::Completed->value, JobStatus::Invoiced->value, JobStatus::AwaitingPayment->value, JobStatus::Paid->value, JobStatus::Cancelled->value];
    }

    /**
     * Jobs at this address that keep it as it was: closed, or with a sent invoice.
     *
     * @return Builder<ServiceJob>
     */
    public static function keptJobs(Property $property): Builder
    {
        return ServiceJob::withTrashed()->where('property_id', $property->id)
            ->where(fn (Builder $q) => $q->whereNotNull('closed_at')
                ->orWhereIn('status', self::closedStatuses())
                ->orWhereIn('id', Invoice::withTrashed()->whereNotNull('sent_at')
                    ->where('status', '!=', InvoiceStatus::Void->value)->select('service_job_id')));
    }

    /**
     * Before the address changes: closed jobs move to a hidden copy of it as it is now. Only when the address itself
     * changes (not a label, gate code or note).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function beforeChange(Property $property, array $attributes): void
    {
        $changes = false;
        foreach (self::ADDRESS_COLUMNS as $column) {
            if (array_key_exists($column, $attributes) && (string) ($attributes[$column] ?? '') !== (string) ($property->getRawOriginal($column) ?? '')) {
                $changes = true;
            }
        }

        if (! $changes || ! self::keptJobs($property)->exists()) {
            return;
        }

        DB::transaction(function () use ($property) {
            $copy = $property->replicate();
            $copy->is_primary = false;
            $copy->save();
            $copy->delete();

            self::keptJobs($property)->update(['property_id' => $copy->id]);
        });
    }

    /**
     * Deleting an address: closed jobs keep it (the row is only hidden); open jobs get the customer's one other
     * address, else the customer's empty address ("No address").
     */
    public static function delete(Property $property): void
    {
        DB::transaction(function () use ($property) {
            $customer = $property->customer()->firstOrFail();
            $open = ServiceJob::withTrashed()->where('property_id', $property->id)
                ->whereNotIn('id', self::keptJobs($property)->select('id'));

            if ($open->exists()) {
                $others = $customer->properties()->whereKeyNot($property->id)
                    ->whereNotNull('line1')->where('line1', '!=', '')->get();
                $target = $others->count() === 1
                    ? $others->first()
                    : $customer->properties()->whereKeyNot($property->id)
                        ->where(fn ($q) => $q->whereNull('line1')->orWhere('line1', ''))->first();

                if ($target === null) {
                    $target = new Property(['line1' => null, 'city' => null, 'country' => $property->country]);
                    $target->customer()->associate($customer);
                    $target->is_primary = false;
                    $target->save();
                }

                $open->update(['property_id' => $target->id]);
            }

            $property->is_primary = false;
            $property->save();
            $property->delete();
            SaveProperty::ensurePrimary($customer);
        });
    }
}
