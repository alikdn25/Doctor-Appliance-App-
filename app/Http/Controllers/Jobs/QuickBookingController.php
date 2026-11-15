<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Services\AI\AiLimitReached;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\QuickBookingParser;
use App\Support\Customers\CustomerFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quick booking (Owner and Office): one big text field (typed, dictated with the phone keyboard's microphone, or an
 * SMS pasted in) and Parse. The AI reads the text into the booking form's fields and a customer already in the book is
 * found by phone, then by name and address. Nothing is saved here: the filled Book customer form opens and the person
 * presses Save.
 */
class QuickBookingController extends Controller
{
    public function show(): Response
    {
        Gate::authorize('useQuickBooking');

        return Inertia::render('jobs/quick-booking');
    }

    public function parse(Request $request, QuickBookingParser $parser, CustomerFinder $finder): JsonResponse
    {
        Gate::authorize('useQuickBooking');

        $text = $request->validate(['text' => ['required', 'string', 'max:5000']])['text'];

        if (! app(AiService::class)->configured()) {
            return response()->json(['message' => __('jobs.quick_booking.not_set_up')], 503);
        }

        try {
            $fields = $parser->parse($text, currentCompany(), $request->user());
        } catch (AiLimitReached) {
            return response()->json(['message' => __('jobs.quick_booking.limit')], 429);
        } catch (AiUnavailable) {
            return response()->json(['message' => __('jobs.quick_booking.failed')], 503);
        }

        $found = $finder->find($fields['phone'], $fields['first_name'], $fields['last_name'], $fields['address']['line1'] ?? null);

        return response()->json([
            'fields' => $fields,
            'customer' => $found === null ? null : JobController::customerOption($found['customer']),
            'matched_by' => $found['by'] ?? null,
        ]);
    }
}
