<?php

namespace App\Support\Billing;

use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Billing\SyncInvoice;
use App\Actions\Customers\SaveCustomer;
use App\Actions\Customers\SaveProperty;
use App\Actions\Jobs\ChangeJobStatus;
use App\Actions\Jobs\SaveJob;
use App\Actions\Jobs\SaveVisit;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\LineKind;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AI\Assistant\InvoiceDraft;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Debtors from a spreadsheet (owner's task of November 19): a CSV with the columns name, phone, email, address, date,
 * work, amount (optional) and note. Preview first; Confirm finds each customer by phone or email (else creates one),
 * makes a Completed job on that date and an invoice that is open and not sent. A row without an amount gets a line
 * marked "amount needed". Code only (no AI). Must run in a tenant context.
 */
class DebtorImport
{
    public const COLUMNS = ['name', 'phone', 'email', 'address', 'date', 'work', 'amount', 'note'];

    public const MAX_ROWS = 500;

    public function __construct(
        private readonly SaveCustomer $saveCustomer,
        private readonly SaveProperty $saveProperty,
        private readonly SaveJob $saveJob,
        private readonly SaveVisit $saveVisit,
        private readonly ChangeJobStatus $changeStatus,
        private readonly SaveBillingDocument $saveDocument,
    ) {}

    /**
     * Reads the file into rows for the preview: each row with what was understood, the customer it matches and any
     * problem that keeps it out.
     *
     * @return list<array<string, mixed>>
     */
    public function read(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $contents) ?: [], fn ($line) => trim($line) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['file' => __('debtors.import.empty')]);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv($lines[0], escape: ''));
        if (! in_array('name', $header, true) || ! in_array('phone', $header, true) && ! in_array('email', $header, true)) {
            throw ValidationException::withMessages(['file' => __('debtors.import.columns', ['columns' => implode(', ', self::COLUMNS)])]);
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('debtors.import.too_many', ['max' => self::MAX_ROWS])]);
        }

        $rows = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            $cells = str_getcsv($line, escape: '');
            $raw = [];
            foreach (self::COLUMNS as $column) {
                $index = array_search($column, $header, true);
                $raw[$column] = $index === false ? '' : trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = $this->row($raw, $n + 2);
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $raw
     * @return array<string, mixed>
     */
    public function row(array $raw, int $line): array
    {
        $company = currentCompany();
        $phone = $raw['phone'] !== '' && PhoneNumber::isPossible($raw['phone'], $company->country) ? PhoneNumber::normalize($raw['phone'], $company->country) : null;
        $email = filter_var($raw['email'], FILTER_VALIDATE_EMAIL) ? strtolower($raw['email']) : null;
        $date = self::date($raw['date'], $company->timezone);
        $amount = self::amount($raw['amount'], $company->currencyDecimals());

        [$customer, $by] = InvoiceDraft::findCustomer((string) $phone, (string) $email);

        $problems = array_values(array_filter([
            $raw['name'] === '' && $customer === null ? __('debtors.import.no_name') : null,
            $phone === null && $email === null ? __('debtors.import.no_contact') : null,
            $date === null ? __('debtors.import.bad_date') : null,
            $raw['amount'] !== '' && $amount === null ? __('debtors.import.bad_amount') : null,
        ]));

        return [
            'line' => $line,
            'name' => $raw['name'],
            'phone' => $phone,
            'email' => $email,
            'address' => $raw['address'] !== '' ? $raw['address'] : null,
            'date' => $date,
            'work' => $raw['work'] !== '' ? $raw['work'] : null,
            'amount' => $amount,
            'note' => $raw['note'] !== '' ? $raw['note'] : null,
            'customer_id' => $customer?->id,
            'customer' => $customer?->display_name,
            'matched_by' => $by,
            'problems' => $problems,
        ];
    }

    /**
     * Confirm: every row without problems becomes a customer (found or new), a Completed job and an open invoice
     * that was not sent. Rows are checked again here; the preview is not trusted.
     *
     * @param  list<array<string, string|null>>  $input
     * @return list<Invoice>
     */
    public function confirm(array $input, User $user): array
    {
        $rows = array_map(fn (array $r, int $i) => $this->row(
            array_combine(self::COLUMNS, array_map(fn (string $c) => trim((string) ($r[$c] ?? '')), self::COLUMNS)), $i + 2,
        ), $input, array_keys($input));
        $brand = Brand::query()->where('is_active', true)->orderBy('id')->first();
        if ($brand === null) {
            throw ValidationException::withMessages(['rows' => __('assistant.invoice.no_brand')]);
        }

        return DB::transaction(function () use ($rows, $user, $brand) {
            $invoices = [];
            foreach ($rows as $row) {
                if ($row['problems'] !== []) {
                    continue;
                }
                $invoices[] = $this->import($row, $user, $brand);
            }

            return $invoices;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function import(array $row, User $user, Brand $brand): Invoice
    {
        $company = currentCompany();
        $customer = $row['customer_id'] ? Customer::query()->findOrFail($row['customer_id']) : $this->newCustomer($row);

        $property = $customer->primaryProperty()->first() ?? $customer->properties()->orderBy('id')->first();
        if ($property === null || ! $property->hasAddress()) {
            $property = $row['address'] !== null
                ? $this->saveProperty->handle($customer, $property, ['line1' => $row['address'], 'country' => $company->country])
                : ($property ?? $this->saveProperty->empty($customer));
        }

        $job = $this->saveJob->create($customer, [
            'brand_id' => $brand->id,
            'property_id' => $property->id,
            'job_type' => $company->vertical->jobTypes()[0]->value,
            'description' => $row['work'],
            'notes' => $row['note'],
        ], [], [], null, null, $user);

        // A finished visit on the day of the work, so the job shows on that date in its history.
        $start = CarbonImmutable::parse($row['date'], $company->timezone)->setTime(9, 0);
        $visit = $this->saveVisit->handle($job, null, ['scheduled_start' => $start->utc(), 'scheduled_end' => $start->addHour()->utc()], [], $user);
        $visit->forceFill(['status' => VisitStatus::Completed, 'started_at' => $start->utc(), 'finished_at' => $start->addHour()->utc()])->save();
        $job->refresh();
        $job->outcome = JobOutcome::Repaired;
        $this->changeStatus->handle($job, JobStatus::Completed, $user, $visit);

        $invoice = $this->saveDocument->createInvoice($job, [
            'issued_on' => $row['date'],
            'due_on' => null,
            'notes' => $row['note'],
            // The amount is what the customer owes: no tax is added on top.
            'tax_rate_ids' => [],
            'items' => [[
                'description' => $row['work'] ?? __('debtors.import.work'),
                'quantity' => '1',
                'unit_price' => $row['amount'] ?? 0,
                'taxable' => false,
                'kind' => LineKind::Service->value,
            ]],
        ], $user);

        if ($row['amount'] === null) {
            $invoice->items()->update(['needs_amount' => true]);
            app(SyncInvoice::class)->handle($invoice->refresh(), $user);
        }

        return $invoice->refresh();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function newCustomer(array $row): Customer
    {
        $parts = preg_split('/\s+/', trim((string) $row['name']), 2) ?: [];

        return $this->saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => $parts[0] ?? null, 'last_name' => $parts[1] ?? null],
            $row['phone'] ? [['number' => $row['phone'], 'label' => 'mobile', 'is_primary' => true]] : [],
            $row['email'] ? [['email' => $row['email'], 'label' => 'personal', 'is_primary' => true]] : [],
            $row['address'] ? ['line1' => $row['address'], 'country' => currentCompany()->country] : null,
        );
    }

    /**
     * "2026-10-03", "10/03/2026", "Oct 3, 2026" → Y-m-d; today and earlier only.
     */
    public static function date(string $value, string $timezone): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)
                : CarbonImmutable::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }

        return $date && $date->lessThanOrEqualTo(CarbonImmutable::now($timezone)->endOfDay()) ? $date->toDateString() : null;
    }

    /**
     * "180", "$180.50", "1,240.00" → minor units; empty → null.
     */
    public static function amount(string $value, int $decimals): ?int
    {
        $clean = preg_replace('/[^\d.,-]/', '', $value) ?? '';
        if ($clean === '') {
            return null;
        }
        $clean = str_contains($clean, '.') ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);
        if (! is_numeric($clean) || (float) $clean < 0) {
            return null;
        }

        return (int) round((float) $clean * (10 ** $decimals));
    }
}
