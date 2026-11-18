<?php

namespace App\Support\Backups;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\DriveBackup;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\User;
use App\Notifications\AppAlert;
use App\Support\Billing\Money;
use App\Support\Locale\LocalTime;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly backup to the company's own Google Drive (owner's task of November 19): at 03:00 company time two CSV
 * files go to the folder "{company} Backup" — every customer (name, phones, email, address, date of the last job,
 * appliances, balance) and the jobs of the next 7 days (date, window, customer, phone, address, problem, technician).
 * The 30 newest copies are kept; older files are deleted from that folder. A failed upload notifies the Owners and is a
 * line in the morning SMS. Must run in a tenant context.
 */
class DriveBackups
{
    public const KEEP = 30;

    public const HOUR = 3;

    public function __construct(private readonly GoogleDrive $drive) {}

    public function run(Company $company, CarbonImmutable $now): DriveBackup
    {
        try {
            $token = $this->drive->accessToken((string) $company->google_drive_token);
            $folder = $this->drive->folder($token, $company->google_drive_folder_id, __('automation.backup.folder', ['company' => $company->name]));
            if ($folder !== $company->google_drive_folder_id) {
                $company->forceFill(['google_drive_folder_id' => $folder])->save();
            }

            $stamp = $now->setTimezone($company->timezone)->format('Y-m-d');
            $files = [];
            foreach (["customers-{$stamp}.csv" => $this->customers($company), "jobs-next-7-days-{$stamp}.csv" => $this->jobs($company, $now)] as $name => $csv) {
                $files[] = ['id' => $this->drive->upload($token, $folder, $name, $csv), 'name' => $name];
            }

            $backup = DriveBackup::query()->create(['status' => DriveBackup::OK, 'files' => $files]);
            $this->prune($token);

            return $backup;
        } catch (Throwable $e) {
            Log::warning('Google Drive backup failed', ['company' => $company->id, 'error' => $e->getMessage()]);
            $backup = DriveBackup::query()->create(['status' => DriveBackup::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->alert($company, $e->getMessage());

            return $backup;
        }
    }

    /**
     * Keeps the 30 newest good copies; the files of older ones are deleted from Drive, then their rows. Failed runs
     * older than 30 days are forgotten.
     */
    public function prune(string $token): int
    {
        $old = DriveBackup::query()->where('status', DriveBackup::OK)->orderByDesc('id')->skip(self::KEEP)->take(1000)->get();

        foreach ($old as $backup) {
            foreach ((array) $backup->files as $file) {
                $this->drive->delete($token, $file['id']);
            }
            $backup->delete();
        }

        DriveBackup::query()->where('status', DriveBackup::FAILED)->where('created_at', '<', now()->subDays(self::KEEP))->delete();

        return $old->count();
    }

    public function customers(Company $company): string
    {
        $rows = [['Name', 'Phones', 'Email', 'Address', 'Last job', 'Appliances', 'Balance']];
        $balances = Invoice::query()->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance', '>', 0)->groupBy('customer_id', 'currency')
            ->select('customer_id', 'currency', DB::raw('sum(balance) as total'))->get()->groupBy('customer_id');
        $lastJobs = JobVisit::query()->join('service_jobs', 'service_jobs.id', '=', 'job_visits.service_job_id')
            ->where('job_visits.status', '!=', VisitStatus::Cancelled->value)
            ->groupBy('service_jobs.customer_id')->select('service_jobs.customer_id', DB::raw('max(job_visits.scheduled_start) as last_at'))
            ->pluck('last_at', 'service_jobs.customer_id');

        Customer::query()->with(['phones', 'emails', 'primaryProperty', 'appliances'])->orderBy('id')
            ->chunk(500, function ($customers) use (&$rows, $company, $balances, $lastJobs) {
                foreach ($customers as $customer) {
                    $last = $lastJobs[$customer->id] ?? null;
                    $rows[] = [
                        $customer->display_name,
                        $customer->phones->map(fn (CustomerPhone $p) => PhoneNumber::display($p->number, $company->country))->implode('; '),
                        $customer->emails->pluck('email')->implode('; '),
                        $customer->primaryProperty?->fullAddress() ?? '',
                        $last ? CarbonImmutable::parse($last, 'UTC')->setTimezone($company->timezone)->toDateString() : '',
                        $customer->appliances->map(fn ($a) => trim($a->label().' '.($a->model_number ?? '')))->implode('; '),
                        collect($balances[$customer->id] ?? [])->map(fn ($b) => Money::format((int) $b->total, $b->currency, $company->locale))->implode(' + '),
                    ];
                }
            });

        return self::csv($rows);
    }

    public function jobs(Company $company, CarbonImmutable $now): string
    {
        $tz = $company->timezone;
        $from = $now->setTimezone($tz)->startOfDay();
        $rows = [['Date', 'Window', 'Customer', 'Phone', 'Address', 'Problem', 'Technician']];

        JobVisit::query()->whereIn('status', VisitStatus::openValues())
            ->whereBetween('scheduled_start', [$from->utc(), $from->addDays(7)->utc()])
            ->whereHas('job')->with(['job.customer.primaryPhone', 'job.property', 'assignees'])
            ->orderBy('scheduled_start')->get()
            ->each(function (JobVisit $visit) use (&$rows, $company, $tz) {
                $job = $visit->job;
                $rows[] = [
                    CarbonImmutable::instance($visit->scheduled_start)->setTimezone($tz)->toDateString(),
                    LocalTime::format($visit->scheduled_start, $tz, $company->locale).'–'.LocalTime::format($visit->scheduled_end, $tz, $company->locale),
                    (string) $job->customer?->display_name,
                    $job->customer?->primaryPhone ? PhoneNumber::display($job->customer->primaryPhone->number, $company->country) : '',
                    $job->property?->fullAddress() ?? '',
                    (string) $job->description,
                    $visit->assignees->pluck('name')->implode(', '),
                ];
            });

        return self::csv($rows);
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\u{FEFF}");
        foreach ($rows as $row) {
            // A cell starting with = + - @ would run as a formula in a spreadsheet.
            fputcsv($handle, array_map(fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) === 1 ? "'".$cell : (string) $cell, $row), escape: '');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function alert(Company $company, string $error): void
    {
        $owners = User::query()->where('is_active', true)
            ->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id)->where('is_active', true)->where('role', UserRole::Owner->value))
            ->get();

        foreach ($owners as $owner) {
            try {
                $owner->notify(new AppAlert('drive_backup_failed', __('automation.backup.failed_title'),
                    __('automation.backup.failed_body', ['error' => mb_substr($error, 0, 200)]), route('company.morning.edit'), mail: false));
            } catch (Throwable $e) {
                Log::warning('Backup alert failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
