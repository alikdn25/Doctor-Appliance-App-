<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\Customer;
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
        $result = $parser->parse($text, currentCompany(), $request->user());
        $phone = $result['fields']['phone'];

        // CustomerPhone is tenant-scoped: only this company's customers are found.
        $known = $phone === null ? null : CustomerPhone::query()
            ->where('number_normalized', $phone)
            ->whereHas('customer')
            ->with('customer')
            ->first()?->customer;

        // A saved customer with this phone but another name (e.g. a tenant calling from the landlord's number) is not
        // picked silently: the pasted person is filled in as new and the saved one is offered.
        $sameName = $known === null || self::sameName($result['fields'], $known);

        return response()->json([
            ...$result,
            'customer' => $known !== null && $sameName ? JobController::customerOption($known) : null,
            'phone_customer' => $known !== null && ! $sameName ? JobController::customerOption($known) : null,
        ]);
    }

    /**
     * True when no name was pasted or the pasted name is the saved customer's (first name, full name or company).
     *
     * @param  array<string, mixed>  $fields
     */
    private static function sameName(array $fields, Customer $customer): bool
    {
        $clean = fn (?string $value) => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $value)));
        $pasted = $clean(trim(($fields['first_name'] ?? '').' '.($fields['last_name'] ?? '')));
        $company = $clean($fields['company_name'] ?? null);

        if ($pasted === '' && $company === '') {
            return true;
        }

        $saved = array_filter([
            $clean(trim($customer->first_name.' '.$customer->last_name)),
            $clean($customer->company_name),
            $clean($customer->display_name),
        ]);
        $savedFirst = $clean($customer->first_name);

        foreach (array_filter([$pasted, $company]) as $name) {
            if (in_array($name, $saved, true) || ($fields['last_name'] ?? null) === null && $name === $savedFirst) {
                return true;
            }
        }

        return false;
    }
}
