<?php

namespace App\Support\Customers;

use App\Models\Customer;
use App\Models\CustomerPhone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds a customer already in the book for a booking read from a text: by phone first, then by name together with
 * the street address. Tenant-scoped like every customer query, so only the current company's customers are found.
 */
class CustomerFinder
{
    /**
     * @return array{customer: Customer, by: 'phone'|'name_address'}|null
     */
    public function find(?string $phone, ?string $firstName, ?string $lastName, ?string $line1): ?array
    {
        if ($phone !== null) {
            $customer = CustomerPhone::query()
                ->where('number_normalized', $phone)
                ->whereHas('customer')
                ->with('customer')
                ->orderBy('id')
                ->first()?->customer;

            if ($customer !== null) {
                return ['customer' => $customer, 'by' => 'phone'];
            }
        }

        $first = mb_strtolower(trim((string) $firstName));
        $last = mb_strtolower(trim((string) $lastName));
        $street = mb_strtolower(trim((string) $line1));

        if ($first === '' || $street === '') {
            return null;
        }

        $customer = Customer::query()
            ->whereRaw('LOWER(first_name) = ?', [$first])
            ->when($last !== '', fn (Builder $q) => $q->whereRaw('LOWER(last_name) = ?', [$last]))
            ->whereHas('properties', fn (Builder $q) => $q->whereRaw('LOWER(line1) = ?', [$street]))
            ->orderBy('id')
            ->first();

        return $customer === null ? null : ['customer' => $customer, 'by' => 'name_address'];
    }
}
