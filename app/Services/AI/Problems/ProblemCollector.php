<?php

namespace App\Services\AI\Problems;

use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\BackupRun;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobStatusChange;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Jobs\JobBacklog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Find problems, the part done by code (no AI): the business checks inside the current company and the technical
 * checks of the last 7 days. Platform-wide technical data (Laravel log, failed queue jobs, backups) is only read for a
 * platform administrator, because it can hold other companies' data.
 */
class ProblemCollector
{
    /** Items listed per check; the count says how many there are in all. */
    private const ITEMS = 20;

    private const TECH_DAYS = 7;

    /**
     * @return array{business: list<array<string, mixed>>, technical: list<array<string, mixed>>}
     */
    public function collect(User $user): array
    {
        $business = array_values(array_filter([
            $this->overdueInvoices(),
            $this->partsWaiting($user),
            $this->estimatesWithoutAnswer(),
            $this->unclosedPastJobs($user),
            $this->scheduleOverlaps(),
            $this->customersMissingInfo(),
            $this->duplicateCustomers(),
        ]));

        $technical = array_values(array_filter([
            $this->smsNotSent(),
            ...($user->is_super_admin ? [
                $this->logErrors(),
                $this->squareErrors(),
                $this->failedQueueJobs(),
                $this->failedBackups(),
            ] : []),
        ]));

        return ['business' => $business, 'technical' => $technical];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function finding(string $check, string $title, Collection $items, ?int $count = null): ?array
    {
        if ($items->isEmpty()) {
            return null;
        }

        return ['check' => $check, 'title' => $title, 'count' => $count ?? $items->count(), 'items' => $items->take(self::ITEMS)->values()->all()];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(currentCompany()->timezone)->startOfDay();
    }

    private function money(int $minor, string $currency): string
    {
        return Money::format($minor, $currency, currentCompany()->locale);
    }

    /** @return array<string, mixed>|null */
    public function overdueInvoices(): ?array
    {
        $today = $this->today();
        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid])
            ->where('balance', '>', 0)
            ->whereNotNull('due_on')
            ->where('due_on', '<', $today->toDateString())
            ->with('customer')
            ->orderBy('due_on')
            ->get();

