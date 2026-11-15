<?php

namespace App\Services\SquareImport;

use App\Enums\CustomerType;
use App\Jobs\RunSquareImport;
use App\Jobs\ScanSquareCustomers;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\SquareImport;
use App\Models\SquareImportCustomer;
use App\Models\SquareInvoice;
use App\Models\User;
use App\Payments\PaymentProviderException;
use App\Payments\Square\SquareClient;
use App\Payments\Square\SquareProvider;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Import from Square (no AI): customers (with duplicate review) and the whole invoice history, in the background.
 * A repeated import brings only new and changed records. Nothing is ever sent to imported customers. Undo sends
 * everything an import added to Deleted items (30 days) and takes back what a merge added.
 */
class SquareImporter
{
    public function __construct(
        private readonly SquareClient $client,
        private readonly SquareProvider $square,
        private readonly AuditLogger $audit,
    ) {}

    public function connection(Company $company): ?PaymentProviderConnection
    {
        return $this->square->connection($company);
    }

    /**
     * Read permissions the connected account has not granted yet (customers, invoices, orders, payments).
     *
     * @return list<string>
     */
    public function missingScopes(PaymentProviderConnection $connection): array
    {
        $granted = $this->client->tokenScopes($this->square->accessToken($connection));

        return array_values(array_diff((array) config('payments.square.import_scopes'), $granted));
    }

    /** Starts reading the customers; the review screen follows. */
    public function start(User $user): SquareImport
    {
        $import = SquareImport::create(['user_id' => $user->id, 'status' => SquareImport::SCANNING, 'step' => 'customers']);
        ScanSquareCustomers::dispatch($import->id, $import->company_id);

        return $import;
    }

    /**
     * Reads every Square customer and finds the ones already in the book (Square id, then phone, then email).
     */
    public function scan(SquareImport $import): void
    {
        $company = currentCompany();
        $token = $this->token($company);
        $cursor = null;
        $seen = 0;

        do {
            $page = $this->client->customers($token, $cursor);
            foreach ($page['items'] as $raw) {
                $this->scanRow($import, $this->normalize($raw, $company));
                $seen++;
            }
            $import->forceFill(['progress_done' => $seen, 'progress_total' => $seen])->save();
            $cursor = $page['cursor'];
        } while ($cursor !== null);

        $rows = $import->rows();
        $import->forceFill([
            'status' => SquareImport::REVIEW,
            'step' => null,
            'counts' => [
                'new' => (clone $rows)->where('kind', 'new')->count(),
                'matched' => (clone $rows)->where('kind', 'match')->count(),
                'linked' => (clone $rows)->where('kind', 'linked')->count(),
                'no_contact' => (clone $rows)->where('has_contact', false)->count(),
                'total' => $seen,
            ],
        ])->save();
    }

    /**
     * The Owner's choices for the matches: merge (default), skip or create new.
     *
     * @param  array<int, string>  $decisions  row id => merge|skip|new
     */
    public function decide(SquareImport $import, array $decisions, ?string $all = null): void
    {
        if ($all !== null) {
            $import->rows()->where('kind', 'match')->update(['decision' => $all]);
        }

        foreach ($decisions as $rowId => $decision) {
            $import->rows()->whereKey((int) $rowId)->where('kind', 'match')->update(['decision' => $decision]);
        }
    }

    /** The Owner pressed Import: customers and invoices are written in the background. */
    public function run(SquareImport $import): void
    {
        $import->forceFill(['status' => SquareImport::IMPORTING, 'step' => 'customers', 'progress_done' => 0, 'progress_total' => $import->rows()->count()])->save();
        RunSquareImport::dispatch($import->id, $import->company_id);
    }

    /**
     * Writes the customers, then the invoice history. Runs in the queue.
     */
    public function apply(SquareImport $import): void
    {
        $company = currentCompany();
        $counts = ['customers_created' => 0, 'customers_merged' => 0, 'customers_updated' => 0, 'customers_skipped' => 0, 'invoices_created' => 0, 'invoices_updated' => 0];
        $merged = $import->merged ?? [];
        $done = 0;

        foreach ($import->rows()->orderBy('id')->lazy(200) as $row) {
            DB::transaction(function () use ($row, $import, &$counts, &$merged) {
                $this->applyRow($row, $import, $counts, $merged);
            });
            $import->forceFill(['progress_done' => ++$done, 'merged' => $merged])->save();
        }

        $import->forceFill(['step' => 'invoices', 'progress_done' => 0, 'progress_total' => 0])->save();
        $this->importInvoices($import, $this->token($company), $counts);

        $import->forceFill([
            'status' => SquareImport::DONE,
            'step' => null,
            'counts' => [...($import->counts ?? []), ...$counts],
            'merged' => $merged,
            'finished_at' => now(),
        ])->save();

        $this->audit->record('square_import.done', $import, [
            'label' => __('square_import.audit_label', ['date' => $import->created_at->toDateString()]),
            'customers' => $counts['customers_created'] + $counts['customers_merged'] + $counts['customers_updated'],
            'invoices' => $counts['invoices_created'] + $counts['invoices_updated'],
        ]);
    }

