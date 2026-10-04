<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\ReviseEstimate;
use App\Actions\Billing\SaveBillingDocument;
use App\Enums\EstimateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Messaging\MessagingPresenter;
use App\Models\Estimate;
use App\Models\ServiceJob;
use App\Payments\PaymentProviders;
use App\Support\Billing\BillingPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EstimateController extends Controller
{
    public function create(ServiceJob $job): Response
    {
        Gate::authorize('estimate', $job);

        $days = currentCompany()->estimate_valid_days;

        return Inertia::render('billing/form', [
            'kind' => 'estimate',
            'document' => null,
            'job' => BillingPresenter::job($job),
            'taxRates' => BillingPresenter::taxOptions(),
            'services' => BillingPresenter::serviceOptions($job->brand_id),
            'lineSetup' => BillingPresenter::lineSetup(),
            'today' => $this->today(),
            'defaultValidUntil' => $days ? CarbonImmutable::parse($this->today())->addDays($days)->toDateString() : null,
            'canTakeDeposit' => app(PaymentProviders::class)->readyFor(currentCompany()) !== null,
        ]);
    }

    public function store(DocumentRequest $request, ServiceJob $job, SaveBillingDocument $save): RedirectResponse
    {
        Gate::authorize('estimate', $job);

        $estimate = $save->createEstimate($job, $request->document(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('estimates.created', ['number' => $estimate->number])]);

        return to_route('estimates.show', $estimate);
    }

    public function show(Estimate $estimate): Response
    {
        Gate::authorize('view', $estimate);

        return Inertia::render('billing/show', [
            'document' => BillingPresenter::document($estimate),
            'can' => [
                'update' => Gate::allows('update', $estimate),
                'delete' => Gate::allows('delete', $estimate),
                'convert' => Gate::allows('convert', $estimate),
                'revise' => Gate::allows('revise', $estimate),
            ],
            'today' => $this->today(),
            'delivery' => BillingPresenter::delivery($estimate),
            'sms' => app(MessagingPresenter::class)->forDocument($estimate),
        ]);
    }

    public function edit(Estimate $estimate): Response
    {
        Gate::authorize('update', $estimate);

        return Inertia::render('billing/form', [
            'kind' => 'estimate',
            'document' => BillingPresenter::document($estimate),
            'job' => BillingPresenter::job($estimate->job),
            'taxRates' => BillingPresenter::taxOptions($estimate->taxes),
            'services' => BillingPresenter::serviceOptions($estimate->brand_id),
            'lineSetup' => BillingPresenter::lineSetup(),
            'today' => $this->today(),
            'canTakeDeposit' => app(PaymentProviders::class)->readyFor(currentCompany()) !== null,
        ]);
    }

    public function update(DocumentRequest $request, Estimate $estimate, SaveBillingDocument $save): RedirectResponse
    {
        Gate::authorize('update', $estimate);

        $save->update($estimate, $request->document(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('estimates.updated')]);

        return to_route('estimates.show', $estimate);
    }

    public function destroy(Estimate $estimate): RedirectResponse
    {
        Gate::authorize('delete', $estimate);

        $estimate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('estimates.deleted')]);

        return to_route('jobs.show', $estimate->service_job_id);
    }

    /**
     * The customer agreed (on site or by phone) or said no. Online approval is on the customer's page.
     */
    public function decide(Request $request, Estimate $estimate): RedirectResponse
    {
        Gate::authorize('update', $estimate);

        $approved = $request->validate(['approved' => ['required', 'boolean']])['approved'];

        $estimate->forceFill([
            'status' => $approved ? EstimateStatus::Approved : EstimateStatus::Declined,
            'approved_at' => $approved ? now() : null,
            'declined_at' => $approved ? null : now(),
        ])->save();

        return back();
    }

    public function revise(Request $request, Estimate $estimate, ReviseEstimate $revise): RedirectResponse
    {
        Gate::authorize('revise', $estimate);

        $revision = $revise->handle($estimate, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('estimates.revised', ['number' => $revision->number])]);

        return to_route('estimates.edit', $revision);
    }

    public function convert(Request $request, Estimate $estimate, SaveBillingDocument $save): RedirectResponse
    {
        Gate::authorize('convert', $estimate);

        $invoice = $save->convertEstimate($estimate, $request->user(), $this->today());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoices.created', ['number' => $invoice->number])]);

        return to_route('invoices.show', $invoice);
    }

    private function today(): string
    {
        return CarbonImmutable::now(currentCompany()->timezone)->format('Y-m-d');
    }
}
