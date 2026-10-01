<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a customer with phones, emails and (on create) a first property.
 * Must run in a tenant context.
 */
class SaveCustomer
{
    public function __construct(private readonly SaveProperty $saveProperty) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $phones
     * @param  list<array<string, mixed>>  $emails
     * @param  array<string, mixed>|null  $property
     */
    public function handle(?Customer $customer, array $attributes, array $phones, array $emails, ?array $property = null): Customer
    {
        return DB::transaction(function () use ($customer, $attributes, $phones, $emails, $property) {
            $customer ??= new Customer;
            $customer->fill($attributes)->save();

            $this->syncRows($customer->phones(), $phones, CustomerPhone::class);
            $this->syncRows($customer->emails(), $emails, CustomerEmail::class);

            if ($property !== null) {
                $this->saveProperty->handle($customer, null, $property);
            }

            return $customer;
        });
    }

    /**
     * Upserts the given rows, deletes the rest and keeps exactly one primary row.
     *
     * @template TModel of Model
     *
     * @param  HasMany<TModel, Customer>  $relation
     * @param  list<array<string, mixed>>  $rows
     * @param  class-string<TModel>  $class
     */
    private function syncRows(HasMany $relation, array $rows, string $class): void
    {
        $keepIds = [];
        $hasPrimary = collect($rows)->contains(fn ($row) => ! empty($row['is_primary']));
        $primaryTaken = false;

        foreach (array_values($rows) as $index => $data) {
            $row = isset($data['id']) ? (clone $relation)->whereKey($data['id'])->first() : null;
            $row ??= new $class;

            $isPrimary = $hasPrimary ? ! empty($data['is_primary']) && ! $primaryTaken : $index === 0;
            $primaryTaken = $primaryTaken || $isPrimary;

            $row->fill(collect($data)->except('id')->all());
            $row->setAttribute('is_primary', $isPrimary);
            $row->setAttribute('customer_id', $relation->getParentKey());
            $row->save();

            $keepIds[] = $row->getKey();
        }

        (clone $relation)->whereKeyNot($keepIds)->delete();
    }
}