    /**
     * Undo: everything the import created goes to Deleted items (customers with their addresses and invoices), and
     * what a merge added to an existing customer is taken back.
     */
    public function undo(SquareImport $import, User $user): void
    {
        DB::transaction(function () use ($import) {
            Customer::query()->where('square_import_id', $import->id)->get()->each(function (Customer $customer) {
                $customer->delete();
                // Its imported invoices go with it, at the same moment, so restoring the customer brings them back.
                SquareInvoice::query()->where('customer_id', $customer->id)->update(['deleted_at' => $customer->getRawOriginal('deleted_at')]);
            });

            SquareInvoice::query()->where('square_import_id', $import->id)->update(['deleted_at' => now()]);

            foreach ($import->merged ?? [] as $merge) {
                $customer = Customer::query()->find($merge['customer_id']);
                if ($customer === null) {
                    continue;
                }
                CustomerPhone::query()->whereIn('id', $merge['phone_ids'] ?? [])->delete();
                CustomerEmail::query()->whereIn('id', $merge['email_ids'] ?? [])->delete();
                Property::query()->whereIn('id', $merge['property_ids'] ?? [])->get()->each->delete();
                $customer->forceFill([
                    ...array_fill_keys($merge['filled'] ?? [], null),
                    'square_customer_id' => $merge['previous_square_id'] ?? null,
                ])->save();
            }

            $import->forceFill(['status' => SquareImport::UNDONE, 'undone_at' => now()])->save();
        });

        $this->audit->record('square_import.undone', $import, [
            'label' => __('square_import.audit_label', ['date' => $import->created_at->toDateString()]),
            'customers' => $import->counts['customers_created'] ?? 0,
            'invoices' => $import->counts['invoices_created'] ?? 0,
        ]);
    }

    private function token(Company $company): string
    {
        $connection = $this->square->connection($company) ?? throw new PaymentProviderException(__('payments.square.errors.not_connected'));

        return $this->square->accessToken($connection);
    }

