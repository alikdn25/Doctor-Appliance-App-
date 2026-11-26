<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Billing\ChargeController;
use App\Http\Controllers\Controller;
use App\Models\GoogleProfile;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Jobs\JobCloseOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The Close job window (owner's task of November 26), opened by "Complete" and by "⋯ → Close job": Paid — Cash,
 * Paid — E-transfer, Take card payment, Send invoice, No charge; then "Receipt & review" and Done, which closes the
 * job and goes to Today. The same step follows Take payment (payments/{payment}/done).
 */
class JobCloseOutController extends Controller
{
    /**
     * What the window needs: the open invoice (or the lines to type), taxes, the ways to pay, the customer's email
     * and the review locations.
     *
     * @return array<string, mixed>
     */
    public static function sheet(ServiceJob $job, User $user): array
    {
        $company = currentCompany();
        $invoice = JobCloseOut::openInvoice($job);

        return [
            ...app(ChargeController::class)->screen(),
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => Money::format($invoice->balance, $invoice->currency, $company->locale),
                'url' => route('invoices.show', $invoice),
            ],
            'can_take_card' => Gate::allows('takeCharges'),
            ...self::reviewStep($job, $user),
            'close_url' => route('jobs.close-out', $job),
            'invoice_url' => route('jobs.close-out.invoice', $job),
            'new_invoice_url' => route('invoices.create', ['job' => $job->id]),
        ];
    }

    /**
     * The customer's email and the review locations (the one picked last time first).
     *
     * @return array<string, mixed>
     */
    public static function reviewStep(ServiceJob $job, User $user): array
    {
        $job->loadMissing(['customer.primaryEmail', 'brand']);
        $customer = $job->customer;
        $profiles = GoogleProfile::query()->orderBy('label')->get(['id', 'label']);
        $default = match (true) {
            $profiles->contains('id', (int) $user->review_profile_id) => (int) $user->review_profile_id,
            $profiles->contains('id', $job->brand?->google_profile_id) => $job->brand?->google_profile_id,
            default => $profiles->first()?->id,
        };

        return [
            'customer_email' => $customer === null || $customer->is_walk_in ? null
                : ($customer->primaryEmail?->email ?? $customer->emails()->value('email')),
            'profiles' => $profiles->map(fn (GoogleProfile $p) => ['id' => $p->id, 'label' => $p->label])->values(),
            'default_profile_id' => $default,
        ];
    }

    /**
     * Take card payment / Send invoice without an invoice yet: the invoice from the lines typed (or the open one).
     */
    public function invoice(Request $request, ServiceJob $job, JobCloseOut $closeOut): JsonResponse
    {
        Gate::authorize('work', $job);
        $data = $request->validate(self::lineRules());

        $invoice = JobCloseOut::openInvoice($job)
            ?? $closeOut->invoiceFromLines($job, array_values($data['lines'] ?? []), (bool) ($data['amounts_include_tax'] ?? false), $request->user());

        if ($invoice === null) {
            return response()->json(['message' => __('payments.errors.amount_required')], 422);
        }

        return response()->json([
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'balance' => $invoice->balance,
            'balance_input' => Money::input($invoice->balance, $invoice->currency),
            'total' => Money::format($invoice->balance, $invoice->currency, currentCompany()->locale),
            'url' => route('invoices.show', $invoice),
        ]);
    }

    /**
     * "Done" of the window.
     */
    public function store(Request $request, ServiceJob $job, JobCloseOut $closeOut): RedirectResponse
    {
        Gate::authorize('work', $job);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'method' => ['required', Rule::in(JobCloseOut::METHODS)],
            'payment_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:5000'],
            ...self::lineRules(),
            ...self::emailRules(),
        ]);

        $open = JobCloseOut::openInvoice($job);
        if ($open !== null && in_array($data['method'], ['cash', 'bank_transfer'], true)) {
            Gate::authorize('recordPayment', $open);
        }

        $closeOut->finish($job, $request->user(), $data);

        return self::toToday($job);
    }

    /**
     * "Done" of the "Receipt & review" step after Take payment.
     */
    public function paid(Request $request, Payment $payment, JobCloseOut $closeOut): RedirectResponse
    {
        $invoice = $payment->invoice ?? abort(404);
        Gate::authorize('view', $invoice);
        $data = $request->validate(['token' => ['required', 'string', 'max:64'], ...self::emailRules()]);

        $closeOut->afterPayment($payment, $request->user(), $data);

        return $invoice->job ? self::toToday($invoice->job) : to_route('jobs.mine');
    }

    private static function toToday(ServiceJob $job): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.close_out.closed', ['number' => $job->number])]);

        return to_route('jobs.mine');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function lineRules(): array
    {
        return [
            'lines' => ['nullable', 'array', 'max:20'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.tax_rate_ids' => ['nullable', 'array', 'max:10'],
            'lines.*.tax_rate_ids.*' => ['integer'],
            'amounts_include_tax' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function emailRules(): array
    {
        return [
            'send_receipt' => ['boolean'],
            'request_review' => ['boolean'],
            'google_profile_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
