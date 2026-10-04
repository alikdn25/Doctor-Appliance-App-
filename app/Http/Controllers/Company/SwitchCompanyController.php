<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Impersonation;
use App\Support\WorkHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchCompanyController extends Controller
{
    public function __invoke(Request $request, Company $company, Impersonation $impersonation): RedirectResponse
    {
        abort_if($impersonation->isActive(), 403);

        $user = $request->user();

        abort_unless($user->accessibleCompanies()->contains('id', $company->id), 403);

        $user->forceFill(['current_company_id' => $company->id])->save();

        return redirect(WorkHome::url($user, $company));
    }
}
