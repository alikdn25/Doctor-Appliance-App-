<?php

namespace App\Http\Controllers\Company;

use App\Enums\JobOutcome;
use App\Enums\PaymentTerms;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanySettingsRequest;
use App\Models\Service;
use App\Payments\PaymentProvider;
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
                'invoice_prefix', 'invoice_next_number', 'online_tips',
                'estimate_prefix', 'estimate_next_number', 'travel_buffer_minutes', 'payment_provider',
                'estimate_valid_days', 'technicians_can_delete_jobs', 'diagnostic_service_id', 'strict_arrival_reminder_minutes',
            ]) + [
                // Reasons per outcome as edited (one per line); defaults shown when the company has none.
                'closure_reasons' => collect(JobOutcome::cases())
                    ->filter(fn (JobOutcome $o) => $o->needsReason())
                    ->mapWithKeys(fn (JobOutcome $o) => [$o->value => $company->closureReasons($o)])
                    ->all(),
                'business_hours' => $company->business_hours ?? $company::defaultBusinessHours(),
                'default_payment_terms' => $company->default_payment_terms->value,
                'vertical' => $company->vertical->label(),
            ],
            'paymentProviders' => $providers->options($company),
            // Providers the company can connect in its country (Square: US, CA, UK, IE, AU, JP, FR, ES).
            'providerConnections' => array_values(array_map(fn (PaymentProvider $provider) => [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'connected' => $provider->connectionSummary($company),
            ], $providers->availableFor($company))),
            'timezones' => DateTimeZone::listIdentifiers(),
            'currencies' => Currencies::options(),
            'countries' => Countries::options(),
            'locales' => Countries::localeOptions(),
            'paymentTerms' => PaymentTerms::options(),
            'services' => Service::query()->orderBy('name')->get()
                ->map(fn (Service $service) => ['value' => (string) $service->id, 'label' => $service->name])->values(),
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
