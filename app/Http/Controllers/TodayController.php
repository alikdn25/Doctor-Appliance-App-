<?php

namespace App\Http\Controllers;

use App\Support\Jobs\MorningSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Today" (owner's task of November 18): the morning summary as a screen — the day's jobs in order, jobs without an
 * address, unanswered texts, unpaid invoices and parts to receive.
 */
class TodayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('today', ['summary' => MorningSummary::for($request->user())]);
    }
}
