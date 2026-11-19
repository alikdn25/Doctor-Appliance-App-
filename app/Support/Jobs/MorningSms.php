<?php

namespace App\Support\Jobs;

use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Messaging\SmsThreads;
use App\Models\Company;
use App\Models\CustomerPhone;
use App\Models\DriveBackup;
use App\Models\Invoice;
use App\Models\JobPart;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\SmsThread;
use App\Models\Task;
use App\Support\Billing\Money;
use App\Support\Locale\LocalTime;
use App\Support\PhoneNumber;
use App\Support\Routing\DayOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The morning SMS to the owner's own phone (owner's task of November 19), instead of the morning push: the day's jobs
 * in order (time, name, phone, address, what is broken), jobs without an address, unanswered texts (the night ones
 * too), customers who stay quiet, unpaid invoices (count and sum), completed jobs without an invoice, the
 * day's tasks, parts to buy and to receive and "Backup failed" when the night's Google Drive backup did not go through. Built by code only (no AI), from the
 * whole company. Must run in a tenant context.
 */
class MorningSms
{
    public static function build(Company $company, CarbonImmutable $now, bool $markReported = false): string
    {
        $tz = $company->timezone;
        $day = $now->setTimezone($tz)->startOfDay();
        $time = fn ($at) => LocalTime::format(CarbonImmutable::instance($at), $tz, $company->locale);
        $lines = [__('automation.morning_sms.hello', ['date' => $day->isoFormat('ddd MMM D')])];

        $visits = DayOrder::sort(JobVisit::query()
            ->whereBetween('scheduled_start', [$day->utc(), $day->endOfDay()->utc()])
            ->whereIn('status', VisitStatus::openValues())
            ->whereHas('job')
            ->with(['job.customer.primaryPhone', 'job.property', 'job.appliances'])
            ->orderBy('scheduled_start')->get());

        if ($visits->isEmpty()) {
            $lines[] = __('automation.morning_sms.no_jobs');
        }
        foreach ($visits->values() as $i => $visit) {
            $job = $visit->job;
            $what = trim(implode(' ', array_filter([
                $job->appliances->map(fn ($a) => $a->type->label())->unique()->implode(', '),
                $job->description ? '— '.mb_strimwidth((string) $job->description, 0, 60, '…') : null,
            ])));
            $lines[] = ($i + 1).'. '.$time($visit->scheduled_start).'–'.$time($visit->scheduled_end).' '.implode(', ', array_filter([
                $job->customer?->display_name,
                $job->customer?->primaryPhone ? PhoneNumber::display($job->customer->primaryPhone->number, $company->country) : null,
                $job->property?->hasAddress() ? $job->property->fullAddress() : __('automation.morning_sms.no_address_short'),
                $what !== '' ? $what : null,
            ]));
        }

        // The Owners' tasks of today (all-day first), Home ones included: the SMS goes to the owner's own phone.
        $tasks = Task::query()->whereNull('completed_at')->where('task_date', $day->toDateString())
            ->whereHas('user.memberships', fn ($q) => $q->where('company_id', $company->id)->where('role', UserRole::Owner->value))
            ->orderByDesc('all_day')->orderBy('start_time')->orderBy('id')->limit(15)->get();
        if ($tasks->isNotEmpty()) {
            $lines[] = __('automation.morning_sms.tasks', ['tasks' => $tasks->map(fn (Task $t) => ($t->all_day ? '' : LocalTime::format($t->starts_at, $tz, $company->locale).' ').$t->title)->implode('; ')]);
        }

        $open = ServiceJob::query()
            ->whereNotIn('status', [JobStatus::Completed, JobStatus::Invoiced, JobStatus::Paid, JobStatus::Cancelled])
            ->whereNull('outcome')->whereNull('closed_at');
        $noAddress = (clone $open)->whereHas('property', fn ($q) => $q->where(fn ($p) => $p->whereNull('line1')->orWhere('line1', '')))
            ->with('customer')->latest('id')->limit(10)->get();
        if ($noAddress->isNotEmpty()) {
            $lines[] = __('automation.morning_sms.no_address', ['jobs' => $noAddress->map(fn (ServiceJob $j) => '#'.$j->number.' '.$j->customer?->display_name)->implode(', ')]);
        }

        $texts = SmsThread::query()->whereNotNull('awaiting_since')->orderBy('awaiting_since')->limit(10)->get();
        if ($texts->isNotEmpty()) {
            $lines[] = trans_choice('automation.morning_sms.texts', $texts->count(), [
                'names' => $texts->map(fn (SmsThread $t) => self::name($t->phone, $company))->implode(', '),
            ]);
        }

        $quiet = SmsThreads::quietForMorning($company, $day, $markReported);
        if ($quiet !== []) {
            $lines[] = __('automation.morning_sms.quiet', ['names' => collect($quiet)->map(fn (array $q) => $q['name'])->implode(', ')]);
        }

        $unpaid = Invoice::query()->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance', '>', 0)->selectRaw('currency, count(*) as count, sum(balance) as total')->groupBy('currency')->get();
        if ($unpaid->sum('count') > 0) {
            $lines[] = trans_choice('automation.morning_sms.unpaid', (int) $unpaid->sum('count'), [
                'total' => $unpaid->map(fn ($row) => Money::format((int) $row->total, $row->currency, $company->locale))->implode(' + '),
            ]);
        }

        $noInvoice = self::completedWithoutInvoice()->count();
        if ($noInvoice > 0) {
            $lines[] = trans_choice('automation.morning_sms.no_invoice', $noInvoice);
        }

        // Parts to buy (by supplier) and to receive (ordered, waiting).
        $toBuy = JobPart::query()->where('status', JobPart::TO_ORDER)->whereHas('job', fn ($q) => $q->where('status', '!=', JobStatus::Cancelled->value))
            ->with('supplier')->get();
        if ($toBuy->isNotEmpty()) {
            $lines[] = trans_choice('automation.morning_sms.parts_to_buy', $toBuy->count(), [
                'parts' => $toBuy->groupBy(fn (JobPart $p) => $p->supplier?->name ?? __('parts.no_supplier'))
                    ->map(fn ($group, $supplier) => $supplier.': '.$group->pluck('name')->implode(', '))->implode('; '),
            ]);
        }
        $toReceive = JobPart::query()->where('status', JobPart::ORDERED)->whereHas('job', fn ($q) => $q->where('status', '!=', JobStatus::Cancelled->value))
            ->with('job')->orderBy('ordered_on')->limit(15)->get();
        if ($toReceive->isNotEmpty()) {
            $lines[] = trans_choice('automation.morning_sms.parts', $toReceive->count(), [
                'jobs' => $toReceive->map(fn (JobPart $p) => '#'.$p->job->number.' '.$p->name)->implode(', '),
            ]);
        }

        if (self::backupFailed($company, $day)) {
            $lines[] = __('automation.morning_sms.backup_failed');
        }

        return implode("\n", $lines);
    }

    /**
     * Jobs marked Completed that have no invoice yet (a void one does not count).
     *
     * @return Builder<ServiceJob>
     */
    public static function completedWithoutInvoice(): Builder
    {
        return ServiceJob::query()->where('status', JobStatus::Completed->value)
            ->whereDoesntHave('invoices', fn ($q) => $q->where('status', '!=', InvoiceStatus::Void->value));
    }

    /**
     * The night's Drive backup did not go through: Drive is connected and today's copy failed or is missing.
     */
    public static function backupFailed(Company $company, CarbonImmutable $todayStart): bool
    {
        if ($company->google_drive_token === null) {
            return false;
        }

        $latest = DriveBackup::query()->latest('id')->first();

        return $latest === null || $latest->status === DriveBackup::FAILED
            || $latest->created_at->lessThan($todayStart->utc());
    }

    private static function name(string $phone, Company $company): string
    {
        $customer = CustomerPhone::query()->with('customer')->where('number_normalized', $phone)->first()?->customer;

        return $customer?->display_name ?? PhoneNumber::display($phone, $company->country);
    }
}
