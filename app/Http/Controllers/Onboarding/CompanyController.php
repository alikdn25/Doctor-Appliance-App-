<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Brands\SaveBrand;
use App\Actions\Companies\CreateCompany;
use App\Enums\Vertical;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CompanyRequest;
use App\Models\User;
use App\Support\Locale\Countries;
use App\Support\Locale\Currencies;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Locale\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->memberships()->exists()) {
            return to_route('dashboard');
        }

        $country = (string) config('fieldservice.default_country');

        return Inertia::render('onboarding/company', [
            'countries' => Countries::options(),
            'currencies' => Currencies::options(),
            'locales' => Countries::localeOptions(),
            'verticals' => Vertical::options(),
            'timezones' => Timezones::options(),
            'countryDefaults' => collect(Countries::codes())->mapWithKeys(fn (string $code) => [$code => [
                'currency' => Countries::currency($code),
                'locale' => Countries::locale($code),
                'timezone' => Countries::timezone($code),
            ]])->all(),
            'defaults' => [
                'country' => $country,
                'currency' => Countries::currency($country),
                'locale' => Countries::locale($country),
                'timezone' => Countries::timezone($country),
                'vertical' => Vertical::ApplianceRepair->value,
            ],
        ]);
    }

    public function store(CompanyRequest $request, CreateCompany $createCompany, CurrentCompany $currentCompany, SaveBrand $saveBrand): RedirectResponse
    {
        DB::transaction(function () use ($request, $createCompany, $currentCompany, $saveBrand) {
            // Serialize setup for this account; repeated or concurrent submissions cannot create two companies.
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->hasVerifiedEmail(), 403);
            if ($user->memberships()->exists()) {
                return;
            }

            $company = $createCompany->handle($request->validated(), ['name' => $user->name, 'email' => $user->email]);
            $currentCompany->runAs($company, fn () => $saveBrand->handle(null, [
                'name' => $company->name,
                'email' => $user->email,
                'sender_name' => $company->name,
            ], []));
            $user->forceFill(['current_company_id' => $company->id])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('onboarding.ready')]);

        return to_route('dashboard');
    }
}
