<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Enums\ApplianceType;
use App\Enums\CustomerType;
use App\Enums\EmailLabel;
use App\Enums\LeadSource;
use App\Enums\PaymentTerms;
use App\Enums\PhoneLabel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerRequest;
use App\Messaging\MessagingPresenter;
use App\Models\Appliance;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\CustomerPhone;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Services\AuditLogger;
use App\Support\Billing\BillingPresenter;
use App\Support\Jobs\JobPresenter;
use App\Support\NameAvatar;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('search', ''));
        $type = CustomerType::tryFrom((string) $request->query('type'));
        $tag = trim((string) $request->query('tag', ''));

        $customers = Customer::query()
            ->search($search)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($tag !== '', fn ($q) => $q->whereJsonContains('tags', $tag))
            ->with(['primaryPhone', 'primaryProperty'])
            ->orderBy('display_name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Customer $customer) => [
                'id' => $customer->id,
                'display_name' => $customer->display_name,
                'avatar_icon' => $customer->avatarIcon(),
                'type' => $customer->type->value,
                'type_label' => $customer->type->label(),
                'phone' => $customer->primaryPhone?->number,
                'address' => $customer->primaryProperty?->fullAddress(),
                'tags' => $customer->tags,
            ]);

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'filters' => ['search' => $search, 'type' => $type?->value ?? '', 'tag' => $tag],
            'types' => CustomerType::options(),
            'tags' => $this->tags(),
        ]);
    }

    public function avatar(Request $request): JsonResponse
    {
        Gate::authorize('create', Customer::class);
        $data = $request->validate(['first_name' => ['nullable', 'string', 'max:100']]);

        return response()->json(['icon' => NameAvatar::suggest($data['first_name'] ?? null)]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/form', [
            'customer' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(CustomerRequest $request, SaveCustomer $save): RedirectResponse
    {
        $customer = $save->handle(
            null,
            $request->customerAttributes(),
            $request->validated('phones', []),
            $request->validated('emails', []),
            $request->propertyAttributes(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('customers.created')]);

        return to_route('customers.show', $customer);
    }

    public function show(Request $request, Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        $user = $request->user();
        $jobs = ServiceJob::query()
            ->visibleTo($user)
            ->where('customer_id', $customer->id)
            ->with(['customer.primaryPhone', 'property', 'brand', 'appliances', 'visits.assignees'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $customer->load(['phones', 'emails', 'properties.appliances']);

        // Technicians only see the properties of their own jobs.
        if (Gate::denies('update', $customer)) {
            $propertyIds = $jobs->pluck('property_id')->unique();
            $customer->setRelation('properties', $customer->properties->whereIn('id', $propertyIds)->values());
        }

        return Inertia::render('customers/show', [
            'messaging' => app(MessagingPresenter::class)->forCustomer($customer, $user),
            'customer' => [
                ...$this->customerData($customer),
                'type_label' => $customer->type->label(),
                'lead_source_label' => $customer->lead_source?->label(),
                'payment_terms_label' => $customer->payment_terms?->label(),
                'created_at' => $customer->created_at?->toDateString(),
                'properties' => $customer->properties->map(fn (Property $property) => [
                    ...self::propertyData($property),
                    'full_address' => $property->fullAddress(),
                    'appliances' => $property->appliances->map(fn (Appliance $appliance) => [
                        'id' => $appliance->id,
                        'type' => $appliance->type->value,
                        'type_label' => $appliance->type->label(),
                        'manufacturer' => $appliance->manufacturer,
                        'model_number' => $appliance->model_number,
                        'serial_number' => $appliance->serial_number,
                        'under_warranty' => $appliance->isUnderWarranty(),
                    ])->values(),
                ])->values(),
            ],
            'jobs' => $jobs->map(fn (ServiceJob $job) => JobPresenter::row($job))->values(),
            // Estimates and invoices of the jobs the user can see.
            'estimates' => Estimate::query()
                ->where('customer_id', $customer->id)
                ->whereIn('service_job_id', ServiceJob::query()->visibleTo($user)->select('id'))
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (Estimate $e) => BillingPresenter::row($e))
                ->values(),
            'invoices' => Invoice::query()
                ->where('customer_id', $customer->id)
                ->whereIn('service_job_id', ServiceJob::query()->visibleTo($user)->select('id'))
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (Invoice $i) => BillingPresenter::row($i))
                ->values(),
            'canUpdate' => Gate::allows('update', $customer),
            'canDelete' => Gate::allows('delete', $customer),
            'canCreateJob' => Gate::allows('create', ServiceJob::class),
            'applianceTypes' => ApplianceType::options(),
            'manufacturers' => self::manufacturers(),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        $customer->load(['phones', 'emails']);

        return Inertia::render('customers/form', [
            'customer' => $this->customerData($customer),
            ...$this->formOptions(),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer, SaveCustomer $save): RedirectResponse
    {
        $save->handle(
            $customer,
            $request->customerAttributes(),
            $request->validated('phones', []),
            $request->validated('emails', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('customers.updated')]);

        return to_route('customers.show', $customer);
    }

    public function destroy(Customer $customer, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        if (ServiceJob::query()->where('customer_id', $customer->id)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('customers.has_jobs')]);

            return to_route('customers.show', $customer);
        }

        $customer->delete();
        $audit->record('customer.deleted', $customer, ['name' => $customer->display_name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('customers.deleted')]);

        return to_route('customers.index');
    }

    /**
     * Existing customers with the same phone or email (duplicate warning, never a block).
     */
    public function duplicates(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $validated = $request->validate([
            'phones' => ['array', 'max:10'],
            'phones.*' => ['nullable', 'string', 'max:32'],
            'emails' => ['array', 'max:10'],
            'emails.*' => ['nullable', 'string', 'max:255'],
            'ignore' => ['nullable', 'integer'],
        ]);

        $phones = collect($validated['phones'] ?? [])
            ->filter(fn ($p) => PhoneNumber::isPossible($p))
            ->map(fn ($p) => PhoneNumber::normalize($p))
            ->unique()
            ->values();
        $emails = collect($validated['emails'] ?? [])
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        $matches = collect();

        if ($phones->isNotEmpty()) {
            CustomerPhone::query()
                ->whereIn('number_normalized', $phones)
                ->whereHas('customer')
                ->with('customer')
                ->get()
                ->each(fn (CustomerPhone $p) => $matches->push(['customer' => $p->customer, 'match' => $p->number]));
        }

        if ($emails->isNotEmpty()) {
            CustomerEmail::query()
                ->whereIn('email', $emails)
                ->whereHas('customer')
                ->with('customer')
                ->get()
                ->each(fn (CustomerEmail $e) => $matches->push(['customer' => $e->customer, 'match' => $e->email]));
        }

        $ignore = isset($validated['ignore']) ? (int) $validated['ignore'] : null;

        return response()->json([
            'duplicates' => $matches
                ->reject(fn ($m) => $m['customer']->id === $ignore)
                ->groupBy(fn ($m) => $m['customer']->id)
                ->map(fn ($group) => [
                    'id' => $group->first()['customer']->id,
                    'display_name' => $group->first()['customer']->display_name,
                    'matches' => $group->pluck('match')->unique()->values(),
                ])
                ->take(5)
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerData(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'type' => $customer->type->value,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'company_name' => $customer->company_name,
            'display_name' => $customer->display_name,
            'avatar_style' => $customer->avatar_style,
            'avatar_icon' => $customer->avatarIcon(),
            'lead_source' => $customer->lead_source?->value,
            'payment_terms' => $customer->payment_terms?->value,
            'tags' => $customer->tags,
            'notes' => $customer->notes,
            'phones' => $customer->phones->map(fn (CustomerPhone $p) => [
                'id' => $p->id,
                'label' => $p->label->value,
                'label_text' => $p->label->label(),
                'number' => $p->number,
                'is_primary' => $p->is_primary,
                'sms_opted_out_at' => $p->sms_opted_out_at?->toIso8601String(),
            ])->values(),
            'emails' => $customer->emails->map(fn (CustomerEmail $e) => [
                'id' => $e->id,
                'label' => $e->label->value,
                'label_text' => $e->label->label(),
                'email' => $e->email,
                'is_primary' => $e->is_primary,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function propertyData(Property $property): array
    {
        return $property->only([
            'id', 'label', 'line1', 'line2', 'unit', 'city', 'region', 'postal_code', 'country',
            'access_notes', 'gate_code', 'site_contact_name', 'site_contact_phone', 'is_primary',
            'google_place_id', 'latitude', 'longitude',
        ]);
    }

    /**
     * Manufacturers already used by the company plus common ones, for autocomplete.
     *
     * @return list<string>
     */
    public static function manufacturers(): array
    {
        return Appliance::query()
            ->whereNotNull('manufacturer')
            ->distinct()
            ->pluck('manufacturer')
            ->merge(config('fieldservice.appliance_manufacturers'))
            ->unique(fn ($name) => mb_strtolower($name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function tags(): array
    {
        return Customer::query()
            ->selectRaw('distinct jsonb_array_elements_text(tags) as tag')
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => CustomerType::options(),
            'leadSources' => LeadSource::options(),
            'paymentTerms' => PaymentTerms::options(),
            'defaultPaymentTerms' => currentCompany()->default_payment_terms->label(),
            'phoneLabels' => PhoneLabel::options(),
            'emailLabels' => EmailLabel::options(),
            'tags' => $this->tags(),
        ];
    }
}
