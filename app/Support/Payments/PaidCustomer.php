<?php

namespace App\Support\Payments;

use App\Actions\Customers\SaveCustomer;
use App\Actions\Customers\SaveProperty;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use App\Models\Invoice;
use App\Models\Property;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * The customer's details typed on the New charge or the Paid screen (owner's task of November 21): phone first, first
 * and last name, email, property address, billing address (or "same as property"). A phone already in the database
 * picks that customer; otherwise a new customer is made when a phone or a name was typed; nothing typed = the
 * company's one "Walk-in" customer. On the Paid screen the details go into the invoice's customer: missing ones are
 * filled in, and a Walk-in invoice (with its job) moves to the real customer. Must run in a tenant context.
 */
class PaidCustomer
{
    public const ADDRESS_FIELDS = ['line1', 'unit', 'city', 'region', 'postal_code'];

    public function __construct(
        private readonly SaveCustomer $saveCustomer,
        private readonly SaveProperty $saveProperty,
    ) {}

    /**
     * The company's "Walk-in" customer, made on first use.
     */
    public function walkIn(): Customer
    {
        return Customer::query()->where('is_walk_in', true)->orderBy('id')->first()
            ?? tap($this->saveCustomer->handle(null, ['type' => 'residential', 'first_name' => __('charges.walk_in'), 'last_name' => null], [], []), function (Customer $customer) {
                $customer->forceFill(['is_walk_in' => true])->save();
            });
    }

    /**
     * Whether anything that names a customer was typed (a phone or a name).
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function named(?array $data): bool
    {
        return $data !== null && (self::phone($data) !== null || trim((string) ($data['first_name'] ?? '')) !== '' || trim((string) ($data['last_name'] ?? '')) !== '');
    }

    /**
     * The customer for a new charge: by phone, new, or Walk-in.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function resolve(?array $data): Customer
    {
        if (! self::named($data)) {
            return $this->walkIn();
        }

        $existing = self::findByPhone(self::phone($data));

        return $existing !== null ? $this->fill($existing, $data) : $this->create($data);
    }

    /**
     * Details typed on the Paid screen go into the invoice's customer; a Walk-in invoice and its job move to the real
     * customer (found by phone or new).
     *
     * @param  array<string, mixed>  $data
     */
    public function applyToInvoice(Invoice $invoice, array $data): Customer
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice->loadMissing(['customer', 'job']);
            $customer = $invoice->customer;

            if (! $customer->is_walk_in) {
                return $this->fill($customer, $data);
            }

            if (! isset($data['customer_id']) && ! self::named($data) && trim((string) ($data['email'] ?? '')) === '') {
                return $customer;
            }

            // A customer picked from the database ("Add customer" on a Walk-in invoice), else found by phone or new.
            $picked = isset($data['customer_id']) ? Customer::query()->where('is_walk_in', false)->find((int) $data['customer_id']) : null;
            $real = $picked ?? self::findByPhone(self::phone($data));
            $real = $real !== null ? $this->fill($real, $data) : $this->create($data);
            $property = $real->primaryProperty()->first() ?? $real->properties()->orderBy('id')->first() ?? $this->saveProperty->empty($real);

            $job = $invoice->job;
            $job->forceFill(['customer_id' => $real->id, 'property_id' => $property->id])->save();
            $invoice->forceFill(['customer_id' => $real->id, 'property_id' => $property->id])->save();

            return $real;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function phone(array $data): ?string
    {
        $raw = trim((string) ($data['phone'] ?? ''));
        $country = currentCompany()->country;

        return $raw !== '' && PhoneNumber::isPossible($raw, $country) ? PhoneNumber::normalize($raw, $country) : null;
    }

    public static function findByPhone(?string $phone): ?Customer
    {
        if ($phone === null) {
            return null;
        }

        return CustomerPhone::query()->where('number_normalized', $phone)
            ->whereHas('customer', fn ($q) => $q->where('is_walk_in', false))
            ->with('customer')->orderBy('id')->first()?->customer;
    }

