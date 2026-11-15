<?php

namespace App\Services\AI\Assistant;

use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Models\AssistantAction;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\SquareInvoice;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Jobs\FreeWindows;
use App\Support\Jobs\JobBacklog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * What the assistant can look at (read-only) and the actions it can propose. Every query runs inside the current
 * company (tenant scope) and the person's own visibility; nothing here changes data. "propose_*" tools only create a
 * pending action that runs after the person presses Confirm (AssistantActions).
 */
class AssistantTools
{
    private const LIMIT = 50;

    public function __construct(private readonly AssistantActions $actions) {}

    /**
     * Tool definitions for the Messages API.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $date = ['type' => 'string', 'description' => 'YYYY-MM-DD'];
        $tool = fn (string $name, string $description, array $properties = [], array $required = []) => [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => (object) $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];

        return [
            $tool('unpaid_invoices', 'Unpaid and partly paid invoices with balance, due date and days overdue.', [
                'overdue_only' => ['type' => 'boolean', 'description' => 'Only invoices past their due date.'],
            ]),
            $tool('jobs_on_dates', 'Visits (jobs) scheduled from one date to another, with customer, address, window, technician and status.', [
                'from' => $date, 'to' => $date,
            ], ['from', 'to']),
            $tool('jobs_by_status', 'Unfinished jobs in one group: need_to_order_parts, waiting_for_parts, overdue (arrival window passed, not done) or not_completed (all unfinished).', [
                'status' => ['type' => 'string', 'enum' => ['need_to_order_parts', 'waiting_for_parts', 'overdue', 'not_completed']],
            ], ['status']),
            $tool('find_customers', 'Search customers by name, phone, email or address.', [
                'query' => ['type' => 'string'],
            ], ['query']),
            $tool('customer_history', 'A customer\'s jobs, estimates, invoices and payments (including imported from Square).', [
                'customer_id' => ['type' => 'integer'],
            ], ['customer_id']),
            $tool('estimates_waiting', 'Estimates sent to the customer and not yet approved or declined.'),
            $tool('revenue', 'Payments received and invoices issued in a period, per currency.', [
                'from' => $date, 'to' => $date,
            ], ['from', 'to']),
            $tool('free_windows', 'The company\'s arrival windows on a date with the technicians free in each.', [
                'date' => $date,
            ], ['date']),
            $tool('propose_payment_reminder', 'Propose sending the customer an SMS reminder to pay an invoice. Nothing is sent until the user presses Confirm.', [
                'invoice_id' => ['type' => 'integer'],
            ], ['invoice_id']),
            $tool('propose_reschedule', 'Propose moving a scheduled visit to another date and arrival window. Nothing changes until the user presses Confirm.', [
                'visit_id' => ['type' => 'integer'],
                'date' => $date,
                'start_time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour: start of the new arrival window'],
            ], ['visit_id', 'date', 'start_time']),
            $tool('propose_booking', 'Propose creating a new booking: pass everything the user said about it (customer, phone, address, appliance, problem, time) as text. After Confirm the booking form opens filled in; the user saves it.', [
                'text' => ['type' => 'string'],
            ], ['text']),
        ];
    }

    /**
     * Runs one tool and returns its result as JSON text for the AI.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $name, array $input, User $user, string $conversation): string
    {
        try {
            $result = match ($name) {
                'unpaid_invoices' => $this->unpaidInvoices((bool) ($input['overdue_only'] ?? false)),
                'jobs_on_dates' => $this->jobsOnDates((string) ($input['from'] ?? ''), (string) ($input['to'] ?? ''), $user),
                'jobs_by_status' => $this->jobsByStatus((string) ($input['status'] ?? ''), $user),
                'find_customers' => $this->findCustomers((string) ($input['query'] ?? '')),
                'customer_history' => $this->customerHistory((int) ($input['customer_id'] ?? 0), $user),
                'estimates_waiting' => $this->estimatesWaiting(),
                'revenue' => $this->revenue((string) ($input['from'] ?? ''), (string) ($input['to'] ?? '')),
                'free_windows' => ['date' => $input['date'] ?? null, 'windows' => FreeWindows::on(currentCompany(), $this->date((string) ($input['date'] ?? ''))->toDateString())],
                'propose_payment_reminder' => $this->actions->propose(AssistantAction::PAYMENT_REMINDER, $input, $user, $conversation),
                'propose_reschedule' => $this->actions->propose(AssistantAction::RESCHEDULE, $input, $user, $conversation),
                'propose_booking' => $this->actions->propose(AssistantAction::BOOKING, $input, $user, $conversation),
                default => ['error' => 'Unknown tool.'],
            };
        } catch (AssistantError $e) {
            $result = ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);
            $result = ['error' => 'The data could not be read.'];
        }

        return (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function money(int $minor, string $currency): string
    {
        return Money::format($minor, $currency, currentCompany()->locale);
    }

    private function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d')
            ? CarbonImmutable::createFromFormat('Y-m-d', $value, currentCompany()->timezone)
            : null;

        if ($date === null) {
            throw new AssistantError('Give the date as YYYY-MM-DD.');
        }

        return $date->startOfDay();
    }

    private function local(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(currentCompany()->timezone)->format('Y-m-d H:i');
    }

    /**
     * @return array<string, mixed>
     */
    private function unpaidInvoices(bool $overdueOnly): array
    {
        $today = CarbonImmutable::now(currentCompany()->timezone)->startOfDay();
        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid])
            ->where('balance', '>', 0)
            ->when($overdueOnly, fn (Builder $q) => $q->whereNotNull('due_on')->where('due_on', '<', $today->toDateString()))
            ->with('customer')
            ->orderBy('due_on')
            ->limit(self::LIMIT)
            ->get();

        return [
            'count' => $invoices->count(),
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'invoice_id' => $i->id,
                'number' => $i->number,
                'customer' => $i->customer?->display_name,
                'customer_id' => $i->customer_id,
                'balance' => $this->money($i->balance, $i->currency),
                'total' => $this->money($i->total, $i->currency),
                'issued_on' => $i->issued_on->toDateString(),
                'due_on' => $i->due_on?->toDateString(),
                'days_overdue' => $i->due_on !== null && $i->due_on->lt($today) ? (int) $i->due_on->diffInDays($today) : 0,
            ])->values()->all(),
            'totals_by_currency' => $invoices->groupBy('currency')->map(fn (Collection $g, string $c) => $this->money((int) $g->sum('balance'), $c))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobsOnDates(string $from, string $to, User $user): array
    {
        $start = $this->date($from);
        $end = $this->date($to)->endOfDay();

        if ($end->lt($start) || $start->diffInDays($end) > 62) {
            throw new AssistantError('Use a period of up to two months, from before to.');
        }

        $visits = JobVisit::query()
            ->whereBetween('scheduled_start', [$start->utc(), $end->utc()])
            ->whereHas('job', fn (Builder $q) => $q->visibleTo($user))
            ->with(['job.customer', 'job.property', 'assignees'])
            ->orderBy('scheduled_start')
            ->limit(self::LIMIT)
            ->get();

        return ['count' => $visits->count(), 'visits' => $visits->map(fn (JobVisit $v) => $this->visit($v))->values()->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(JobVisit $v): array
    {
        return [
            'visit_id' => $v->id,
            'job_id' => $v->service_job_id,
            'job_number' => $v->job?->number,
            'customer' => $v->job?->customer?->display_name,
            'address' => $v->job?->property?->fullAddress(),
            'window' => $this->local($v->scheduled_start).' - '.CarbonImmutable::instance($v->scheduled_end)->setTimezone(currentCompany()->timezone)->format('H:i'),
            'technicians' => $v->assignees->pluck('name')->values()->all(),
            'visit_status' => $v->status->value,
            'job_status' => $v->job?->status->value,
            'problem' => $v->job?->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobsByStatus(string $status, User $user): array
    {
        $reasons = match ($status) {
            'need_to_order_parts' => ['need_to_order_parts'],
            'waiting_for_parts' => ['waiting_for_parts'],
            'overdue' => ['overdue'],
            'not_completed' => JobBacklog::REASONS,
            default => throw new AssistantError('Unknown status.'),
        };

        $jobs = JobBacklog::query($user)
            ->whereIn('backlog_reason', $reasons)
            ->with(['customer', 'property'])
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get();

        return [
            'count' => $jobs->count(),
            'jobs' => $jobs->map(fn (ServiceJob $j) => [
                'job_id' => $j->id,
                'number' => $j->number,
                'customer' => $j->customer?->display_name,
                'address' => $j->property?->fullAddress(),
                'status' => $j->status->value,
                'group' => $j->getAttribute('backlog_reason'),
                'created' => $j->created_at?->toDateString(),
                'status_since' => $j->updated_at?->toDateString(),
                'problem' => $j->description,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findCustomers(string $query): array
    {
        if (mb_strlen(trim($query)) < 2) {
            throw new AssistantError('Give at least 2 characters to search.');
        }

        $customers = Customer::query()->search($query)->with(['primaryPhone', 'properties'])->orderBy('display_name')->limit(20)->get();

        return ['count' => $customers->count(), 'customers' => $customers->map(fn (Customer $c) => [
            'customer_id' => $c->id,
            'name' => $c->display_name,
            'phone' => $c->primaryPhone?->number,
            'addresses' => $c->properties->map(fn ($p) => $p->fullAddress())->values()->all(),
        ])->values()->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerHistory(int $id, User $user): array
    {
        $customer = Customer::query()->with(['primaryPhone', 'primaryEmail'])->find($id);

        if ($customer === null) {
            throw new AssistantError('No customer with this id.');
        }

        $jobs = ServiceJob::query()->visibleTo($user)->where('customer_id', $customer->id)->latest('id')->limit(self::LIMIT)->get();
        $estimates = Estimate::query()->where('customer_id', $customer->id)->latest('id')->limit(self::LIMIT)->get();
        $invoices = Invoice::query()->where('customer_id', $customer->id)->latest('id')->limit(self::LIMIT)->get();
        $payments = Payment::query()->whereIn('invoice_id', $invoices->pluck('id'))->latest('received_at')->limit(self::LIMIT)->get();

        return [
            'customer' => ['customer_id' => $customer->id, 'name' => $customer->display_name, 'phone' => $customer->primaryPhone?->number, 'email' => $customer->primaryEmail?->email, 'notes' => $customer->notes],
            'jobs' => $jobs->map(fn (ServiceJob $j) => ['job_id' => $j->id, 'number' => $j->number, 'status' => $j->status->value, 'created' => $j->created_at?->toDateString(), 'problem' => $j->description])->values()->all(),
            'estimates' => $estimates->map(fn (Estimate $e) => ['estimate_id' => $e->id, 'number' => $e->number, 'status' => $e->status->value, 'total' => $this->money($e->total, $e->currency), 'issued_on' => $e->issued_on->toDateString()])->values()->all(),
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'invoice_id' => $i->id, 'number' => $i->number, 'status' => $i->status->value, 'total' => $this->money($i->total, $i->currency),
                'balance' => $this->money($i->balance, $i->currency), 'issued_on' => $i->issued_on->toDateString(),
            ])->values()->all(),
            'invoices_imported_from_square' => SquareInvoice::query()->where('customer_id', $customer->id)->orderByDesc('issued_on')->limit(self::LIMIT)->get()
                ->map(fn (SquareInvoice $i) => [
                    'number' => $i->number, 'status' => $i->status, 'issued_on' => $i->issued_on?->toDateString(),
                    'total' => $this->money($i->total, $i->currency), 'paid' => $this->money($i->amount_paid, $i->currency),
                    'payments' => collect($i->payments)->map(fn (array $p) => ['amount' => $this->money((int) $p['amount'], $i->currency), 'method' => $p['method'], 'paid_at' => $p['paid_at']])->all(),
                ])->values()->all(),
            'payments' => $payments->map(fn (Payment $p) => ['amount' => $this->money($p->amount, $p->currency), 'method' => $p->method->value, 'received' => $this->local($p->received_at), 'invoice_id' => $p->invoice_id])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function estimatesWaiting(): array
    {
        $estimates = Estimate::query()
            ->where('status', EstimateStatus::Draft)
            ->whereNotNull('sent_at')
            ->with('customer')
            ->orderBy('sent_at')
            ->limit(self::LIMIT)
            ->get();

        return ['count' => $estimates->count(), 'estimates' => $estimates->map(fn (Estimate $e) => [
            'estimate_id' => $e->id, 'number' => $e->number, 'customer' => $e->customer?->display_name, 'job_id' => $e->service_job_id,
            'total' => $this->money($e->total, $e->currency), 'sent' => $this->local($e->sent_at),
            'days_waiting' => (int) $e->sent_at->diffInDays(now()), 'viewed' => $e->viewed_at !== null,
        ])->values()->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenue(string $from, string $to): array
    {
        $start = $this->date($from);
        $end = $this->date($to)->endOfDay();

        if ($end->lt($start)) {
            throw new AssistantError('The end is before the start.');
        }

        $payments = Payment::query()->whereNull('voided_at')->whereBetween('received_at', [$start->utc(), $end->utc()])->get();
        $invoices = Invoice::query()->where('status', '!=', InvoiceStatus::Void)->whereBetween('issued_on', [$start->toDateString(), $end->toDateString()])->get();

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'payments_received' => $payments->groupBy('currency')->map(fn (Collection $g, string $c) => ['amount' => $this->money((int) $g->sum('amount'), $c), 'count' => $g->count(),
                'by_method' => $g->groupBy(fn (Payment $p) => $p->method->value)->map(fn (Collection $m) => $this->money((int) $m->sum('amount'), $c))->all()])->all(),
            'invoices_issued' => $invoices->groupBy('currency')->map(fn (Collection $g, string $c) => ['total' => $this->money((int) $g->sum('total'), $c), 'count' => $g->count(), 'still_owed' => $this->money((int) $g->sum('balance'), $c)])->all(),
        ];
    }
}
