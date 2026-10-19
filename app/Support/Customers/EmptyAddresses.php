<?php

namespace App\Support\Customers;

use Illuminate\Support\Facades\DB;

/**
 * No address without an address (owner's fixes, October 19): old quick bookings left customers with an empty
 * address. Jobs, estimates, invoices and appliances on an empty address move to the customer's address when the
 * customer has exactly one; empty addresses left without jobs go to the trash (soft delete, restorable). Every
 * customer with addresses keeps exactly one primary. Runs over all companies (a data migration).
 */
class EmptyAddresses
{
    public static function clean(): void
    {
        $now = now();
        $empty = fn ($q) => $q->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('line1')->orWhere('line1', ''));

        foreach (DB::table('properties')->where($empty)->get() as $property) {
            $others = DB::table('properties')
                ->where('customer_id', $property->customer_id)
                ->whereNull('deleted_at')
                ->where('line1', '!=', '')
                ->pluck('id');

            if ($others->count() === 1) {
                $target = $others->first();
                foreach (['service_jobs', 'estimates', 'invoices', 'appliances'] as $table) {
                    DB::table($table)->where('property_id', $property->id)->update(['property_id' => $target]);
                }
            }

            if (! DB::table('service_jobs')->where('property_id', $property->id)->exists()) {
                DB::table('appliances')->where('property_id', $property->id)->whereNull('deleted_at')->update(['deleted_at' => $now]);
                DB::table('properties')->where('id', $property->id)->update(['deleted_at' => $now, 'is_primary' => false]);
            }
        }

        // Every customer with addresses keeps exactly one primary.
        $withoutPrimary = DB::table('properties')
            ->whereNull('deleted_at')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('sum(case when is_primary then 1 else 0 end) = 0')
            ->pluck('customer_id');
        foreach ($withoutPrimary as $customerId) {
            $first = DB::table('properties')->where('customer_id', $customerId)->whereNull('deleted_at')
                ->orderByRaw("case when line1 = '' then 1 else 0 end")->orderBy('id')->value('id');
            DB::table('properties')->where('id', $first)->update(['is_primary' => true]);
        }

    }
}