    /**
     * What the New charge form shows for a phone already in the database.
     *
     * @return array<string, mixed>|null
     */
    public static function lookup(string $raw): ?array
    {
        $customer = self::findByPhone(self::phone(['phone' => $raw]));

        if ($customer === null) {
            return null;
        }

        $customer->loadMissing(['primaryEmail']);
        $property = $customer->primaryProperty()->first();

        return [
            'id' => $customer->id,
            'name' => $customer->display_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->primaryEmail?->email,
            'property' => $property === null ? null : collect(self::ADDRESS_FIELDS)->mapWithKeys(fn ($f) => [$f => $property->{$f}])->all(),
            'billing' => $customer->billingAddressParts(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(array $data): Customer
    {
        $phone = self::phone($data);
        $email = self::email($data);
        $property = self::address($data['property'] ?? null);

        $customer = $this->saveCustomer->handle(
            null,
            [
                'type' => 'residential',
                'first_name' => self::text($data['first_name'] ?? null) ?? ($phone ?? __('charges.customer')),
                'last_name' => self::text($data['last_name'] ?? null),
                ...$this->billing($data, $property),
            ],
            $phone ? [['number' => $phone, 'label' => 'mobile', 'is_primary' => true]] : [],
            $email ? [['email' => $email, 'label' => 'personal', 'is_primary' => true]] : [],
            $property === null ? null : [...$property, 'country' => currentCompany()->country],
        );

        return $customer->refresh();
    }

    /**
     * Fills in what the customer is missing; nothing they already have is overwritten.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Customer $customer, array $data): Customer
    {
        $phone = self::phone($data);
        $email = self::email($data);
        $property = self::address($data['property'] ?? null);

        if ($phone !== null && ! $customer->phones()->where('number_normalized', $phone)->exists()) {
            $row = new CustomerPhone(['number' => $phone, 'label' => 'mobile']);
            $row->customer()->associate($customer);
            $row->is_primary = ! $customer->phones()->exists();
            $row->save();
        }

        if ($email !== null && ! $customer->emails()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $row = new CustomerEmail(['email' => $email, 'label' => 'personal']);
            $row->customer()->associate($customer);
            $row->is_primary = ! $customer->emails()->exists();
            $row->save();
        }

        $first = self::text($data['first_name'] ?? null);
        $last = self::text($data['last_name'] ?? null);
        if ($first !== null && ($customer->first_name === null || $customer->first_name === '' || $customer->first_name === $customer->phones()->value('number'))) {
            $customer->first_name = $first;
        }
        if ($last !== null && blank($customer->last_name)) {
            $customer->last_name = $last;
        }

        if ($property !== null) {
            $primary = $customer->primaryProperty()->first();
            if ($primary === null || blank($primary->line1)) {
                $this->saveProperty->handle($customer, $primary instanceof Property ? $primary : null, [...$property, 'country' => currentCompany()->country]);
            }
        }

        if (! $customer->hasOwnBillingAddress()) {
            $customer->fill($this->billing($data, $property));
        }

        $customer->save();

        return $customer->refresh();
    }

    /**
     * Billing columns: empty when "same as property" (the customer's billing address is then the service address).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>|null  $property
     * @return array<string, string|null>
     */
    private function billing(array $data, ?array $property): array
    {
        if (($data['billing_same'] ?? true) !== false) {
            return [];
        }

        $billing = self::address($data['billing'] ?? null);

        return $billing === null ? [] : [
            ...collect($billing)->mapWithKeys(fn ($v, $f) => ["billing_{$f}" => $v])->all(),
            'billing_country' => currentCompany()->country,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private static function address(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        $parts = collect(self::ADDRESS_FIELDS)->mapWithKeys(fn ($f) => [$f => self::text($address[$f] ?? null)])->all();

        return $parts['line1'] === null ? null : $parts;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function email(array $data): ?string
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
