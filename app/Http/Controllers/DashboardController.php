<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Membership;
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
        ]);
    }
}
