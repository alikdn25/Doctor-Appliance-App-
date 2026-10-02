<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\CloseJob;
use App\Actions\Jobs\RefundOriginalJob;
use App\Enums\JobOutcome;
use App\Enums\VisitType;
use App\Http\Controllers\Controller;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Support\Locale\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Closing a job with its outcome from the job page (when no visit is on the way or in progress). On site the
 * technician does the same from "Finish". A warranty callback the customer declined (or that could not be
 * repaired) may refund the original job.
 */
class JobCloseController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job, CloseJob $close, RefundOriginalJob $refund): RedirectResponse
    {
        Gate::authorize('work', $job);

        $data = self::validateClose($request, $job, array_map(fn (JobOutcome $o) => $o->value, JobOutcome::closing()));
        $outcome = JobOutcome::from($data['outcome']);

        DB::transaction(function () use ($job, $outcome, $data, $request, $close, $refund) {
            $close->close($job, $outcome, $data['reason'] ?? null, $data['note'] ?? null, $request->user());
            self::refundOriginal($job, $outcome, $data, $request, $refund);
        });

        return self::afterClose($job, $outcome, $request) ?? tap(back(), fn () => Inertia::flash('toast', [
            'type' => 'success', 'message' => __('jobs.closed', ['outcome' => $outcome->label()]),
        ]));
    }

    /**
     * Shared by "Close job" and "Finish": outcome, reason from the company's list, comment, the diagnosis-only
     * invoice, and the callback refund.
     *
     * @param  list<string>  $outcomes
     * @return array<string, mixed>
     */
    public static function validateClose(Request $request, ServiceJob $job, array $outcomes): array
    {
        $outcome = JobOutcome::tryFrom((string) $request->input('outcome'));
        $callback = $job->visit_type === VisitType::Callback && $job->previous_job_id !== null;
        $refunding = $callback && $outcome?->allowsCallbackRefund() && in_array($request->input('refund'), ['full', 'partial'], true);

        return $request->validate([
            'outcome' => ['required', Rule::in($outcomes)],
            'reason' => $outcome?->needsReason()
                ? ['required', Rule::in(currentCompany()->closureReasons($outcome))]
                : ['nullable'],
            'note' => ['nullable', 'string', 'max:2000'],
            'invoice_diagnosis' => ['boolean'],
            'refund' => ['nullable', Rule::in(['none', 'full', 'partial'])],
            'refund_amount' => $refunding && $request->input('refund') === 'partial'
                ? ['required', 'numeric', 'gt:0']
                : ['nullable'],
            'refund_reason' => $refunding ? ['required', 'string', 'max:500'] : ['nullable'],
        ], [], [
            'reason' => __('jobs.fields.reason'),
            'refund_reason' => __('jobs.callback.refund_reason'),
            'refund_amount' => __('payments.refunds.amount'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function refundOriginal(ServiceJob $job, JobOutcome $outcome, array $data, Request $request, RefundOriginalJob $refund): void
    {
        $mode = $data['refund'] ?? 'none';

        if (! $outcome->allowsCallbackRefund() || $job->visit_type !== VisitType::Callback || ! in_array($mode, ['full', 'partial'], true)) {
            return;
        }

        $currency = $job->previousJob()->first()?->invoices()->value('currency') ?? currentCompany()->currency;
        $amount = $mode === 'partial' ? Currencies::toMinor((string) $data['refund_amount'], $currency) : null;

        $refund->handle($job, $mode, $amount, (string) $data['refund_reason'], $request->user());
    }

    /**
     * Where to go after closing: the diagnosis-only invoice, or the warranty review of a repaired job.
     */
    public static function afterClose(ServiceJob $job, JobOutcome $outcome, Request $request): ?RedirectResponse
    {
        if ($outcome->allowsDiagnosisInvoice() && $request->boolean('invoice_diagnosis')) {
            return to_route('invoices.create', ['job' => $job->id, 'diagnosis' => 1]);
        }

        // Repaired with invoices: review the warranties of the lines ("Apply to all lines").
        if (in_array($outcome, [JobOutcome::Repaired, JobOutcome::FixedUnderWarranty], true) && $job->invoices()->exists()) {
            return to_route('jobs.show', ['job' => $job->id, 'warranty' => 1]);
        }

        return null;
    }

    /**
     * Visits use the same rules; kept here so both stay in step.
     */
    public static function visitJob(JobVisit $visit): ServiceJob
    {
        return $visit->job()->firstOrFail();
    }
}
