<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\GoogleProfile;
use App\Models\Scopes\CompanyScope;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;

/**
 * /r/{token}/{location}: the review link in invoice, receipt and review emails. The first click is kept on the job
 * ("Review link clicked"; no reminder is sent after it), then the customer goes to the location's Google review page.
 * People signed in to the app are not counted.
 */
class ReviewLinkController extends Controller
{
    public function __invoke(string $token, int $profile): RedirectResponse
    {
        abort_if(strlen($token) !== 40, 404);
        $job = ServiceJob::query()->withoutGlobalScope(CompanyScope::class)->withTrashed()->where('review_token', $token)->first() ?? abort(404);
        $company = Company::query()->find($job->company_id);
        abort_if($company === null || ! $company->isActive(), 404);

        $location = GoogleProfile::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->whereKey($profile)->first();
        $url = $location?->review_url;
        abort_if(blank($url), 404);

        if (auth()->guest() && $job->review_clicked_at === null) {
            ServiceJob::query()->withoutGlobalScope(CompanyScope::class)->withTrashed()->whereKey($job->id)
                ->whereNull('review_clicked_at')->update(['review_clicked_at' => now()]);
        }

        return redirect()->away($url);
    }
}
