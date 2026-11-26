<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\RefundOriginalJob;
use App\Actions\Jobs\ReopenJob;
use App\Actions\Jobs\SaveJob;
use App\Enums\ApplianceCategory;
use App\Enums\ApplianceType;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\LeadSource;
use App\Enums\PaymentMethod;
use App\Enums\PhotoKind;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Enums\VisitType;
use App\Enums\WarrantyUnit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Parts\PartsController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Requests\Jobs\JobRequest;
use App\Messaging\BookingConfirmation;
use App\Messaging\MessagingPresenter;
use App\Messaging\SmsThreads;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobBringItem;
use App\Models\JobCostItem;
use App\Models\JobNote;
use App\Models\JobPart;
use App\Models\JobPhoto;
use App\Models\JobReport;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\Supplier;
use App\Models\SupplierReceipt;
use App\Models\Task;
use App\Models\User;
use App\Support\Accounting\JobExpenses;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\JobProfit;
use App\Support\Jobs\BookingPhotos;
use App\Support\Jobs\JobBacklog;
use App\Support\Jobs\JobPresenter;
use App\Support\Jobs\PossibleWarranty;
use App\Support\Jobs\UnscheduledJobs;
use App\Support\Locale\LocalTime;
use App\Support\PhoneNumber;
use App\Support\Routing\DayOrder;
use App\Support\Ui\SavedToast;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use IntlDateFormatter;

class JobController extends Controller
{
    private const CLOSED = ['completed', 'invoiced', 'paid', 'cancelled'];

    /**
     * Jobs (UX-REDESIGN §7.4): one screen with Open (the unfinished queue, by urgency), All (by visit date, newest
     * first) and Deleted (restorable for 30 days). People without the office list see only Open.
     */
    /** Columns the desktop table of the All tab sorts by (UX-REDESIGN §13). */
    private const TABLE_SORTS = ['visit', 'number', 'status', 'customer', 'total'];

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $canAll = Gate::allows('viewAny', ServiceJob::class);
        // Links with a filter of the full list (status, technician, dates…) open All jobs; otherwise the open jobs.
        $allFilters = ['status', 'brand', 'technician', 'type', 'visit_type', 'outcome', 'strict', 'from', 'to'];
        $tab = in_array($request->query('tab'), ['open', 'all', 'deleted'], true)
            ? $request->query('tab')
            : ($request->hasAny($allFilters) ? 'all' : 'open');

        if (! $canAll) {
            Gate::authorize('viewMine', ServiceJob::class);

            // Technicians work from My Jobs; the unfinished queue is the only list they open.
            if ($tab !== 'open' || ! $request->has('tab')) {
                return to_route('jobs.mine');
            }
        }

        if ($tab === 'deleted') {
            Gate::authorize('viewTrash', ServiceJob::class);
        }

        $sort = in_array($request->query('sort'), ['urgency', 'newest', ...self::TABLE_SORTS], true) ? $request->query('sort') : '';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $shared = [
            'tab' => $tab,
            'sort' => $sort,
            'dir' => $dir,
            'canViewAll' => $canAll,
            'canViewTrash' => Gate::allows('viewTrash', ServiceJob::class),
            'canCreate' => Gate::allows('create', ServiceJob::class),
            'deletedCount' => Gate::allows('viewTrash', ServiceJob::class)
                ? ServiceJob::onlyTrashed()->visibleTo($user)->where('deleted_at', '>=', now()->subDays(ServiceJob::RESTORE_DAYS))->count()
                : 0,
        ];

        if ($tab === 'open') {
            $filters = [
                'search' => trim((string) $request->query('search', '')),
                'reason' => in_array($request->query('reason'), JobBacklog::FILTERS, true) ? $request->query('reason') : '',
                'sort' => $sort === 'newest' ? 'newest' : 'urgency',
            ];

            return Inertia::render('jobs/index', [
                ...$shared,
                // A search finds any job (owner's task of November 25): every status, with a time or not, deleted too.
                // The reason chips do not narrow a search (owner's task of November 26).
                'jobs' => $filters['search'] !== ''
                    ? self::searchJobs($user, $filters['search'])
                    : JobBacklogController::jobs($user, $filters),
                'filters' => $filters,
            ]);
        }

        if ($tab === 'deleted') {
            return Inertia::render('jobs/index', [
                ...$shared,
                'deleted' => $this->deletedJobs($user),
                'days' => ServiceJob::RESTORE_DAYS,
                'filters' => ['search' => ''],
            ]);
        }

        $timezone = currentCompany()->timezone;
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'brand' => (string) $request->query('brand', ''),
            'technician' => (string) $request->query('technician', ''),
            'type' => (string) $request->query('type', ''),
            'visit_type' => (string) $request->query('visit_type', ''),
            'outcome' => (string) $request->query('outcome', ''),
            'strict' => $request->boolean('strict') ? '1' : '',
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        // A search on All finds every job too: open first, then closed, newest first; the filters do not narrow it
        // (owner's task of November 26).
        if ($filters['search'] !== '') {
            return Inertia::render('jobs/index', [
                ...$shared,
                'jobs' => self::searchJobs($user, $filters['search']),
                'statusCounts' => (object) [],
                'filters' => $filters,
                'statuses' => JobStatus::options(),
                'types' => currentCompany()->vertical->jobTypeOptions(),
                'visitTypes' => VisitType::options(),
                'outcomes' => JobOutcome::options(),
                'brands' => $this->brandOptions($user, activeOnly: false),
                'technicians' => $this->assignableUsers(),
            ]);
        }

        $status = JobStatus::tryFrom($filters['status']);
        $type = JobType::tryFrom($filters['type']);
        $visitType = VisitType::tryFrom($filters['visit_type']);
        $outcome = JobOutcome::tryFrom($filters['outcome']);
        $lastVisit = '(select max(v.scheduled_start) from job_visits v where v.service_job_id = service_jobs.id)';

