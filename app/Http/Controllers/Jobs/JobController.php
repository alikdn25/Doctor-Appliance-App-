<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\SaveJob;
use App\Enums\ApplianceType;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\LeadSource;
use App\Enums\PhotoKind;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Requests\Jobs\JobRequest;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\JobChecklistItem;
use App\Models\JobPhoto;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Jobs\JobPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        $status = JobStatus::tryFrom($filters['status']);
        $type = JobType::tryFrom($filters['type']);

        $jobs = ServiceJob::query()
            ->visibleTo($user)
            ->search($filters['search'])
            ->when($filters['status'] === 'open', fn ($q) => $q->whereNotIn('status', self::CLOSED))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('job_type', $type))
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
            'types' => JobType::options(),
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
            ->whereHas('job')
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
            ->with(['assignees', 'job.customer.primaryPhone', 'job.property', 'job.appliances'])
            ->limit(100)
            ->get();

        return Inertia::render('jobs/mine', [
            'tab' => $tab,
            'visits' => $visits->map(function (JobVisit $visit) use ($user, $timezone) {
                $job = $visit->job;

                return [
                    ...JobPresenter::visit($visit, $user, $timezone),
                    'job' => [
                        'id' => $job->id,
                        'number' => $job->number,
                        'status' => $job->status->value,
                        'status_label' => $job->status->label(),
                        'job_type_label' => $job->job_type->label(),
                        'customer' => $job->customer?->display_name,
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
        Gate::authorize('create', ServiceJob::class);

        $customer = $request->integer('customer_id')
            ? Customer::query()->find($request->integer('customer_id'))
            : null;

        return Inertia::render('jobs/form', [
            'job' => null,
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
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.created', ['number' => $job->number])]);

        return to_route('jobs.show', $job);
    }

    public function show(Request $request, ServiceJob $job): Response
    {
        Gate::authorize('view', $job);

        $user = $request->user();
        $timezone = currentCompany()->timezone;
        $job->load([
            'brand', 'customer.phones', 'property', 'appliances', 'visits.assignees', 'statusChanges.user',
            'photos.user', 'checklistItems.doneBy', 'signer',
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
                'signature' => $job->signature_path ? [
                    'url' => route('jobs.signature.show', $job).'?v='.$job->signed_at?->timestamp,
                    'name' => $job->signature_name,
                    'signed_at' => JobPresenter::iso($job->signed_at),
                    'by' => $job->signer?->name,
                ] : null,
            ],
            'myVisitId' => $myVisit?->id,
            'can' => [
                'update' => $canUpdate,
                'delete' => Gate::allows('delete', $job),
                'work' => Gate::allows('work', $job),
                'viewCustomer' => Gate::allows('view', $job->customer),
            ],
            'statusOptions' => $canUpdate && ! $job->status->isLocked() ? JobStatus::manualOptions() : [],
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
            ],
            'customer' => self::customerOption($job->customer),
            'today' => CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d'),
            ...$this->formOptions($request->user(), $job),
        ]);
    }

    public function update(JobRequest $request, ServiceJob $job, SaveJob $save): RedirectResponse
    {
        $save->update($job, $request->jobAttributes(), $request->applianceIds(), $request->newAppliances());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.updated')]);

        return to_route('jobs.show', $job);
    }

    public function destroy(ServiceJob $job, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('delete', $job);

        $job->delete();
        $audit->record('job.deleted', $job, ['number' => $job->number]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.deleted')]);

        return to_route('jobs.index');
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
            'phone' => $customer->primaryPhone?->number,
            'lead_source' => $customer->lead_source?->value,
            'properties' => $customer->properties->map(fn (Property $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'full_address' => $p->fullAddress(),
                'is_primary' => $p->is_primary,
                'appliances' => $p->appliances->map(fn (Appliance $a) => JobPresenter::appliance($a))->values(),
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
            'jobTypes' => JobType::options(),
            'leadSources' => LeadSource::options(),
            'applianceTypes' => ApplianceType::options(),
            'manufacturers' => CustomerController::manufacturers(),
            'assignableUsers' => $this->assignableUsers(),
        ];
    }

    private function date(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
