<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\GoogleProfile;
use App\Models\Payment;
use App\Payments\PaymentProviderException;
use App\Payments\Square\SquareProvider;
use App\Support\Billing\Money;
use App\Support\Payments\PaymentReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Paid screen (owner's task of November 21) after Charge reader / Cash / E-transfer, and from the "Invoice #N
 * paid" notification of an online payment: amount, way of payment, invoice number, and Send receipt (email only:
 * the customer's email or one typed there, the other details when missing, Request review). Nothing goes before Send.
 */
class PaidController extends Controller
{
    public function show(Payment $payment): Response
    {
        $invoice = $payment->invoice ?? abort(404);
        Gate::authorize('view', $invoice);
        $invoice->loadMissing(['customer.primaryEmail', 'job.brand']);
        $customer = $invoice->customer;
        $company = currentCompany();
        $profiles = GoogleProfile::query()->orderBy('label')->get(['id', 'label', 'review_url']);
        $default = $invoice->job?->brand?->google_profile_id;
        $property = $customer->primaryProperty()->first();
        $phone = $customer->phones()->orderByDesc('is_primary')->orderBy('id')->value('number');

        return Inertia::render('payments/paid', [
            'payment' => [
                'id' => $payment->id,
                'amount' => Money::format($payment->amount, $payment->currency, $company->locale),
                'method' => PaymentReceipt::methodLabel($payment),
                'reference' => $payment->reference,
                'tip' => $payment->tip_amount > 0 ? Money::format($payment->tip_amount, $payment->currency, $company->locale) : null,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'paid' => $invoice->balance <= 0,
                'balance' => Money::format($invoice->balance, $invoice->currency, $company->locale),
                'url' => route('invoices.show', $invoice),
                'job_url' => $invoice->service_job_id ? route('jobs.show', $invoice->service_job_id) : null,
            ],
            'customer' => [
                'walk_in' => $customer->is_walk_in,
                'name' => $customer->display_name,
                'first_name' => $customer->is_walk_in ? '' : (string) $customer->first_name,
                'last_name' => $customer->is_walk_in ? '' : (string) $customer->last_name,
                'phone' => $customer->is_walk_in ? '' : (string) $phone,
                'email' => $customer->is_walk_in ? '' : (string) $customer->primaryEmail?->email,
                'has_address' => ! $customer->is_walk_in && filled($property?->line1),
            ],
            'profiles' => $profiles->map(fn (GoogleProfile $p) => ['id' => $p->id, 'label' => $p->label])->values(),
            'default_profile_id' => match (true) {
                $profiles->contains('id', $default) => $default,
                $profiles->count() === 1 => $profiles->first()->id,
                default => null,
            },
            'send_url' => route('payments.send-receipt', $payment),
        ]);
    }

    public function send(Request $request, Payment $payment, PaymentReceipt $receipt): RedirectResponse
    {
        $invoice = $payment->invoice ?? abort(404);
        Gate::authorize('view', $invoice);
        abort_if($invoice->isVoid(), 422);

        $data = $request->validate([
            'review' => ['boolean'],
            'google_profile_id' => ['nullable', 'integer'],
            ...ChargeController::customerRules(''),
        ]);

        $result = $receipt->send($payment, $data, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('charges.receipt.sent', ['to' => implode(', ', $result['sent'])])]);

        return back();
    }

    /**
     * The Android app authorizes the Square Mobile Payments SDK with these (Charge reader, Pair reader).
     */
    public function readerSession(Request $request, SquareProvider $square): JsonResponse
    {
        Gate::authorize('takeCharges');

        try {
            return response()->json($square->readerSession(currentCompany()))->header('Cache-Control', 'no-store');
        } catch (PaymentProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * The Android app (APK built by GitHub Actions and placed on the server).
     */
    public function androidApk(): HttpResponse|BinaryFileResponse
    {
        $path = (string) config('payments.android_apk_path');
        abort_unless(is_file($path), 404);

        return response()->download($path, 'android.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
