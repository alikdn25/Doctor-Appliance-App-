<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\GoogleProfile;
use App\Models\Membership;
use App\Models\TaxRate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $company = currentCompany();

        return Inertia::render('dashboard', [
            'stats' => [
                'brands' => Brand::query()->where('is_active', true)->count(),
                'members' => Membership::query()->where('is_active', true)->count(),
            ],
            'companyName' => $company->name,
            // Settings still missing that change what customers get (taxes on invoices, review requests).
            'setup' => [
                'taxes' => TaxRate::query()->exists(),
                'reviewProfiles' => GoogleProfile::query()->exists(),
                'reviewsOn' => (bool) $company->review_requests_default,
            ],
        ]);
    }
}
