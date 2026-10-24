<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\CustomerPhone;
use App\Models\ServiceJob;
use App\Support\Ai\BookingTextParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Paste from message" on Book customer: the pasted text split into the form's fields, and the customer from the
 * book when the payer's phone is already known.
 */
class BookingTextController extends Controller
{
    public function __invoke(Request $request, BookingTextParser $parser): JsonResponse
    {
        Gate::authorize('create', ServiceJob::class);

        $text = $request->validate(['text' => ['required', 'string', 'max:5000']])['text'];
        $result = $parser->parse($text, currentCompany());
        $phone = $result['fields']['phone'];

        // CustomerPhone is tenant-scoped: only this company's customers are found.
        $known = $phone === null ? null : CustomerPhone::query()
            ->where('number_normalized', $phone)
            ->whereHas('customer')
            ->with('customer')
            ->first()?->customer;

        return response()->json([
            ...$result,
            'customer' => $known === null ? null : JobController::customerOption($known),
        ]);
    }
}
