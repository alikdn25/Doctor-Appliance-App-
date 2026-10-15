<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Billing\CashLedger;
use App\Actions\Jobs\RefundOriginalJob;
use App\Actions\Jobs\SaveJob;
use App\Enums\ApplianceType;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\LeadSource;
use App\Enums\PaymentMethod;
use App\Enums\PhotoKind;
use App\Enums\VisitStatus;
use App\Enums\VisitType;
use App\Enums\WarrantyUnit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Mileage\TripController;
use App\Http\Requests\Jobs\JobRequest;
use App\Messaging\MessagingPresenter;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobBringItem;
use App\Models\JobCostItem;
use App\Models\JobPhoto;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SupplierReceipt;
use App\Models\Trip;
use App\Models\User;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\CostAccess;
use App\Support\Billing\JobProfit;
use App\Support\Jobs\JobBacklog;
use App\Support\Jobs\JobPresenter;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
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
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $canAll = Gate::allows('viewAny', ServiceJob::class);
        // Links with a filter of the full list (status, technician, dates…) open All; otherwise Open.
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

        $sort = in_array($request->query('sort'), ['urgency', 'newest', 'visit', 'number'], true) ? $request->query('sort') : '';
        $shared = [
            'tab' => $tab,
            'sort' => $sort,
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
                'reason' => in_array($request->query('reason'), JobBacklog::REASONS, true) ? $request->query('reason') : '',
                'sort' => $sort === 'newest' ? 'newest' : 'urgency',
            ];

            return Inertia::render('jobs/index', [
                ...$shared,
                'jobs' => JobBacklogController::jobs($user, $filters),
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

        $status = JobStatus::tryFrom($filters['status']);
        $type = JobType::tryFrom($filters['type']);
        $visitType = VisitType::tryFrom($filters['visit_type']);
        $outcome = JobOutcome::tryFrom($filters['outcome']);
        $lastVisit = '(select max(v.scheduled_start) from job_visits v where v.service_job_id = service_jobs.id)';

        $jobs = ServiceJob::query()
            ->visibleTo($user)
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
            ->when(
                $sort === 'number',
                fn ($q) => $q->orderByDesc('number'),
                // By visit date, newest first; jobs without a visit after them (by when they were booked).
                fn ($q) => $q->orderByRaw("{$lastVisit} desc nulls last")->orderByDesc('id'),
            )
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
        $finished = [JobStatus::Completed->value, JobStatus::Invoiced->value, JobStatus::Paid->value];

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

        $messaging = app(MessagingPresenter::class);

        // My Jobs lists this person's visits (and unassigned ones for the office); the office is told how many
        // other people have that day, so an empty day here is not mistaken for an empty schedule.
        $othersOnDay = $tab === 'day' && $search === '' && $office
            ? JobVisit::query()
                ->whereBetween('scheduled_start', [$dayStart, $dayEnd])
                ->where('status', '!=', VisitStatus::Cancelled->value)
                ->whereHas('assignees')
                ->whereDoesntHave('assignees', fn (Builder $q) => $q->where('users.id', $user->id))
                ->whereHas('job', fn (Builder $q) => $q->visibleTo($user))
                ->count()
            : 0;

        return Inertia::render('jobs/mine', [
            'tab' => $tab,
            'othersOnDay' => $othersOnDay,
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'search' => $search,
            // Cash this person collected and has not handed in yet.
            'cashOnHand' => CashLedger::balances()[$user->id] ?? [],
            // The Trip sheet's "Recent" chips (only for people who log trips).
            'recentStops' => Gate::allows('create', Trip::class) ? TripController::recentStops($user) : [],
            'visits' => $visits->map(function (JobVisit $visit) use ($user, $timezone, $messaging) {
                $job = $visit->job;

                return [
                    ...JobPresenter::visit($visit, $user, $timezone),
                    // The card's main button: On my way / Start / Finish visit, while field work is allowed.
                    'can_work' => $job->status->allowsVisitWork() && Gate::allows('work', $job),
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
            'bookingTechnician' => $request->integer('technician') ?: null,
            'customer' => $customer ? self::customerOption($customer) : null,
            'today' => CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d'),
            // Company clock, so the booking form never suggests a window that has already passed.
            'nowTime' => CarbonImmutable::now(currentCompany()->timezone)->format('H:i'),
            'arrivalWindows' => currentCompany()->arrivalWindows(),
            ...($quick ? $this->bookingTechnicians() : []),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(JobRequest $request, SaveJob $save): RedirectResponse
    {
        $job = $save->create(
            $request->customer(),
            $request->jobAttributes(),
            $request->applianceIds(),
            $request->newAppliances(),
            $request->newCustomer(),
            $request->visit(),
            $request->user(),
            $request->bringItems(),
        );

        $visit = $request->boolean('quick_booking') && ! $request->boolean('open_invoice') ? $job->visits()->first() : null;

        // Booking (UX-REDESIGN §7.1): back to the day's schedule with "Booked #1005 · Tomorrow 9–11" and Open job.
        if ($visit !== null) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('jobs.quick.booked', ['number' => $job->number, 'when' => self::bookedWhen($visit)]),
                'action' => ['label' => __('jobs.quick.open_job'), 'url' => route('jobs.show', $job, false)],
            ]);
            $date = CarbonImmutable::parse($visit->scheduled_start)->setTimezone(currentCompany()->timezone)->toDateString();

            return Gate::allows('dispatch', ServiceJob::class)
                ? to_route('calendar', ['view' => 'day', 'date' => $date])
                : to_route('jobs.mine', ['date' => $date]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.created', ['number' => $job->number])]);

        return $request->boolean('open_invoice')
            ? to_route('invoices.create', $job)
            : to_route('jobs.show', $job);
    }

    /**
     * "Today 9–11 a.m.", "Tomorrow 1–3 p.m." or "Fri, Oct 16 9–11 a.m." in the company's time zone and format.
     */
    private static function bookedWhen(JobVisit $visit): string
    {
        $company = currentCompany();
        $start = CarbonImmutable::parse($visit->scheduled_start)->setTimezone($company->timezone);
        $end = CarbonImmutable::parse($visit->scheduled_end)->setTimezone($company->timezone);
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        // The wall-clock time is worked out by Carbon; ICU only spells it in the company's format (as UTC, so its
        // own time-zone rules never shift the hour).
        $wall = fn (CarbonImmutable $moment) => new DateTimeImmutable($moment->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        $day = match ((int) $today->diffInDays($start->startOfDay())) {
            0 => __('jobs.quick.today'),
            1 => __('jobs.quick.tomorrow'),
            default => (new IntlDateFormatter($company->locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'EEE, MMM d'))->format($wall($start)),
        };
        $hours = new IntlDateFormatter($company->locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, 'UTC');

        return $day.' '.$hours->format($wall($start)).'–'.$hours->format($wall($end));
    }

    public function show(Request $request, ServiceJob $job): Response|RedirectResponse
    {
        Gate::authorize('view', $job);

        $user = $request->user();
        $timezone = currentCompany()->timezone;
        $job->load([
            'brand', 'customer.phones', 'property', 'appliances', 'visits.assignees', 'statusChanges.user',
            'photos.user', 'signer', 'estimates', 'invoices',
            'bringItems.checker', 'previousJob', 'followUps', 'closer',
        ]);
        $canUpdate = Gate::allows('update', $job);
        $property = $job->property;
        $myVisit = JobPresenter::myNextVisit($job, $user);

        // Old "finish" links open the Finish visit screen.
        if ($request->boolean('finish') && $myVisit?->status === VisitStatus::InProgress) {
            return to_route('visits.finish-screen', $myVisit);
        }

        return Inertia::render('jobs/show', [
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
                'outcome_label' => $job->outcome?->label(),
                'outcome_reason' => $job->outcome_reason,
                'outcome_note' => $job->outcome_note,
                'closed_at' => JobPresenter::iso($job->closed_at),
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
                'description' => $job->description,
                'notes' => $job->notes,
                'tech_notes' => $job->tech_notes,
                'created_at' => JobPresenter::iso($job->created_at),
                'completed_at' => JobPresenter::iso($job->completed_at),
                'allows_visit_work' => $job->status->allowsVisitWork(),
                'customer' => [
                    'id' => $job->customer->id,
                    'display_name' => $job->customer->display_name,
                    'avatar_icon' => $job->customer->avatarIcon(),
                    'avatar_style' => $job->customer->avatar_style,
                    'notes' => $job->customer->notes,
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
                'signature' => JobPresenter::signature($job),
            ],
            'myVisitId' => $myVisit?->id,
            'messaging' => app(MessagingPresenter::class)->forJob($job, $user, $myVisit),
            'can' => [
                'update' => $canUpdate,
                'delete' => Gate::allows('delete', $job),
                'work' => Gate::allows('work', $job),
                'close' => Gate::allows('work', $job) && $job->status !== JobStatus::Cancelled && ! $job->trashed(),
                'viewCustomer' => Gate::allows('view', $job->customer),
                'updateCustomer' => Gate::allows('update', $job->customer),
            ],
            'statusOptions' => $canUpdate && ! $job->status->isLocked() ? JobStatus::manualOptions() : [],
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
            // Costs, receipts and profit: only for people who see costs.
            'costs' => CostAccess::canSee($user) ? [
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
            'photoKinds' => PhotoKind::options(),
            // "Mark as paid": invoices with money owed that this person may take a payment for.
            'payable' => $job->invoices
                ->filter(fn (Invoice $i) => ! $i->isVoid() && $i->balance > 0 && Gate::allows('recordPayment', $i))
                ->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'balance' => $i->balance, 'currency' => $i->currency])
                ->values(),
            'paymentMethods' => PaymentMethod::manualOptions(currentCompany()),
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.updated')]);

        return to_route('jobs.show', $job);
    }

    public function destroy(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('delete', $job);

        // Invoices and payments are financial records: such a job is cancelled or closed instead.
        $blocker = $job->deleteBlocker();
        if ($blocker !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $blocker]);

            return to_route('jobs.show', $job);
        }

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
     * Deleted jobs: the Deleted tab of Jobs. The old address opens that tab.
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
            ->with(['primaryPhone', 'properties.appliances'])
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
        $customer->loadMissing(['primaryPhone', 'properties.appliances']);

        return [
            'id' => $customer->id,
            'display_name' => $customer->display_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'company_name' => $customer->company_name,
            'avatar_icon' => $customer->avatarIcon(),
            'notes' => $customer->notes,
            'phone' => $customer->primaryPhone?->number,
            'lead_source' => $customer->lead_source?->value,
            'properties' => $customer->properties->map(fn (Property $p) => [
                'id' => $p->id,
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
}
