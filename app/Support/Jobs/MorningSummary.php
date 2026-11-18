<?php

namespace App\Support\Jobs;

use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\ServiceJob;
use App\Models\SmsThread;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\PhoneNumber;
use App\Support\Routing\DayOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The morning summary and the "Today" screen (owner's task of November 18), built by code only (no AI): the person's
 * jobs of the day in order, open jobs without an address, unanswered texts (from the night too), unpaid invoices
 * (count and sum per currency) and parts to receive. The office sees the whole company; a technician their own jobs.
 * Must run in a tenant context.
 */
class MorningSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user, ?CarbonImmutable $day = null): array
    {
        $company = currentCompany();
        $day ??= CarbonImmutable::now($company->timezone)->startOfDay();
        $office = Gate::forUser($user)->allows('dispatch', ServiceJob::class);

        $visits = JobVisit::query()
            ->whereBetween('scheduled_start', [$day->utc(), $day->endOfDay()->utc()])
            ->where('status', '!=', VisitStatus::Cancelled->value)
            ->when(! $office, fn ($q) => $q->whereHas('assignees', fn ($a) => $a->where('users.id', $user->id)))
            ->whereHas('job', fn ($q) => $q->visibleTo($user))
            ->with(['job.customer', 'job.property', 'assignees'])
            ->orderBy('scheduled_start')->get();
        $visits = DayOrder::sort($visits);

        $open = ServiceJob::query()->visibleTo($user)
            ->whereNotIn('status', [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled])
            ->whereNull('outcome')->whereNull('closed_at');

        $noAddress = (clone $open)->whereHas('property', fn ($q) => $q->where(fn ($p) => $p->whereNull('line1')->orWhere('line1', '')))
            ->with('customer')->latest('id')->limit(20)->get();

        $parts = (clone $open)->where('status', JobStatus::WaitingForParts->value)->with('customer')->latest('id')->limit(20)->get();

        $texts = [];
        $unpaid = null;
        if (Gate::forUser($user)->allows('viewAny', Message::class)) {
            $texts = SmsThread::query()->whereNotNull('awaiting_since')->orderBy('awaiting_since')->limit(20)->get()
                ->map(function (SmsThread $thread) use ($company) {
                    $latest = Message::query()->with('customer')->where('direction', Message::INBOUND)->where('from', $thread->phone)->latest('id')->first();

                    return [
                        'phone' => $thread->phone,
                        'name' => $latest?->customer?->display_name ?? PhoneNumber::display($thread->phone, $company->country),
                        'preview' => mb_substr((string) $latest?->body, 0, 120),
                        'since' => JobPresenter::iso($thread->awaiting_since),
                        'urgent' => $thread->urgent,
                        'url' => route('messages.index', ['phone' => $thread->phone]),
                    ];
                })->values()->all();
        }
        if (Gate::forUser($user)->allows('viewAny', Invoice::class)) {
            $rows = Invoice::query()->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
                ->where('balance', '>', 0)->selectRaw('currency, count(*) as count, sum(balance) as total')->groupBy('currency')->get();
            $unpaid = [
                'count' => (int) $rows->sum('count'),
                'totals' => $rows->map(fn ($row) => Money::format((int) $row->total, $row->currency, $company->locale))->values()->all(),
            ];
        }

        $job = fn (ServiceJob $job) => [
            'id' => $job->id, 'number' => $job->number, 'customer' => $job->customer?->display_name,
            'status_label' => $job->status->label(), 'part' => $job->part_name, 'part_number' => $job->part_number,
        ];

        return [
            'date' => $day->toDateString(),
            'jobs' => $visits->map(fn (JobVisit $visit) => [
                'id' => $visit->job->id,
                'number' => $visit->job->number,
                'customer' => $visit->job->customer?->display_name,
                'address' => $visit->job->property?->hasAddress() ? $visit->job->property->fullAddress() : null,
                'start' => JobPresenter::iso($visit->scheduled_start),
                'end' => JobPresenter::iso($visit->scheduled_end),
                'technicians' => $visit->assignees->pluck('name')->values()->all(),
                'status_label' => $visit->job->status->label(),
            ])->values()->all(),
            'no_address' => $noAddress->map($job)->values()->all(),
            'texts' => $texts,
            'unpaid' => $unpaid,
            'parts' => $parts->map($job)->values()->all(),
        ];
    }

    /**
     * The push / notification line: "4 jobs today · 2 unanswered texts · 3 unpaid ($1,240) · 1 part to receive".
     *
     * @param  array<string, mixed>  $summary
     */
    public static function line(array $summary): string
    {
        return collect([
            trans_choice('automation.today.line.jobs', count($summary['jobs'])),
            $summary['no_address'] !== [] ? trans_choice('automation.today.line.no_address', count($summary['no_address'])) : null,
            $summary['texts'] !== [] ? trans_choice('automation.today.line.texts', count($summary['texts'])) : null,
            ($summary['unpaid']['count'] ?? 0) > 0 ? trans_choice('automation.today.line.unpaid', $summary['unpaid']['count'], ['total' => implode(' + ', $summary['unpaid']['totals'])]) : null,
            $summary['parts'] !== [] ? trans_choice('automation.today.line.parts', count($summary['parts'])) : null,
        ])->filter()->implode(' · ');
    }
}
