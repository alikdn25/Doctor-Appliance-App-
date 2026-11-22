<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Messaging\ReviewRequests;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * "Send Google Review request?" after an invoice is sent or paid (SPEC §8): the review link of the chosen location
 * goes to the customer by email only (owner's task of November 22), with the text as edited for this customer and the
 * review block with the company's picture. An email typed here is kept on the customer. Everyone who can send the
 * invoice can send it.
 */
class InvoiceReviewRequestController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, ReviewRequests $reviews): RedirectResponse
    {
        Gate::authorize('view', $invoice);
        abort_if($invoice->isVoid(), 422);

        $data = $request->validate([
            'google_profile_id' => ['required', 'integer', Rule::exists('google_profiles', 'id')->where('company_id', currentCompany()->id)],
            'email' => ['required', 'email', 'max:255'],
            'text' => ['nullable', 'string', 'max:1000'],
        ], [], ['google_profile_id' => __('reviews.prompt.location'), 'email' => __('reviews.prompt.email'), 'text' => __('reviews.prompt.text')]);

        $reviews->send($invoice, GoogleProfile::query()->findOrFail($data['google_profile_id']), $data['email'], $request->user(), $data['text'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('reviews.prompt.sent', ['email' => $data['email']])]);

        return back();
    }
}