    /**
     * A Square customer in our fields: phone in E.164, email lower case, the address split.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw, Company $company): array
    {
        $text = fn ($value, int $max = 255) => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        $phone = $text($raw['phone_number'] ?? null, 32);
        $email = $text($raw['email_address'] ?? null);
        $address = is_array($raw['address'] ?? null) ? $raw['address'] : [];
        $line1 = $text($address['address_line_1'] ?? null);
        $city = $text($address['locality'] ?? null, 100);

        return [
            'square_id' => (string) $raw['id'],
            'first_name' => $text($raw['given_name'] ?? null, 100),
            'last_name' => $text($raw['family_name'] ?? null, 100),
            'company_name' => $text($raw['company_name'] ?? null),
            'phone' => $phone !== null && PhoneNumber::isPossible($phone, $company->country) ? PhoneNumber::normalize($phone, $company->country) : null,
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null,
            'address' => $line1 !== null && $city !== null ? [
                'line1' => $line1,
                'unit' => $text($address['address_line_2'] ?? null, 50),
                'city' => $city,
                'region' => $text($address['administrative_district_level_1'] ?? null, 50),
                'postal_code' => $text($address['postal_code'] ?? null, 20),
                'country' => strtoupper((string) ($text($address['country'] ?? null, 2) ?? $company->country)),
            ] : null,
            'notes' => $text($raw['note'] ?? null, 10000),
            'updated_at' => $text($raw['updated_at'] ?? null, 40),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function scanRow(SquareImport $import, array $data): void
    {
        $linked = Customer::query()->where('square_customer_id', $data['square_id'])->first();
        $byPhone = $linked === null && $data['phone'] !== null
            ? CustomerPhone::query()->where('number_normalized', $data['phone'])->whereHas('customer')->orderBy('id')->first()?->customer
            : null;
        $byEmail = $linked === null && $byPhone === null && $data['email'] !== null
            ? CustomerEmail::query()->where('email', $data['email'])->whereHas('customer')->orderBy('id')->first()?->customer
            : null;
        $match = $linked ?? $byPhone ?? $byEmail;

        $import->rows()->create([
            'square_id' => $data['square_id'],
            'data' => $data,
            'square_updated_at' => $data['updated_at'],
            'kind' => $linked !== null ? 'linked' : ($match !== null ? 'match' : 'new'),
            'customer_id' => $match?->id,
            'matched_by' => $linked !== null ? 'square_id' : ($byPhone !== null ? 'phone' : ($byEmail !== null ? 'email' : null)),
            'decision' => $match !== null ? 'merge' : 'new',
            'has_contact' => $data['phone'] !== null || $data['email'] !== null,
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $merged
     */
    private function applyRow(SquareImportCustomer $row, SquareImport $import, array &$counts, array &$merged): void
    {
        $data = $row->data;

        if ($row->decision === 'skip') {
            $counts['customers_skipped']++;

            return;
        }

        $existing = $row->decision === 'merge' && $row->customer_id !== null ? Customer::query()->find($row->customer_id) : null;

        if ($existing === null) {
            $this->create($data, $import);
            $counts['customers_created']++;

            return;
        }

        // Already imported and not changed at Square since: nothing to do.
        if ($row->kind === 'linked' && $existing->square_updated_at === $row->square_updated_at) {
            $counts['customers_skipped']++;

            return;
        }

        $merged[] = $this->merge($existing, $data);
        $counts[$row->kind === 'linked' ? 'customers_updated' : 'customers_merged']++;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(array $data, SquareImport $import): Customer
    {
        $customer = new Customer;
        $customer->forceFill([
            'type' => $data['company_name'] !== null && $data['first_name'] === null && $data['last_name'] === null ? CustomerType::Commercial : CustomerType::Residential,
            'first_name' => $data['first_name'] ?? ($data['company_name'] === null ? ($data['email'] ?? $data['phone'] ?? __('square_import.unnamed')) : null),
            'last_name' => $data['last_name'],
            'company_name' => $data['company_name'],
            'notes' => $data['notes'],
            'square_customer_id' => $data['square_id'],
            'square_import_id' => $import->id,
            'square_updated_at' => $data['updated_at'],
        ])->save();

        if ($data['phone'] !== null) {
            $customer->phones()->create(['label' => 'mobile', 'number' => $data['phone'], 'is_primary' => true]);
        }
        if ($data['email'] !== null) {
            $customer->emails()->create(['label' => 'personal', 'email' => $data['email'], 'is_primary' => true]);
        }
        if ($data['address'] !== null) {
            $customer->properties()->create([...$data['address'], 'is_primary' => true]);
        }

        return $customer;
    }

    /**
     * Fills what the existing customer lacks; never overwrites what is there. Returns what was added, for Undo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function merge(Customer $customer, array $data): array
    {
        $filled = [];
        foreach (['first_name', 'last_name', 'company_name', 'notes'] as $field) {
            if (blank($customer->getAttribute($field)) && $data[$field] !== null) {
                $customer->setAttribute($field, $data[$field]);
                $filled[] = $field;
            }
        }

        $record = ['customer_id' => $customer->id, 'filled' => $filled, 'previous_square_id' => $customer->square_customer_id, 'phone_ids' => [], 'email_ids' => [], 'property_ids' => []];
        $customer->forceFill(['square_customer_id' => $data['square_id'], 'square_updated_at' => $data['updated_at']])->save();

        if ($data['phone'] !== null && ! $customer->phones()->where('number_normalized', $data['phone'])->exists()) {
            $record['phone_ids'][] = $customer->phones()->create(['label' => 'mobile', 'number' => $data['phone'], 'is_primary' => ! $customer->phones()->exists()])->id;
        }
        if ($data['email'] !== null && ! $customer->emails()->where('email', $data['email'])->exists()) {
            $record['email_ids'][] = $customer->emails()->create(['label' => 'personal', 'email' => $data['email'], 'is_primary' => ! $customer->emails()->exists()])->id;
        }
        if ($data['address'] !== null && ! $customer->properties()->whereRaw('LOWER(line1) = ?', [mb_strtolower($data['address']['line1'])])->exists()) {
            $record['property_ids'][] = $customer->properties()->create([...$data['address'], 'is_primary' => ! $customer->properties()->exists()])->id;
        }

        return $record;
    }

    /**
     * Every invoice of every location (drafts left out), with the order's lines, taxes and payments. New and changed
     * invoices only; the rest is left as is.
     *
     * @param  array<string, int>  $counts
     */
    private function importInvoices(SquareImport $import, string $token, array &$counts): void
    {
        $invoices = [];
        foreach ($this->client->locations($token) as $location) {
            $cursor = null;
            do {
                $page = $this->client->invoices($token, (string) $location['id'], $cursor);
                foreach ($page['items'] as $invoice) {
                    if (($invoice['status'] ?? '') !== 'DRAFT') {
                        $invoices[] = $invoice;
                    }
                }
                $cursor = $page['cursor'];
            } while ($cursor !== null);
        }

        $import->forceFill(['progress_total' => count($invoices)])->save();
        $done = 0;

        foreach (array_chunk($invoices, 100) as $chunk) {
            $known = SquareInvoice::withTrashed()->whereIn('square_invoice_id', array_column($chunk, 'id'))->get()->keyBy('square_invoice_id');
            $changed = array_values(array_filter($chunk, fn (array $i) => ! isset($known[$i['id']]) || $known[$i['id']]->trashed() || $known[$i['id']]->square_updated_at !== ($i['updated_at'] ?? null)));
            $orders = collect($this->client->orders($token, array_values(array_filter(array_column($changed, 'order_id')))))->keyBy('id');

            foreach ($changed as $invoice) {
                $existing = $known[$invoice['id']] ?? null;
                $this->saveInvoice($invoice, $orders->get($invoice['order_id'] ?? '') ?? [], $existing, $import);
                $counts[$existing === null ? 'invoices_created' : 'invoices_updated']++;
            }

            $done += count($chunk);
            $import->forceFill(['progress_done' => $done])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $order
     */
    private function saveInvoice(array $invoice, array $order, ?SquareInvoice $existing, SquareImport $import): void
    {
        $company = currentCompany();
        $money = fn ($value) => is_array($value) ? (int) ($value['amount'] ?? 0) : 0;
        $requests = (array) ($invoice['payment_requests'] ?? []);
        $currency = (string) ($order['total_money']['currency'] ?? $requests[0]['computed_amount_money']['currency'] ?? $company->currency);
        $total = isset($order['total_money']) ? $money($order['total_money']) : array_sum(array_map(fn ($r) => $money($r['computed_amount_money'] ?? null), $requests));
        $tax = $money($order['total_tax_money'] ?? null);
        $paid = array_sum(array_map(fn ($r) => $money($r['total_completed_amount_money'] ?? null), $requests));
        $customerId = filled($invoice['primary_recipient']['customer_id'] ?? null)
            ? Customer::query()->where('square_customer_id', $invoice['primary_recipient']['customer_id'])->value('id')
            : null;
        $issued = $invoice['sale_or_service_date'] ?? (isset($invoice['created_at']) ? CarbonImmutable::parse($invoice['created_at'])->setTimezone($company->timezone)->toDateString() : null);

        $attributes = [
            'customer_id' => $customerId,
            'square_import_id' => $import->id,
            'number' => $invoice['invoice_number'] ?? null,
            'status' => strtolower((string) ($invoice['status'] ?? 'unknown')),
            'issued_on' => $issued,
            'due_on' => $requests[0]['due_date'] ?? null,
            'currency' => strtoupper(substr($currency, 0, 3)),
            'subtotal' => $total - $tax,
            'tax_total' => $tax,
            'total' => $total,
            'amount_paid' => $paid,
            'items' => array_map(fn (array $line) => [
                'name' => (string) ($line['name'] ?? $line['variation_name'] ?? '—'),
                'quantity' => (string) ($line['quantity'] ?? '1'),
                'amount' => $money($line['total_money'] ?? null),
                'tax' => $money($line['total_tax_money'] ?? null),
            ], array_values((array) ($order['line_items'] ?? []))),
            'taxes' => array_map(fn (array $t) => [
                'name' => (string) ($t['name'] ?? 'Tax'),
                'rate' => isset($t['percentage']) ? (string) $t['percentage'] : null,
                'amount' => $money($t['applied_money'] ?? null),
            ], array_values((array) ($order['taxes'] ?? []))),
            'payments' => array_map(fn (array $tender) => [
                'amount' => $money($tender['amount_money'] ?? null),
                'method' => strtolower((string) ($tender['type'] ?? 'other')),
                'paid_at' => $tender['created_at'] ?? null,
            ], array_values((array) ($order['tenders'] ?? []))),
            'square_updated_at' => $invoice['updated_at'] ?? null,
            'deleted_at' => null,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return;
        }

        (new SquareInvoice)->forceFill([...$attributes, 'square_invoice_id' => (string) $invoice['id']])->save();
    }
}