        $jobs = ServiceJob::query()
            ->visibleTo($user)
            // A search also finds deleted jobs (marked Deleted) for people who see the trash.
            ->when($filters['search'] !== '' && Gate::allows('viewTrash', ServiceJob::class), fn (Builder $q) => $q->withTrashed())
            ->search($filters['search'])
            ->when($filters['status'] === 'open', fn ($q) => $q->whereNotIn('status', self::CLOSED))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('job_type', $type))
            ->when($visitType, fn ($q) => $q->where('visit_type', $visitType))
            ->when($outcome, fn ($q) => $q->where('outcome', $outcome))
            ->when($filters['outcome'] === 'none', fn ($q) => $q->whereNull('outcome'))
            ->when($filters['strict'] === '1', fn ($q) => $q->whereHas('visits', fn (Builder $v) => $v->where('strict_arrival', true)))
            ->when($filters['brand'] !== '', fn ($q) => $q->where('brand_id', (int) $filters['brand']))
            ->when($filters['technician'] !== '', fn ($q) => $q->whereHas(
                'visits.assignees',
                fn (Builder $a) => $a->where('users.id', (int) $filters['technician']),
            ))
            ->when($filters['from'] || $filters['to'], fn ($q) => $q->whereHas('visits', function (Builder $v) use ($filters, $timezone) {
                if ($filters['from']) {
                    $v->where('scheduled_start', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc());
                }
                if ($filters['to']) {
                    $v->where('scheduled_start', '<=', CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc());
                }
            }))
            ->with(['customer.primaryPhone', 'property', 'brand', 'appliances', 'visits.assignees'])
            ->when(Gate::allows('viewAny', Invoice::class), fn ($q) => $q->with('invoices'))
            // The invoiced total is a sort key only for people who see invoices.
            ->tap(fn (Builder $q) => $this->sortTable(
                $q,
                $sort === 'total' && ! Gate::allows('viewAny', Invoice::class) ? 'visit' : ($sort ?: 'visit'),
                $dir,
                $lastVisit,
            ))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceJob $job) => JobPresenter::row($job));

        // Status chips: visible jobs per status (other filters ignored), tap to filter.
        $statusCounts = ServiceJob::query()
            ->visibleTo($user)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        return Inertia::render('jobs/index', [
            ...$shared,
            'jobs' => $jobs,
            'statusCounts' => $statusCounts,
            'filters' => $filters,
            'statuses' => JobStatus::options(),
            'types' => currentCompany()->vertical->jobTypeOptions(),
            'visitTypes' => VisitType::options(),
            'outcomes' => JobOutcome::options(),
            'brands' => $this->brandOptions($user, activeOnly: false),
            'technicians' => $this->assignableUsers(),
        ]);
    }

    /**
     * @return array{amount: int, currency: string}|null
     */
    private static function refunded(ServiceJob $job): ?array
    {
        $row = Payment::query()->valid()->whereNotNull('refunded_payment_id')
            ->whereIn('invoice_id', $job->invoices()->select('id'))
            ->selectRaw('currency, -sum(amount) as amount')->groupBy('currency')->orderByDesc('amount')->first();

        return $row === null || (int) $row->amount <= 0 ? null : ['amount' => (int) $row->amount, 'currency' => (string) $row->currency];
    }

    /**
     * Any job the search matches (owner's tasks of November 25 and 26): every status, open and closed, with or
     * without a date or a time, and deleted ones (marked Deleted, at the bottom) for people who see the trash. Open
     * jobs first, then closed ones; newest (by the last visit, else the booking) first in each.
     */
    public static function searchJobs(User $user, string $search): LengthAwarePaginator
    {
        $closed = "'".implode("','", [JobStatus::Completed->value, JobStatus::Invoiced->value, JobStatus::AwaitingPayment->value, JobStatus::Paid->value, JobStatus::Cancelled->value])."'";

        return ServiceJob::query()
            ->visibleTo($user)
            ->when(Gate::forUser($user)->allows('viewTrash', ServiceJob::class), fn (Builder $q) => $q->withTrashed())
            ->search($search)
            ->with(['customer.primaryPhone', 'property', 'brand', 'appliances', 'visits.assignees'])
            ->when(Gate::forUser($user)->allows('viewAny', Invoice::class), fn ($q) => $q->with('invoices'))
            ->orderByRaw('service_jobs.deleted_at is not null')
            ->orderByRaw("case when service_jobs.status in ({$closed}) then 1 else 0 end")
            ->orderByRaw('coalesce((select max(v.scheduled_start) from job_visits v where v.service_job_id = service_jobs.id), service_jobs.planned_date, service_jobs.created_at) desc')
            ->orderByDesc('service_jobs.id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceJob $job) => [...JobPresenter::row($job), ...JobPresenter::searchFields($job)]);
    }

    /**
     * "My jobs": the visits assigned to the current user (technicians, and Owners/Admins who go on calls).
     */
    public function mine(Request $request): Response
    {
        Gate::authorize('viewMine', ServiceJob::class);

        $user = $request->user();
        // Two tabs: one day at a time (arrows move the day; today by default) and the work completed lately.
        $tab = $request->query('tab') === 'completed' ? 'completed' : 'day';
        $timezone = currentCompany()->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $date = $request->filled('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date'))
            ? CarbonImmutable::parse((string) $request->query('date'), $timezone)->startOfDay()
            : $today;
        $dayStart = $date->utc();
        $dayEnd = $date->endOfDay()->utc();
        $search = trim((string) $request->query('search', ''));
        $active = [VisitStatus::OnTheWay->value, VisitStatus::InProgress->value];
        $finished = [JobStatus::Completed->value, JobStatus::Invoiced->value, JobStatus::AwaitingPayment->value, JobStatus::Paid->value];

        // The office (owner, admin, dispatcher) also sees the day's jobs nobody is assigned to yet.
        $office = Gate::allows('dispatch', ServiceJob::class);

        // One query per tab; a search applies to every tab.
        // Today also keeps a visit started on an earlier day that is still open.
        $tabQuery = fn (string $name) => JobVisit::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('assignees', fn (Builder $a) => $a->where('users.id', $user->id))
                ->when($office && $name === 'day', fn (Builder $o) => $o->orWhereDoesntHave('assignees')))
            ->whereHas('job', fn (Builder $q) => $q->visibleTo($user)->when($search !== '', fn (Builder $j) => $j->search($search)))
            ->when($name === 'day', fn ($q) => $q
                // Closed jobs are off the day (owner's task of November 25): they show under Completed only;
                // awaiting payment also sits in the "Awaiting payment" list. A visit still under way stays (Finish).
                ->where(fn (Builder $w) => $w
                    ->whereHas('job', fn (Builder $j) => $j->whereNotIn('status', $finished)->whereNull('closed_at'))
                    ->orWhereIn('status', $active))
                ->where(fn ($w) => $w
                    ->whereBetween('scheduled_start', [$dayStart, $dayEnd])
                    ->when($date->equalTo($today), fn ($o) => $o->orWhereIn('status', $active)))
                ->where('status', '!=', VisitStatus::Cancelled->value)
                ->orderBy('scheduled_start'))
            ->when($name === 'completed', fn ($q) => $q
                ->whereHas('job', fn (Builder $j) => $j->whereIn('status', $finished))
                ->whereBetween('scheduled_start', [$today->subDays(30)->utc(), $today->endOfDay()->utc()])
                ->whereNotIn('status', [...$active, VisitStatus::Cancelled->value])
                ->orderByDesc('scheduled_start'));

        $visits = $tabQuery($tab)
            ->with(['assignees', 'job.customer.primaryPhone', 'job.property', 'job.appliances', 'job.bringItems'])
            ->limit(100)
            ->get();
        // The day in the order saved on the Map tab ("Apply").
        if ($tab === 'day') {
            $visits = DayOrder::sort($visits);
        }

        $messaging = app(MessagingPresenter::class);
        // New-message badge: unread texts from the customer of an unfinished job.
        $unread = SmsThreads::unreadByCustomer($user, $visits->pluck('job.customer_id')->filter()->unique()->values()->all());

        // My Jobs lists this person's visits (and unassigned ones for the office); the office is told how many
        // other people have that day, so an empty day here is not mistaken for an empty schedule.
        $othersOnDay = $tab === 'day' && $search === '' && $office
            ? JobVisit::query()
                ->whereBetween('scheduled_start', [$dayStart, $dayEnd])
                ->where('status', '!=', VisitStatus::Cancelled->value)
                ->whereHas('assignees')
                ->whereDoesntHave('assignees', fn (Builder $q) => $q->where('users.id', $user->id))
                ->whereHas('job', fn (Builder $q) => $q->visibleTo($user)->whereNotIn('status', $finished)->whereNull('closed_at'))
                ->count()
            : 0;

        return Inertia::render('jobs/mine', [
            'tab' => $tab,
            'canTasks' => Gate::allows('viewAny', Task::class),
            'othersOnDay' => $othersOnDay,
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'search' => $search,
            // The person's own to-dos of the day, in one feed with the jobs (All / Jobs / To Do on the screen).
            'tasks' => $search === '' ? TaskController::between($user, $date, $date->addDay(), onlyOwn: true) : [],
            // Jobs of the day without a time first (marked No time), and on today the jobs with no day yet (owner's
            // task of November 25). The office sees every one; a technician the ones given to them.
            'noTime' => $tab === 'day' && $search === ''
                ? UnscheduledJobs::noTime($user, $date, $date->addDay(), onlyOwn: ! $office)->map(fn (ServiceJob $job) => UnscheduledJobs::card($job))->values()
                : [],
            'noDate' => $tab === 'day' && $search === '' && $date->equalTo($today)
                ? UnscheduledJobs::noDate($user, onlyOwn: ! $office)->map(fn (ServiceJob $job) => UnscheduledJobs::card($job))->values()
                : [],
            'visits' => $visits->map(function (JobVisit $visit) use ($user, $timezone, $messaging, $unread) {
                $job = $visit->job;

                return [
                    ...JobPresenter::visit($visit, $user, $timezone),
                    // The card's main button: On my way / Start / Finish visit, while field work is allowed.
                    'can_work' => $job->status->allowsVisitWork() && Gate::allows('work', $job),
                    // Finish visit of a started visit: also once the job is invoiced or paid.
                    'can_finish' => $job->status->allowsFinishingVisit() && Gate::allows('work', $job),
                    // "Edit by voice": everyone who may edit the job (working alone too).
                    'can_edit' => Gate::allows('update', $job),
                    'on_my_way_sms' => $visit->status === VisitStatus::Scheduled ? $messaging->onMyWayOnPhone($job, $user, $visit) : null,
                    'job' => [
                        'id' => $job->id,
                        'number' => $job->number,
                        'status' => $job->status->value,
                        'status_label' => $job->status->label(),
                        'job_type_label' => $job->job_type->label(),
                        'visit_type' => $job->visit_type->value,
                        'visit_type_label' => $job->visit_type->label(),
                        'bring' => $job->bringItems->isEmpty() ? null : [
                            'done' => $job->bringItems->where('is_checked', true)->count(),
                            'total' => $job->bringItems->count(),
                        ],
                        'customer' => $job->customer?->display_name,
                        'customer_icon' => $job->customer?->avatarIcon() ?? 'neutral',
                        'phone' => $job->customer?->primaryPhone?->number,
                        'address' => $job->property?->fullAddress(),
                        'appliances' => $job->appliances->map(fn (Appliance $a) => $a->label())->values(),
                        // Picture on the card: the appliance, several appliances, or tools for an installation.
                        'picture' => JobPresenter::picture($job),
                        'appliance_types' => $job->appliances->map(fn (Appliance $a) => $a->type->label())->values(),
                        'problem' => $job->description,
                        'new_messages' => JobPresenter::isOpen($job) ? ($unread[$job->customer_id] ?? 0) : 0,
                    ],
                ];
            })->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        Gate::authorize('create', ServiceJob::class);

        $customer = $request->integer('customer_id')
            ? Customer::query()->find($request->integer('customer_id'))
            : null;

        $quick = $request->boolean('book') || $request->boolean('invoice');

        return Inertia::render($quick ? 'jobs/quick-book' : 'jobs/form', [
            'job' => null,
            'booking' => $request->boolean('book'),
            'openInvoice' => $request->boolean('invoice'),
            'bookingDate' => $request->input('date'),
            // A tap on an empty calendar slot: its time and person (UX-REDESIGN §9.1).
            'bookingStart' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $request->query('start')) === 1 ? $request->query('start') : null,
            // Otherwise "Me" (the person booking, when they go on calls); My menu = Office starts on Unassigned.
            'bookingTechnician' => $request->integer('technician') ?: $this->selfBooking($request->user()),
            'customer' => $customer ? self::customerOption($customer) : null,
            'today' => CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d'),
            // Company clock, so the booking form never suggests a window that has already passed.
            'nowTime' => CarbonImmutable::now(currentCompany()->timezone)->format('H:i'),
            'arrivalWindows' => currentCompany()->arrivalWindows(),
            // Days the company is closed: Quick booking offers free windows on open days only.
            'closedDays' => collect(currentCompany()->business_hours ?? Company::defaultBusinessHours())
                ->filter(fn ($day) => is_array($day) && ($day['closed'] ?? false))
                ->keys()->values()->all(),
            ...($quick ? $this->bookingTechnicians() : []),
            // A company of one person: no technician choice, the job goes to them (SaveJob).
            'soloCompany' => Membership::query()->where('is_active', true)->count() === 1,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(JobRequest $request, SaveJob $save, BookingConfirmation $confirmation): RedirectResponse
    {
        $booking = $request->boolean('quick_booking') && ! $request->boolean('open_invoice');
        $draft = $booking && $request->boolean('draft');

        $job = $save->create(
            $request->customer(),
            $request->jobAttributes(),
            $request->applianceIds(),
            $request->newAppliances(),
            $request->newCustomer(),
            $request->visit(),
            $request->user(),
            $request->bringItems(),
            $request->newProperty(),
            $request->occupant(),
            $request->billing(),
            $draft,
        );

        // The photos the booking was read from (a screenshot of the customer's message, a rating plate).
        BookingPhotos::attach($job, $request->bookingPhotos(), $request->user());

        $visit = $booking ? $job->visits()->first() : null;

        // Booking (UX-REDESIGN §7.1): back to the day's schedule with "Booked #1005 · Tomorrow 9–11". Finish booking
        // sends the confirmation by itself (the company's SMS setting); Save as draft sends nothing.
        if ($booking) {
            // A booking without a time or a day gets its confirmation too ("exact time will be confirmed shortly").
            $sent = $draft ? null : $confirmation->send($job, $request->user());
            self::flashBooked($job, $visit, $sent);
            $date = $visit !== null
                ? CarbonImmutable::parse($visit->scheduled_start)->setTimezone(currentCompany()->timezone)->toDateString()
                : ($job->planned_date?->toDateString() ?? CarbonImmutable::now(currentCompany()->timezone)->toDateString());

            return Gate::allows('dispatch', ServiceJob::class)
                ? to_route('calendar', ['view' => 'day', 'date' => $date])
                : to_route('jobs.mine', ['date' => $date]);
        }

        SavedToast::flash(route('jobs.show', $job), __('jobs.created', ['number' => $job->number]));

        return $request->boolean('open_invoice')
            ? to_route('invoices.create', $job)
            : to_route('jobs.show', $job);
    }

    /**
     * The "Booked #1005 · Tomorrow 9–11" sheet after a booking is saved or finished, with how the confirmation went
     * (null for a draft).
     *
     * @param  array<string, mixed>|null  $sent
     */
    public static function flashBooked(ServiceJob $job, ?JobVisit $visit, ?array $sent): void
    {
        Inertia::flash('booking_confirmation', [
            'job_id' => $job->id,
            'number' => $job->number,
            'message' => __($sent === null ? 'jobs.quick.drafted' : 'jobs.quick.booked', ['number' => $job->number, 'when' => self::bookedWhen($visit, $job)]),
            'url' => route('jobs.show', $job, false),
            'draft' => $sent === null,
            'sent' => $sent,
        ]);
    }

    /**
     * "Today 9–11 a.m.", "Tomorrow 1–3 p.m." or "Fri, Oct 16 9–11 a.m." in the company's time zone and format.
     */
    public static function bookedWhen(?JobVisit $visit, ?ServiceJob $job = null): string
    {
        $company = currentCompany();
        if ($visit === null) {
            return $job?->planned_date !== null
                ? LocalTime::format($job->planned_date, 'UTC', $company->locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'EEE, MMM d').' · '.__('calendar.no_time')
                : (string) __('calendar.no_date');
        }
        $start = CarbonImmutable::parse($visit->scheduled_start)->setTimezone($company->timezone);
        $end = CarbonImmutable::parse($visit->scheduled_end)->setTimezone($company->timezone);
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $day = match ((int) $today->diffInDays($start->startOfDay())) {
            0 => __('jobs.quick.today'),
            1 => __('jobs.quick.tomorrow'),
            default => LocalTime::format($start, $company->timezone, $company->locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'EEE, MMM d'),
        };

        return $day.' '.LocalTime::format($start, $company->timezone, $company->locale).'–'.LocalTime::format($end, $company->timezone, $company->locale);
    }

    public function show(Request $request, ServiceJob $job): Response|RedirectResponse
    {
        Gate::authorize('view', $job);

        $user = $request->user();
        $timezone = currentCompany()->timezone;
        $job->load([
            'brand', 'customer.phones', 'property', 'appliances', 'visits.assignees', 'statusChanges.user',
            'photos.user', 'signer', 'estimates', 'invoices',
            'bringItems.checker', 'previousJob', 'followUps', 'closer', 'teamNotes.user', 'parts.supplier',
        ]);
        $canUpdate = Gate::allows('update', $job);
        $property = $job->property;
        $myVisit = JobPresenter::myNextVisit($job, $user);

        // Old "finish" links open the Finish visit screen.
        if ($request->boolean('finish') && $myVisit?->status === VisitStatus::InProgress) {
            return to_route('visits.finish-screen', $myVisit);
        }

        // A technician has no SMS chat: the customer's texts shown on the job page count as read (the new-message
        // badge on the job card goes away). The office reads them in the chat.
        if (! Gate::allows('viewAny', Message::class)) {
            SmsThreads::markCustomerRead($user, $job->customer_id);
        }

        return Inertia::render('jobs/show', [
            'suppliers' => Supplier::options(),
            'job' => [
                'id' => $job->id,
                'number' => $job->number,
                'status' => $job->status->value,
                'status_label' => $job->status->label(),
                'job_type_label' => $job->job_type->label(),
                'visit_type' => $job->visit_type->value,
                'visit_type_label' => $job->visit_type->label(),
                'previous_job' => $job->previousJob ? ['id' => $job->previousJob->id, 'number' => $job->previousJob->number] : null,
                'follow_ups' => $job->followUps->map(fn (ServiceJob $f) => [
                    'id' => $f->id,
                    'number' => $f->number,
                    'visit_type_label' => $f->visit_type->label(),
                    'status_label' => $f->status->label(),
                ])->values(),
                'outcome' => $job->outcome?->value,
                // "Handed to: {name}" (owner's task of November 19).
                'handed_to' => $job->handed_to,
                'outcome_label' => $job->outcome?->label(),
                'outcome_reason' => $job->outcome_reason,
                'outcome_note' => $job->outcome_note,
                'closed_at' => JobPresenter::iso($job->closed_at),
                // "Refunded $XX.XX" on the job (owner's task of November 26): refunds given on its invoices.
                'refunded' => Gate::allows('viewAny', Invoice::class) ? self::refunded($job) : null,
                // The job's own reports, under Files (owner's task of November 25).
                'reports' => JobReport::query()->where('service_job_id', $job->id)->orderByDesc('id')->get()
                    ->map(fn (JobReport $report) => [
                        'id' => $report->id,
                        'title' => trim(__('service_reports.title').' · '.(DocumentPrint::date($report->report_date) ?? DocumentPrint::date($report->created_at))),
                        'url' => route('jobs.reports.edit', $report),
                        'sent_at' => JobPresenter::iso($report->sent_at),
                        'sent_to' => $report->sent_to,
                    ])->values(),
                // "Confirmation sent: SMS ✓, Email ✓, …" or why it did not go (owner's task of November 25).
                'confirmation' => $job->confirmation_sent_at === null ? null : [
                    'at' => JobPresenter::iso($job->confirmation_sent_at),
                    'sms' => $job->confirmation_sms_status,
                    'email' => $job->confirmation_email_status,
                    'error' => $job->confirmation_error,
                ],
                'closed_by' => $job->closer?->name,
                'bring_items' => $job->bringItems->map(fn (JobBringItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                    'is_checked' => $item->is_checked,
                    'checked_by' => $item->checker?->name,
                ])->values(),
                'lead_source_label' => $job->lead_source?->label(),
                'brand' => $job->brand?->name,
                // Statistics fields (owner's task of November 24).
                'stats' => [
                    'appliance_category' => $job->appliance_category?->value,
                    'appliance_brand' => $job->appliance_brand,
                    'only_diagnostic' => (bool) $job->only_diagnostic,
                    'can_edit' => Gate::any(['update', 'work'], $job),
                ],
                'description' => $job->description,
                'notes' => $job->notes,
                'tech_notes' => $job->tech_notes,
                // The part the job waits for and the AI's possible number ("check").
                // The parts the job needs (Parts page), each with its status; the AI's number is marked "check".
                'parts' => $job->parts->map(fn (JobPart $part) => PartsController::present($part->setRelation('job', $job), CarbonImmutable::now($timezone)->startOfDay()))->values(),
                'created_at' => JobPresenter::iso($job->created_at),
                'completed_at' => JobPresenter::iso($job->completed_at),
                'allows_visit_work' => $job->status->allowsVisitWork(),
                'allows_visit_finish' => $job->status->allowsFinishingVisit(),
                'customer' => [
                    'id' => $job->customer->id,
                    'display_name' => $job->customer->display_name,
                    // The edit sheet's name fields.
                    'type' => $job->customer->type->value,
                    'first_name' => $job->customer->first_name,
                    'last_name' => $job->customer->last_name,
                    'company_name' => $job->customer->company_name,
                    'avatar_icon' => $job->customer->avatarIcon(),
                    'avatar_style' => $job->customer->avatar_style,
                    'notes' => $job->customer->notes,
                    'email' => $job->customer->primaryEmail()->value('email'),
                    'phones' => $job->customer->phones->map(fn ($p) => [
                        'id' => $p->id,
                        'number' => $p->number,
                        'label_text' => $p->label->label(),
                        'contact_name' => $p->contact_name,
                    ])->values(),
                ],
                'property' => [
                    ...CustomerController::propertyData($property),
                    'full_address' => $property->fullAddress(),
                ],
                'appliances' => $job->appliances->map(fn (Appliance $a) => JobPresenter::appliance($a))->values(),
                'visits' => $job->visits->map(fn (JobVisit $v) => JobPresenter::visit($v, $user, $timezone))->values(),
                'team_notes' => $job->teamNotes->map(fn (JobNote $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'author' => $note->user?->name,
                    'at' => JobPresenter::iso($note->created_at),
                    'can_delete' => $note->user_id === $user->id || $canUpdate,
                ])->values(),
                'minutes_on_job' => $job->visits->sum(fn (JobVisit $v) => $v->minutesOnJob() ?? 0),
                'history' => $job->statusChanges->map(fn ($c) => JobPresenter::statusChange($c))->values(),
                'photos' => $job->photos->map(fn (JobPhoto $photo) => [
                    'id' => $photo->id,
                    'kind' => $photo->kind->value,
                    'url' => route('jobs.photos.show', [$job, $photo]),
                    'taken_at' => JobPresenter::iso($photo->taken_at),
                    'user' => $photo->user?->name,
                    'can_delete' => $canUpdate || ($photo->user_id === $user->id && Gate::allows('work', $job)),
                ])->values(),
                'estimates' => $job->estimates->map(fn (Estimate $e) => BillingPresenter::row($e))->values(),
                'invoices' => $job->invoices->map(fn (Invoice $i) => BillingPresenter::row($i))->values(),
                // Google review of this job: requested by hand, link clicked, the one reminder sent.
                'review' => self::reviewState($job),
                'signature' => JobPresenter::signature($job),
                // The job's person when it has no open visit (the visits carry their own).
                'assignees' => $job->assignment_is_explicit
                    ? $job->assignees()->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()
                    : [],
            ],
            // "Change address": the customer's other addresses.
            'otherAddresses' => $canUpdate ? Property::query()
                ->where('customer_id', $job->customer_id)
                ->whereKeyNot($job->property_id)
                ->where('line1', '!=', '')
                ->get()
                ->map(fn (Property $p) => ['id' => $p->id, 'full_address' => $p->fullAddress()])
                ->values() : [],
            'myVisitId' => $myVisit?->id,
            'messaging' => app(MessagingPresenter::class)->forJob($job, $user, $myVisit),
            // "Possible warranty": the customer had a job finished shortly before this one was booked.
            'possibleWarranty' => PossibleWarranty::for($job->customer_id, currentCompany(), $job->id, CarbonImmutable::instance($job->created_at)),
            'can' => [
                'update' => $canUpdate,
                'delete' => Gate::allows('delete', $job),
                'work' => Gate::allows('work', $job),
                'close' => Gate::allows('work', $job) && $job->status !== JobStatus::Cancelled && ! $job->trashed(),
                // "Reopen job" in the header of a closed job (owner's task of November 26).
                'reopen' => $canUpdate && ReopenJob::canReopen($job) && ! $job->trashed(),
                'viewCustomer' => Gate::allows('view', $job->customer),
                'updateCustomer' => Gate::allows('update', $job->customer),
                // One tap on "Technician" (office, team mode).
                'assign' => $canUpdate && Gate::allows('dispatch', ServiceJob::class) && ! currentCompany()->works_alone,
            ],
            // "Hand to technician": the app's people and the outside technicians.
            'handoffOptions' => $canUpdate && Gate::allows('dispatch', ServiceJob::class) ? JobHandoffController::options() : [],
            'statusOptions' => $canUpdate ? JobStatus::manualOptions() : [],
            'closureReasons' => [
                'customer_declined' => currentCompany()->closureReasons(JobOutcome::CustomerDeclined),
                'unable_to_repair' => currentCompany()->closureReasons(JobOutcome::UnableToRepair),
                'cancelled' => currentCompany()->closureReasons(JobOutcome::Cancelled),
                'no_charge' => currentCompany()->closureReasons(JobOutcome::NoCharge),
            ],
            // Warranty callback: what can be refunded on the original job.
            'callback' => $job->visit_type === VisitType::Callback && $job->previousJob ? [
                'number' => $job->previousJob->number,
                'refundable' => RefundOriginalJob::refundable($job->previousJob),
                'currency' => $job->previousJob->invoices()->value('currency') ?? currentCompany()->currency,
            ] : null,
            'warrantyLines' => $job->invoices->where('status', '!=', InvoiceStatus::Void)
                ->flatMap(fn (Invoice $invoice) => $invoice->items()->get()->filter(fn (InvoiceItem $item) => $item->hasWarranty())->map(fn (InvoiceItem $item) => [
                    'id' => $item->id,
                    'invoice' => $invoice->number,
                    'description' => $item->description,
                    'kind' => $item->kind->value,
                    'bill_to_customer' => $item->bill_to_customer,
                    'warranty_value' => $item->warranty_value ?? 0,
                    'warranty_unit' => $item->warranty_unit ?? 'days',
                    'warranty_ends_on' => $item->warranty_ends_on?->toDateString(),
                ]))->values(),
            'warrantyUnits' => WarrantyUnit::options(),
            // Costs, receipts and profit: only the Owner, once the job is closed (owner's update, October 24).
            'costs' => $user->hasRole(UserRole::Owner) && $job->closed_at !== null ? [
                'profit' => JobProfit::for($job),
                'currency' => currentCompany()->currency,
                'items' => JobCostItem::query()->where('service_job_id', $job->id)->orderBy('id')->get()
                    ->map(fn (JobCostItem $item) => [
                        'id' => $item->id,
                        'kind' => $item->kind->value,
                        'description' => $item->description,
                        'part_number' => $item->part_number,
                        'supplier' => $item->supplier,
                        'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                        'unit' => $item->unit,
                        'unit_cost' => $item->unit_cost,
                        'total_cost' => $item->totalCost(),
                    ])->values(),
                'receipts' => SupplierReceipt::query()
                    ->whereHas('jobs', fn ($q) => $q->where('service_jobs.id', $job->id))
                    ->with('jobs')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (SupplierReceipt $r) => [
                        'id' => $r->id,
                        'name' => $r->original_name,
                        'url' => route('receipts.show', $r),
                        'supplier' => $r->supplier,
                        'receipt_date' => $r->receipt_date?->toDateString(),
                        'amount' => $r->amount,
                        'jobs' => $r->jobs->pluck('number')->values(),
                        'can_delete' => $r->uploaded_by === $user->id && $r->created_at?->isAfter(now()->subHour()),
                    ])->values(),
                'supplier_taxes' => BillingPresenter::lineSetup()['supplier_taxes'],
                'units' => BillingPresenter::lineSetup()['units'],
            ] : null,
            // My expenses (owner's task of November 24): the Owner and the job's technicians (their own lines).
            'myExpenses' => JobExpenses::present($user, $job),
            'openWarranty' => $request->boolean('warranty'),
            // "Finish visit" on My Jobs opens the finish dialog straight away.
            'assignableUsers' => $canUpdate ? $this->assignableUsers() : [],
            'otherAppliances' => Appliance::query()
                ->where('property_id', $property->id)
                ->whereKeyNot($job->appliances->pluck('id'))
                ->get()
                ->map(fn (Appliance $a) => JobPresenter::appliance($a))
                ->values(),
            'applianceTypes' => ApplianceType::options(),
            'manufacturers' => CustomerController::manufacturers(),
            'today' => CarbonImmutable::now($timezone)->format('Y-m-d'),
            // The Reopen job window: the company's arrival windows and "Now".
            'nowTime' => CarbonImmutable::now($timezone)->format('H:i'),
            'arrivalWindows' => currentCompany()->arrivalWindows(),
            'photoKinds' => PhotoKind::takeable(),
            // "Mark as paid": invoices with money owed that this person may take a payment for.
            'payable' => $job->invoices
                ->filter(fn (Invoice $i) => ! $i->isVoid() && $i->balance > 0 && Gate::allows('recordPayment', $i))
                ->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'balance' => $i->balance, 'currency' => $i->currency])
                ->values(),
            'paymentMethods' => PaymentMethod::manualOptions(currentCompany()),
            'applianceCategories' => ApplianceCategory::options(),
        ]);
    }

    public function edit(Request $request, ServiceJob $job): Response
    {
        Gate::authorize('update', $job);

        $job->load(['customer', 'appliances']);

        return Inertia::render('jobs/form', [
            'job' => [
                'id' => $job->id,
                'number' => $job->number,
                'brand_id' => $job->brand_id,
                'property_id' => $job->property_id,
                'job_type' => $job->job_type->value,
                'lead_source' => $job->lead_source?->value,
                'description' => $job->description,
                'notes' => $job->notes,
                'appliance_ids' => $job->appliances->pluck('id')->values(),
                'visit_type' => $job->visit_type->value,
                'previous_job_id' => $job->previous_job_id,
                'bring_items' => $job->bringItems()->get()->map(fn (JobBringItem $item) => [
                    'description' => $item->description,
                    'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                ])->values(),
            ],
            'customer' => self::customerOption($job->customer),
            'today' => CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d'),
            ...$this->formOptions($request->user(), $job),
        ]);
    }

    public function update(JobRequest $request, ServiceJob $job, SaveJob $save): RedirectResponse
    {
        DB::transaction(function () use ($request, $job, $save) {
            $save->update($job, $request->jobAttributes(), $request->applianceIds(), $request->newAppliances(), $request->bringItems());

            if (($edit = $request->customerEdit()) !== null) {
                self::updateCustomerContact($job->customer, $edit);
            }

            // The address of the job's place, e.g. added later to a booking taken without one.
            if (($address = $request->addressEdit()) !== null) {
                $job->property()->first()?->fill($address)->save();
            }
        });

        SavedToast::flash(route('jobs.show', $job), __('jobs.updated'));

        return to_route('jobs.show', $job);
    }

    public function destroy(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('delete', $job);

        // Any job can be deleted; its documents, payments, costs, parts and trips go to the trash with it.
        DB::transaction(function () use ($job, $request) {
            $job->forceFill(['deleted_by' => $request->user()->id])->save();
            $job->delete();
        });

        // "Job deleted · Undo" (UX-REDESIGN §7.5) for people who may restore it; the trash keeps it for 30 days.
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('jobs.deleted_restorable', ['days' => ServiceJob::RESTORE_DAYS]),
            ...(Gate::allows('restore', $job) ? [
                'action' => ['label' => __('jobs.undo'), 'url' => route('jobs.restore', $job, false), 'method' => 'post'],
            ] : []),
        ]);

        return Gate::allows('viewAny', ServiceJob::class) ? to_route('jobs.index') : to_route('jobs.mine');
    }

    /**
     * Jobs deleted in the last 30 days, which the office can restore.
     */
    /**
     * Deleted jobs: the Deleted mode of Jobs (opened from "⋯"). The old address opens it.
     */
    public function trash(): RedirectResponse
    {
        Gate::authorize('viewTrash', ServiceJob::class);

        return to_route('jobs.index', ['tab' => 'deleted']);
    }

    /**
     * Jobs deleted in the last 30 days, newest first, with Restore.
     *
     * @return list<array<string, mixed>>
     */
    private function deletedJobs(User $user): array
    {
        return ServiceJob::onlyTrashed()
            ->visibleTo($user)
            ->where('deleted_at', '>=', now()->subDays(ServiceJob::RESTORE_DAYS))
            ->with(['customer', 'property', 'brand', 'deleter'])
            ->orderByDesc('deleted_at')
            ->limit(200)
            ->get()
            ->map(fn (ServiceJob $job) => [
                'id' => $job->id,
                'number' => $job->number,
                'customer' => $job->customer?->display_name,
                'address' => $job->property?->fullAddress(),
                'brand' => $job->brand?->name,
                'deleted_at' => JobPresenter::iso($job->deleted_at),
                'deleted_by' => $job->deleter?->name,
                'restorable_until' => JobPresenter::iso($job->deleted_at?->copy()->addDays(ServiceJob::RESTORE_DAYS)),
            ])->values()->all();
    }

    public function restore(ServiceJob $job): RedirectResponse
    {
        Gate::authorize('restore', $job);

        DB::transaction(function () use ($job) {
            $job->restore();
            $job->forceFill(['deleted_by' => null])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.restored', ['number' => $job->number])]);

        return to_route('jobs.show', $job);
    }

    /**
     * Customer lookup for the new job form: name, phone in any format, email, address, model or serial.
     */
    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('create', ServiceJob::class);

        $search = trim((string) $request->query('search', ''));
        // Booking types the phone first: from 3 digits, only phone numbers are matched (not house numbers).
        $digits = PhoneNumber::searchDigits($search);
        $byPhone = $request->query('by') === 'phone';

        $customers = ($search === '' || ($byPhone && strlen($digits) < 3)) ? collect() : Customer::query()
            ->when(
                $byPhone,
                fn (Builder $q) => $q->whereHas('phones', fn (Builder $p) => $p->where('number_normalized', 'like', "%{$digits}%")),
                fn (Builder $q) => $q->search($search),
            )
            ->with(['primaryPhone', 'primaryEmail', 'properties.appliances'])
            ->orderBy('display_name')
            ->limit(8)
            ->get();
        $jobCounts = ServiceJob::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->selectRaw('customer_id, count(*) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => [
                ...self::customerOption($c),
                'jobs_count' => (int) ($jobCounts[$c->id] ?? 0),
            ])->values(),
        ]);
    }

    /**
     * New name and main phone of a customer, typed on the job form (a typo at booking, a new number).
     *
     * @param  array{first_name: ?string, last_name: ?string, company_name: ?string, phone: string}  $edit
     */
    private static function updateCustomerContact(Customer $customer, array $edit): void
    {
        $customer->fill([
            'first_name' => $edit['first_name'],
            'last_name' => $edit['last_name'],
            'company_name' => $edit['company_name'],
        ])->save();

        // An empty phone box leaves the saved number as it is.
        if (blank($edit['phone'] ?? null)) {
            return;
        }

        $phone = $customer->primaryPhone()->first() ?? new CustomerPhone(['label' => 'mobile', 'is_primary' => true]);

        if ($phone->exists && $phone->number === PhoneNumber::normalize($edit['phone'])) {
            return;
        }

        $phone->number = $edit['phone'];
        $phone->customer_id = $customer->id;
        $phone->save();
    }

    /**
     * A customer with properties and their appliances, as the job form needs it.
     *
     * @return array<string, mixed>
     */
    public static function customerOption(Customer $customer): array
    {
        $customer->loadMissing(['primaryPhone', 'primaryEmail', 'properties.appliances']);

        return [
            'id' => $customer->id,
            'display_name' => $customer->display_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'company_name' => $customer->company_name,
            'avatar_icon' => $customer->avatarIcon(),
            'notes' => $customer->notes,
            'phone' => $customer->primaryPhone?->number,
            'email' => $customer->primaryEmail?->email,
            'billing' => $customer->billingAddressParts(),
            'lead_source' => $customer->lead_source?->value,
            'properties' => $customer->properties->map(fn (Property $p) => [
                'id' => $p->id,
                // The occupant saved at this address, filled in by itself when it is booked again.
                'occupant' => [
                    'name' => $p->site_contact_name,
                    'phone' => $p->site_contact_phone,
                    'access_notes' => $p->access_notes,
                ],
                'label' => $p->label,
                'full_address' => $p->fullAddress(),
                'is_primary' => $p->is_primary,
                // Address parts, editable from the job form.
                'address' => [
                    'line1' => $p->line1 ?? '',
                    'unit' => $p->unit ?? '',
                    'city' => $p->city ?? '',
                    'region' => $p->region ?? '',
                    'postal_code' => $p->postal_code ?? '',
                    'country' => $p->country ?? currentCompany()->country,
                    'google_place_id' => $p->google_place_id ?? '',
                    'latitude' => $p->latitude === null ? '' : (string) $p->latitude,
                    'longitude' => $p->longitude === null ? '' : (string) $p->longitude,
                ],
                'appliances' => $p->appliances->map(fn (Appliance $a) => JobPresenter::appliance($a))->values(),
            ])->values(),
            // Earlier jobs, for a return visit or warranty callback.
            'jobs' => ServiceJob::query()
                ->visibleTo(auth()->user())
                ->where('customer_id', $customer->id)
                ->with('appliances')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (ServiceJob $job) => [
                    'id' => $job->id,
                    'number' => $job->number,
                    'property_id' => $job->property_id,
                    'status_label' => $job->status->label(),
                    'created_at' => JobPresenter::iso($job->created_at),
                    'appliance_ids' => $job->appliances->pluck('id')->values(),
                    'appliances' => $job->appliances->map(fn (Appliance $a) => $a->label())->implode(', '),
                ])->values(),
        ];
    }

    /**
     * Members who can be assigned to visits.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    /**
     * Booking (UX-REDESIGN §7.1): the people who go on calls, and when each is busy over the next week, so the form
     * can say "free" or "busy 9–11" for the chosen day and window, and "Now" can end at the next job.
     *
     * @return array{bookingTechnicians: list<array{id: int, name: string}>, busy: array<int, list<array{date: string, start: string, end: string}>>}
     */
    private function bookingTechnicians(): array
    {
        $timezone = currentCompany()->timezone;
        $members = Membership::query()->inCalendar()->with('user')->get()
            ->filter(fn (Membership $m) => $m->user !== null)
            ->sortBy(fn (Membership $m) => mb_strtolower($m->user->name))
            ->values();
        $ids = $members->pluck('user_id')->all();
        $from = CarbonImmutable::now($timezone)->startOfDay();

        $busy = [];
        JobVisit::query()
            ->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::OnTheWay->value, VisitStatus::InProgress->value])
            ->whereBetween('scheduled_start', [$from->utc(), $from->addDays(8)->utc()])
            ->whereHas('assignees', fn (Builder $q) => $q->whereIn('users.id', $ids))
            ->with('assignees')
            ->orderBy('scheduled_start')
            ->get()
            ->each(function (JobVisit $visit) use (&$busy, $ids, $timezone) {
                $start = CarbonImmutable::parse($visit->scheduled_start)->setTimezone($timezone);
                $end = CarbonImmutable::parse($visit->scheduled_end)->setTimezone($timezone);

                foreach ($visit->assignees->whereIn('id', $ids) as $user) {
                    $busy[$user->id][] = ['date' => $start->toDateString(), 'start' => $start->format('H:i'), 'end' => $end->format('H:i')];
                }
            });

        return [
            'bookingTechnicians' => $members->map(fn (Membership $m) => ['id' => $m->user->id, 'name' => $m->user->name])->all(),
            'busy' => $busy,
        ];
    }

    private function selfBooking(User $user): ?int
    {
        return $user->menuMode() === 'field' && Membership::query()->assignable()->where('user_id', $user->id)->exists()
            ? $user->id
            : null;
    }

    private function assignableUsers(): array
    {
        return Membership::query()
            ->assignable()
            ->with('user')
            ->get()
            ->filter(fn (Membership $m) => $m->user !== null)
            ->sortBy(fn (Membership $m) => mb_strtolower($m->user->name))
            ->map(fn (Membership $m) => ['id' => $m->user->id, 'name' => $m->user->name, 'role' => $m->role->label()])
            ->values()
            ->all();
    }

    /**
     * Brands the user may create jobs for.
     *
     * @return list<array{value: string, label: string}>
     */
    private function brandOptions(User $user, bool $activeOnly = true, ?ServiceJob $job = null): array
    {
        $limited = $user->limitedBrandIds();

        return Brand::query()
            ->when($activeOnly, fn ($q) => $q->where(fn ($w) => $w
                ->where('is_active', true)
                ->when($job, fn ($j) => $j->orWhere('id', $job->brand_id))))
            ->when($limited !== [], fn ($q) => $q->whereIn('id', $limited))
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $b) => ['value' => (string) $b->id, 'label' => $b->name])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user, ?ServiceJob $job = null): array
    {
        return [
            'brands' => $this->brandOptions($user, true, $job),
            'jobTypes' => $this->jobTypeOptions($job),
            'leadSources' => LeadSource::options(),
            'applianceTypes' => ApplianceType::options(),
            'manufacturers' => CustomerController::manufacturers(),
            'assignableUsers' => $this->assignableUsers(),
            'visitTypes' => VisitType::options(),
        ];
    }

    /**
     * Job types of the company's vertical, plus the job's own type if it is not one of them.
     *
     * @return list<array{value: string, label: string}>
     */
    private function jobTypeOptions(?ServiceJob $job): array
    {
        $types = currentCompany()->vertical->jobTypes();

        if ($job !== null && ! in_array($job->job_type, $types, true)) {
            $types[] = $job->job_type;
        }

        return array_map(fn (JobType $type) => ['value' => $type->value, 'label' => $type->label()], $types);
    }

    private function date(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Orders the All tab: by visit date (default: newest first, jobs without a visit after them), job number,
     * status in workflow order, customer name or the invoiced total. Ties fall back to the newest job.
     *
     * @param  Builder<ServiceJob>  $query
     */
    private function sortTable(Builder $query, string $sort, string $dir, string $lastVisit): void
    {
        $nulls = $dir === 'asc' ? 'nulls first' : 'nulls last';

        match ($sort) {
            'number' => $query->orderBy('number', $dir),
            'status' => $query->orderByRaw(
                'case status '.collect(JobStatus::cases())->map(fn (JobStatus $status, int $i) => "when '{$status->value}' then {$i}")->implode(' ')." end {$dir}",
            ),
            'customer' => $query->orderBy(
                Customer::query()->select('display_name')->whereColumn('customers.id', 'service_jobs.customer_id'),
                $dir,
            ),
            'total' => $query->orderByRaw(
                "(select sum(i.total) from invoices i where i.service_job_id = service_jobs.id and i.status != 'void' and i.deleted_at is null) {$dir} {$nulls}",
            ),
            default => $query->orderByRaw("{$lastVisit} {$dir} {$nulls}"),
        };

        $query->orderByDesc('id');
    }

    /**
     * @return array{requested: bool, clicked_at: string|null, reminder_sent_at: string|null}|null
     */
    private static function reviewState(ServiceJob $job): ?array
    {
        $request = ReviewRequest::query()->where('service_job_id', $job->id)->where('status', ReviewRequest::SENT)->first();

        if ($request === null && $job->review_clicked_at === null) {
            return null;
        }

        return [
            'requested' => $request !== null,
            'clicked_at' => JobPresenter::iso($job->review_clicked_at),
            'reminder_sent_at' => JobPresenter::iso($request?->reminder_sent_at),
        ];
    }
}
