<?php

namespace App\Http\Controllers\Parts;

use App\Actions\Jobs\ChangeJobStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\Appliance;
use App\Models\JobPart;
use App\Models\ServiceJob;
use App\Models\Supplier;
use App\Support\Parts\JobParts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Parts page (owner's task of November 19): "Order" (grouped by supplier, parts without one in their own group)
 * and "Waiting" (order date, days waited, more than 7 highlighted), with the count on each tab. Number, quantity and
 * supplier are edited in the row; Bought / Ordered / Arrived move a part; the job's status follows (JobParts). A part
 * is added with "Part needed" on the job, "+" here, or Close by voice.
 */
class PartsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ServiceJob::class);
        $tab = $request->query('tab') === 'waiting' ? 'waiting' : 'order';
        $timezone = currentCompany()->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $user = $request->user();

        $base = fn () => JobPart::query()->whereHas('job', fn (Builder $q) => $q->visibleTo($user)->where('status', '!=', JobStatus::Cancelled->value));
        $parts = $base()
            ->where('status', $tab === 'order' ? JobPart::TO_ORDER : JobPart::ORDERED)
            ->with(['supplier', 'job.customer', 'job.appliances'])
            ->when($tab === 'waiting', fn ($q) => $q->orderBy('ordered_on'))
            ->orderBy('id')
            ->limit(300)
            ->get();

        return Inertia::render('parts/index', [
            'tab' => $tab,
            'counts' => [
                'order' => $base()->where('status', JobPart::TO_ORDER)->count(),
                'waiting' => $base()->where('status', JobPart::ORDERED)->count(),
            ],
            'parts' => $parts->map(fn (JobPart $part) => self::present($part, $today))->values(),
            'suppliers' => Supplier::options(),
            // "+": a part for one of the open jobs.
            'jobs' => ServiceJob::query()->visibleTo($user)->with('customer')
                ->whereNotIn('status', [JobStatus::Invoiced->value, JobStatus::Paid->value, JobStatus::Cancelled->value, JobStatus::Draft->value])
                ->latest('id')->limit(80)->get()
                ->map(fn (ServiceJob $job) => ['value' => (string) $job->id, 'label' => trim('#'.$job->number.' '.($job->customer?->display_name ?? ''))])
                ->values(),
            'canManageSuppliers' => Gate::allows('manageCatalog'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(JobPart $part, CarbonImmutable $today): array
    {
        $job = $part->job;
        $waited = $part->ordered_on === null ? null : (int) CarbonImmutable::parse($part->ordered_on->format('Y-m-d'), $today->timezone)->diffInDays($today);

        return [
            'id' => $part->id,
            'name' => $part->name,
            'part_number' => $part->part_number,
            'number_unverified' => $part->number_unverified,
            'quantity' => $part->quantity,
            'supplier_id' => $part->supplier_id === null ? null : (string) $part->supplier_id,
            'supplier' => $part->supplier?->name,
            'status' => $part->status,
            'ordered_on' => $part->ordered_on?->format('Y-m-d'),
            'days_waiting' => $waited,
            'long_wait' => $waited !== null && $waited > JobPart::LONG_WAIT_DAYS,
            'job' => [
                'id' => $job->id,
                'number' => $job->number,
                'status' => $job->status->value,
                'customer' => $job->customer?->display_name,
                // Appliance and model, from the job (the rating plate photo fills the model on the appliance).
                'appliance' => $job->appliances->map(fn (Appliance $a) => trim(implode(' ', array_filter([
                    $a->manufacturer, $a->type->label(), $a->model_number ? '· '.$a->model_number : null,
                ]))))->implode(', ') ?: null,
            ],
            'can_edit' => Gate::allows('work', $job),
        ];
    }

    /**
     * "Part needed" on the job, "+" on the Parts page.
     */
    public function store(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('work', $job);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', currentCompany()->id)->whereNull('deleted_at')],
        ]);

        JobParts::add($job, $data, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('parts.added')]);

        return back();
    }

    /**
     * Number, quantity, supplier (and name) right in the row. Changing the number, or confirming it, takes the
     * "check" mark off.
     */
    public function update(Request $request, JobPart $part): RedirectResponse
    {
        Gate::authorize('work', $part->job);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'part_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'confirm_number' => ['sometimes', 'boolean'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:999'],
            'supplier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', currentCompany()->id)->whereNull('deleted_at')],
        ]);

        if (array_key_exists('name', $data)) {
            $part->name = trim($data['name']);
        }
        if (array_key_exists('part_number', $data)) {
            $number = filled($data['part_number']) ? trim((string) $data['part_number']) : null;
            if ($number !== $part->part_number) {
                $part->part_number = $number;
                $part->number_unverified = false;
            }
        }
        if ($request->boolean('confirm_number')) {
            $part->number_unverified = false;
        }
        if (array_key_exists('quantity', $data)) {
            $part->quantity = (int) $data['quantity'];
        }
        $supplierChanged = array_key_exists('supplier_id', $data) && $data['supplier_id'] !== $part->supplier_id;
        if (array_key_exists('supplier_id', $data)) {
            $part->supplier_id = $data['supplier_id'];
        }
        $part->save();

        if ($supplierChanged) {
            JobParts::changed($part->job, $request->user());
        }

        return back();
    }

    /** Bought: on hand at once, off the list. Offers "Add a receipt?". */
    public function bought(Request $request, JobPart $part): RedirectResponse
    {
        return $this->move($request, $part, JobPart::ON_HAND, [JobPart::TO_ORDER], receipt: true);
    }

    /** Ordered: to Waiting with today's date. */
    public function ordered(Request $request, JobPart $part): RedirectResponse
    {
        return $this->move($request, $part, JobPart::ORDERED, [JobPart::TO_ORDER]);
    }

    /** Arrived: on hand, off the list. */
    public function arrived(Request $request, JobPart $part): RedirectResponse
    {
        return $this->move($request, $part, JobPart::ON_HAND, [JobPart::ORDERED]);
    }

    /**
     * "Parts ordered" on the job: every part still to order is ordered today (a job without parts just moves to
     * Waiting for parts, as before).
     */
    public function orderedAll(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('update', $job);
        $parts = $job->parts()->where('status', JobPart::TO_ORDER)->get();

        if ($parts->isEmpty() && $job->parts()->doesntExist()) {
            app(ChangeJobStatus::class)->handle($job, JobStatus::WaitingForParts, $request->user());

            return back();
        }

        $parts->each(fn (JobPart $part) => JobParts::move($part, JobPart::ORDERED, $request->user()));

        return back();
    }

    public function destroy(Request $request, JobPart $part): RedirectResponse
    {
        Gate::authorize('work', $part->job);
        $job = $part->job;
        $part->delete();
        JobParts::changed($job, $request->user());

        return back();
    }

    /**
     * @param  list<string>  $from
     */
    private function move(Request $request, JobPart $part, string $to, array $from, bool $receipt = false): RedirectResponse
    {
        Gate::authorize('work', $part->job);
        abort_unless(in_array($part->status, $from, true), 409);

        $ready = JobParts::move($part, $to, $request->user());

        if ($ready === JobStatus::PartsReady && Gate::allows('update', $part->job)) {
            // Every part of the job is on hand: the text with the nearest windows opens (sent only on Confirm).
            Inertia::flash('partsReadyJob', $part->service_job_id);
        }
        if ($receipt) {
            Inertia::flash('offerReceipt', true);
        }

        return back();
    }
}