        return $this->finding('overdue_invoices', 'Invoices past their due date', $invoices->map(fn (Invoice $i) => [
            'type' => 'invoice', 'id' => $i->id, 'label' => "Invoice {$i->number} — {$i->customer?->display_name}",
            'detail' => $this->money($i->balance, $i->currency).' owed, '.(int) $i->due_on->diffInDays($today).' days overdue',
        ]));
    }

    /** @return array<string, mixed>|null */
    public function partsWaiting(User $user): ?array
    {
        $days = currentCompany()->parts_stale_days ?? 7;
        $limit = now()->subDays($days);
        $jobs = ServiceJob::query()->visibleTo($user)
            ->whereIn('status', [JobStatus::NeedToOrderParts, JobStatus::WaitingForParts])
            ->with('customer')
            ->get()
            ->map(function (ServiceJob $job) {
                $since = JobStatusChange::query()->where('service_job_id', $job->id)->where('to_status', $job->status)->latest('created_at')->value('created_at');

                return [$job, $since !== null ? CarbonImmutable::parse($since) : CarbonImmutable::instance($job->updated_at ?? $job->created_at)];
            })
            ->filter(fn (array $pair) => $pair[1]->lt($limit))
            ->sortBy(fn (array $pair) => $pair[1]->timestamp);

        return $this->finding('parts_waiting', "Jobs in Need to order parts or Waiting for parts longer than {$days} days", $jobs->map(fn (array $pair) => [
            'type' => 'job', 'id' => $pair[0]->id, 'label' => "Job #{$pair[0]->number} — {$pair[0]->customer?->display_name}",
            'detail' => $pair[0]->status->label().' for '.(int) $pair[1]->diffInDays(now()).' days',
        ]));
    }

    /** @return array<string, mixed>|null */
    public function estimatesWithoutAnswer(): ?array
    {
        $days = currentCompany()->estimate_stale_days ?? 3;
        $estimates = Estimate::query()
            ->where('status', EstimateStatus::Draft)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', now()->subDays($days))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $this->today()->toDateString()))
            ->with('customer')
            ->orderBy('sent_at')
            ->get();

        return $this->finding('estimates_waiting', "Estimates without an answer longer than {$days} days", $estimates->map(fn (Estimate $e) => [
            'type' => 'estimate', 'id' => $e->id, 'label' => "Estimate {$e->number} — {$e->customer?->display_name}",
            'detail' => $this->money($e->total, $e->currency).', sent '.(int) $e->sent_at->diffInDays(now()).' days ago'.($e->viewed_at ? ', viewed' : ', not viewed'),
        ]));
    }

    /** @return array<string, mixed>|null */
    public function unclosedPastJobs(User $user): ?array
    {
        $jobs = JobBacklog::query($user)->where('backlog_reason', 'overdue')->with('customer')->orderBy('created_at')->get();

        return $this->finding('unclosed_past_jobs', 'Jobs not closed although their visit date has passed', $jobs->map(fn (ServiceJob $j) => [
            'type' => 'job', 'id' => $j->id, 'label' => "Job #{$j->number} — {$j->customer?->display_name}", 'detail' => $j->status->label(),
        ]));
    }

    /** @return array<string, mixed>|null */
    public function scheduleOverlaps(): ?array
    {
        $from = $this->today()->utc();
        $visits = JobVisit::query()
            ->whereIn('status', VisitStatus::openValues())
            ->whereBetween('scheduled_start', [$from, $from->addDays(14)])
            ->with(['assignees', 'job'])
            ->orderBy('scheduled_start')
            ->get();

        $pairs = collect();
        $byPerson = [];
        foreach ($visits as $visit) {
            foreach ($visit->assignees as $person) {
                $byPerson[$person->id][] = [$visit, $person->name];
            }
        }
        foreach ($byPerson as $list) {
            for ($i = 0; $i < count($list); $i++) {
                for ($j = $i + 1; $j < count($list); $j++) {
                    [$a, $name] = $list[$i];
                    [$b] = $list[$j];
                    if ($a->scheduled_start->lt($b->scheduled_end) && $b->scheduled_start->lt($a->scheduled_end)) {
                        $pairs->push([
                            'type' => 'job', 'id' => $a->service_job_id,
                            'label' => "{$name}: job #{$a->job?->number} and job #{$b->job?->number}",
                            'detail' => CarbonImmutable::instance($a->scheduled_start)->setTimezone(currentCompany()->timezone)->format('Y-m-d H:i').' overlaps '.CarbonImmutable::instance($b->scheduled_start)->setTimezone(currentCompany()->timezone)->format('H:i'),
                            'other_job_id' => $b->service_job_id,
                        ]);
                    }
                }
            }
        }

        return $this->finding('schedule_overlaps', 'Technician schedule overlaps in the next 14 days', $pairs);
    }

    /** @return array<string, mixed>|null */
    public function customersMissingInfo(): ?array
    {
        $query = Customer::query()->where(fn (Builder $q) => $q->whereDoesntHave('phones')->orWhereDoesntHave('properties'));
        $count = (clone $query)->count();
        $customers = $query->withCount(['phones', 'properties'])->orderByDesc('id')->limit(self::ITEMS)->get();

        return $this->finding('customers_missing_info', 'Customers without a phone or an address', $customers->map(fn (Customer $c) => [
            'type' => 'customer', 'id' => $c->id, 'label' => $c->display_name,
            'detail' => implode(', ', array_filter([$c->phones_count === 0 ? 'no phone' : null, $c->properties_count === 0 ? 'no address' : null])),
        ]), $count);
    }

    /** @return array<string, mixed>|null */
    public function duplicateCustomers(): ?array
    {
        $groups = collect();

        CustomerPhone::query()->whereHas('customer')
            ->select('number_normalized')->groupBy('number_normalized')->havingRaw('count(distinct customer_id) > 1')
            ->pluck('number_normalized')
            ->each(fn (string $number) => $groups->push(['by' => 'phone '.$number, 'ids' => CustomerPhone::query()->where('number_normalized', $number)->whereHas('customer')->distinct()->pluck('customer_id')->all()]));

        CustomerEmail::query()->whereHas('customer')
            ->select('email')->groupBy('email')->havingRaw('count(distinct customer_id) > 1')
            ->pluck('email')
            ->each(fn (string $email) => $groups->push(['by' => 'email '.$email, 'ids' => CustomerEmail::query()->where('email', $email)->whereHas('customer')->distinct()->pluck('customer_id')->all()]));

        $names = Customer::query()->whereIn('id', $groups->pluck('ids')->flatten()->unique())->pluck('display_name', 'id');

        return $this->finding('duplicate_customers', 'Possible duplicate customers', $groups->map(fn (array $g) => [
            'type' => 'customer', 'id' => (int) $g['ids'][0],
            'label' => collect($g['ids'])->map(fn ($id) => $names[$id] ?? "#{$id}")->implode(' / '),
            'detail' => 'same '.$g['by'],
            'other_customer_ids' => array_map('intval', $g['ids']),
        ]));
    }

    /** @return array<string, mixed>|null */
    public function smsNotSent(): ?array
    {
        $messages = Message::query()
            ->where('direction', Message::OUTBOUND)
            ->whereIn('status', ['failed', 'blocked'])
            ->where('created_at', '>=', now()->subDays(self::TECH_DAYS))
            ->get();

        return $this->finding('sms_not_sent', 'Texts not sent in the last 7 days', $messages
            ->groupBy(fn (Message $m) => $m->status.': '.($m->status_reason ?? 'no reason'))
            ->map(fn (Collection $group, string $reason) => ['type' => null, 'id' => null, 'label' => $reason, 'detail' => $group->count().' texts'])
            ->values(), $messages->count());
    }

    /**
     * Errors in the Laravel log of the last 7 days, grouped by message.
     *
     * @return array<string, mixed>|null
     */
    public function logErrors(?string $filter = null, string $check = 'log_errors', string $title = 'Errors in the Laravel log in the last 7 days'): ?array
    {
        $since = now()->subDays(self::TECH_DAYS);
        $groups = [];

        foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
            if (filemtime($file) < $since->timestamp) {
                continue;
            }
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+)[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING): (.*)$/', $line, $m) !== 1) {
                    continue;
                }
                if ($m[2] === 'WARNING' && $filter === null) {
                    continue;
                }
                if ($filter !== null && stripos($m[3], $filter) === false) {
                    continue;
                }
                $at = CarbonImmutable::parse($m[1]);
                if ($at->lt($since)) {
                    continue;
                }
                // Same error with other numbers, ids or quoted values is one group.
                $key = Str::limit((string) preg_replace(['/\d+/', "/'[^']*'/", '/"[^"]*"/'], ['N', "'…'", '"…"'], strtok($m[3], '{') ?: $m[3]), 200, '');
                $groups[$key] ??= ['count' => 0, 'last' => $at];
                $groups[$key]['count']++;
                $groups[$key]['last'] = $at->max($groups[$key]['last']);
            }
            fclose($handle);
        }

        arsort($groups);
        $items = collect($groups)->sortByDesc('count')->map(fn (array $g, string $message) => [
            'type' => null, 'id' => null, 'label' => $message, 'detail' => $g['count'].' times, last '.$g['last']->format('Y-m-d H:i'),
        ])->values();

        return $this->finding($check, $title, $items, (int) collect($groups)->sum('count'));
    }

    /** @return array<string, mixed>|null */
    public function squareErrors(): ?array
    {
        return $this->logErrors('Square', 'square_errors', 'Square sync errors in the last 7 days');
    }

    /** @return array<string, mixed>|null */
    public function failedQueueJobs(): ?array
    {
        $rows = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(self::TECH_DAYS))->get(['payload', 'exception', 'failed_at']);

        $items = $rows->groupBy(function ($row) {
            $job = json_decode((string) $row->payload, true)['displayName'] ?? 'Job';

            return $job.': '.Str::limit((string) strtok((string) $row->exception, "\n"), 200, '');
        })->map(fn (Collection $group, string $key) => ['type' => null, 'id' => null, 'label' => $key, 'detail' => $group->count().' times'])->values();

        return $this->finding('failed_queue_jobs', 'Failed queue jobs in the last 7 days', $items, $rows->count());
    }

    /** @return array<string, mixed>|null */
    public function failedBackups(): ?array
    {
        $runs = BackupRun::query()->where('status', 'failed')->where('started_at', '>=', now()->subDays(self::TECH_DAYS))->orderByDesc('started_at')->get();

        return $this->finding('failed_backups', 'Backups that failed in the last 7 days', $runs->map(fn (BackupRun $r) => [
            'type' => null, 'id' => null, 'label' => "{$r->kind} backup ({$r->reason})", 'detail' => $r->started_at->format('Y-m-d H:i').': '.Str::limit((string) $r->error, 200),
        ]));
    }
}
