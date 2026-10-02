<?php

namespace App\Http\Controllers\Company;

use App\Enums\PaymentTerms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanySettingsRequest;
use App\Payments\PaymentProviders;
use App\Services\AuditLogger;
use App\Support\Locale\Countries;
use App\Support\Locale\Currencies;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingsController extends Controller
{
    public function edit(PaymentProviders $providers): Response
    {
        $company = currentCompany();

        Gate::authorize('update', $company);

        return Inertia::render('company/settings', [
            'company' => $company->only([
                'id', 'name', 'country', 'timezone', 'currency', 'locale', 'prices_include_tax',
                'invoice_prefix', 'invoice_next_number',
                'estimate_prefix', 'estimate_next_number', 'travel_buffer_minutes', 'payment_provider',
            ]) + [
                'business_hours' => $company->business_hours ?? $company::defaultBusinessHours(),
                'default_payment_terms' => $company->default_payment_terms->value,
                'vertical' => $company->vertical->label(),
            ],
            'paymentProviders' => $providers->options(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'currencies' => Currencies::options(),
            'countries' => Countries::options(),
            'locales' => Countries::localeOptions(),
            'paymentTerms' => PaymentTerms::options(),
        ]);
    }

    public function update(CompanySettingsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        $company->fill($request->settings());
        $changes = $company->getDirty();
        $company->save();

        if ($changes !== []) {
            $audit->record('company.settings_updated', $company, ['fields' => array_keys($changes)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('company.saved')]);

        return to_route('company.settings.edit');
    }
}
