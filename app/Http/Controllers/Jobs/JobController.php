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
use App\Enums\PhotoKind;
use App\Enums\VisitStatus;
use App\Enums\VisitType;
use App\Enums\WarrantyUnit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customers\CustomerController;
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
use App\Models\JobChecklistItem;
use App\Models\JobCostItem;
use App\Models\JobPhoto;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SupplierReceipt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\CostAccess;
use App\Support\Billing\JobProfit;
use App\Support\Jobs\JobPresenter;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    private const CLOSED = ['completed', 'invoiced', 'paid', 'cancelled'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (! Gate::allows('viewAny', ServiceJob::class)) {
            Gate::authorize('viewMine', ServiceJob::class);

            return to_route('jobs.mine');
        }

        $user = $request->user();
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
            ->with(['customer', 'property', 'brand', 'appliances', 'visits.assignees'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceJob $job) => JobPresenter::row($job));

        return Inertia::render('jobs/index', [
            'jobs' => $jobs,
            'filters' => $filters,
            'statuses' => JobStatus::options(),
            'types' => currentCompany()->vertical->jobTypeOptions(),
            'visitTypes' => VisitType::options(),
            'outcomes' => JobOutcome::options(),
            'canViewTrash' => Gate::allows('viewTrash', ServiceJob::class),
            'brands' => $this->brandOptions($user, activeOnly: false),
            'technicians' => $this->assignableUsers(),
            'canCreate' => Gate::allows('create', ServiceJob::class),
        ]);
    }

    /**
     * "My jobs": the visits assigned to the current user (technicians, and Owners/Admins who go on calls).
     */
    public function mine(Request $request): Response
    {
        Gate::authorize('viewMine', ServiceJob::class);

        $user = $request->user();
        $tab = in_array($request->query('tab'), ['today', 'upcoming', 'recent'], true) ? $request->query('tab') : 'today';
        $timezone = currentCompany()->timezone;
        $todayStart = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $todayEnd = CarbonImmutable::now($timezone)->endOfDay()->utc();

        $visits = JobVisit::query()
            ->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $user->id))
            ->whereHas('job', fn (Builder $q) => $q->visibleTo($user))
            ->when($tab === 'today', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->whereBetween('scheduled_start', [$todayStart, $todayEnd])
                    ->orWhereIn('status', [VisitStatus::OnTheWay->value, VisitStatus::InProgress->value]))
                ->orderBy('scheduled_start'))
            ->when($tab === 'upcoming', fn ($q) => $q
                ->where('scheduled_start', '>', $todayEnd)
                ->where('status', VisitStatus::Scheduled->value)
                ->orderBy('scheduled_start'))
            ->when($tab === 'recent', fn ($q) => $q
                ->where('scheduled_start', '<', $todayStart)
                ->where('scheduled_start', '>=', $todayStart->subDays(30))
                ->orderByDesc('scheduled_start'))
            ->with(['assignees', 'job.customer.primaryPhone', 'job.property', 'job.appliances', 'job.bringItems'])
            ->limit(100)
            ->get();

        $messaging = app(MessagingPresenter::class);

        return Inertia::render('jobs/mine', [
            'tab' => $tab,
            // Cash this person collected and has not handed in yet.
            'cashOnHand' => CashLedger::balances()[$user->id] ?? [],
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
            'customer' => $customer ? self::customerOption($customer) : null,
            'today' => CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d'),
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.created', ['number' => $job->number])]);

        return $request->boolean('open_invoice')
            ? to_route('invoices.create', $job)
            : to_route('jobs.show', $job);
    }

    public function show(Request $request, ServiceJob $job): Response
    {
        Gate::authorize('view', $job);

        $user = $request->user();
        $timezone = currentCompany()->timezone;
        $job->load([
            'brand', 'customer.phones', 'property', 'appliances', 'visits.assignees', 'statusChanges.user',
            'photos.user', 'checklistItems.doneBy', 'signer', 'estimates', 'invoices',
            'bringItems.checker', 'previousJob', 'followUps', 'closer',
        ]);
        $canUpdate = Gate::allows('update', $job);
        $property = $job->property;
        $myVisit = JobPresenter::myNextVisit($job, $user);

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
                    'notes' => $job->customer->notes,
                    'phones' => $job->customer->phones->map(fn ($p) => [
                        'id' => $p->id,
                        'number' => $p->number,
                        'label_text' => $p->label->label(),
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
                'checklist' => $job->checklistItems->map(fn (JobChecklistItem $item) => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'is_done' => $item->is_done,
                    'done_by' => $item->doneBy?->name,
                    'done_at' => JobPresenter::iso($item->done_at),
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
                ->flatMap(fn (Invoice $invoice) => $invoice->items()->get()->map(fn (InvoiceItem $item) => [
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
            'openFinish' => $request->boolean('finish'),
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
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.updated')]);

        return to_route('jobs.show', $job);
    }

    public function destroy(Request $request, ServiceJob $job, AuditLogger $audit): RedirectResponse
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
        $audit->record('job.deleted', $job, ['number' => $job->number]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.deleted_restorable', ['days' => ServiceJob::RESTORE_DAYS])]);

        return Gate::allows('viewAny', ServiceJob::class) ? to_route('jobs.index') : to_route('jobs.mine');
    }

    /**
     * Jobs deleted in the last 30 days, which the office can restore.
     */
    public function trash(Request $request): Response
    {
        Gate::authorize('viewTrash', ServiceJob::class);

        $jobs = ServiceJob::onlyTrashed()
            ->visibleTo($request->user())
            ->where('deleted_at', '>=', now()->subDays(ServiceJob::RESTORE_DAYS))
            ->with(['customer', 'property', 'brand', 'deleter'])
            ->orderByDesc('deleted_at')
            ->limit(200)
            ->get();

        return Inertia::render('jobs/trash', [
            'days' => ServiceJob::RESTORE_DAYS,
            'jobs' => $jobs->map(fn (ServiceJob $job) => [
                'id' => $job->id,
                'number' => $job->number,
                'customer' => $job->customer?->display_name,
                'address' => $job->property?->fullAddress(),
                'brand' => $job->brand?->name,
                'deleted_at' => JobPresenter::iso($job->deleted_at),
                'deleted_by' => $job->deleter?->name,
                'restorable_until' => JobPresenter::iso($job->deleted_at?->copy()->addDays(ServiceJob::RESTORE_DAYS)),
            ])->values(),
        ]);
    }

    public function restore(ServiceJob $job, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('restore', $job);

        DB::transaction(function () use ($job) {
            $job->restore();
            $job->forceFill(['deleted_by' => null])->save();
        });
        $audit->record('job.restored', $job, ['number' => $job->number]);

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

        $customers = $search === '' ? collect() : Customer::query()
            ->search($search)
            ->with(['primaryPhone', 'properties.appliances'])
            ->orderBy('display_name')
            ->limit(8)
            ->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => self::customerOption($c))->values(),
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
