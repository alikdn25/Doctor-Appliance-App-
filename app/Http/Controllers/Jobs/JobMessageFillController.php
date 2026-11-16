<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Customers\SaveProperty;
use App\Http\Controllers\Controller;
use App\Models\CustomerPhone;
use App\Models\ServiceJob;
use App\Support\Ai\BookingTextParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Paste from message" on the job page (a job booked with missing data): the text is read like on Book customer and
 * only the empty fields are filled: the customer's name, phone and email, the job's address and the problem. Nothing
 * already filled in is changed.
 */
class JobMessageFillController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job, BookingTextParser $parser, SaveProperty $save): RedirectResponse
    {
        Gate::authorize('work', $job);

        $text = $request->validate(['text' => ['required', 'string', 'max:5000']])['text'];
        $fields = $parser->parse($text, currentCompany(), $request->user())['fields'];
        $customer = $job->customer()->firstOrFail();
        $filled = [];

        DB::transaction(function () use ($job, $customer, $fields, $save, &$filled) {
            if (blank($customer->first_name) && blank($customer->last_name) && blank($customer->company_name)
                && (filled($fields['first_name']) || filled($fields['last_name']) || filled($fields['company_name']))) {
                $customer->fill([
                    'first_name' => $fields['first_name'],
                    'last_name' => $fields['last_name'],
                    'company_name' => $fields['company_name'],
                    'type' => filled($fields['company_name']) && blank($fields['first_name']) ? 'commercial' : $customer->type,
                ])->save();
                $filled[] = 'name';
            }

            if (filled($fields['phone']) && ! $customer->phones()->exists()) {
                $customer->phones()->save(new CustomerPhone(['label' => 'mobile', 'number' => $fields['phone'], 'is_primary' => true]));
                $filled[] = 'phone';
            }

            if (filled($fields['email']) && ! $customer->emails()->exists()) {
                $customer->emails()->create(['label' => 'personal', 'email' => $fields['email'], 'is_primary' => true]);
                $filled[] = 'email';
            }

            $address = $fields['job_address'];
            $current = $job->property;
            if (is_array($address) && filled($address['line1']) && ($current === null || ! $current->hasAddress())) {
                $property = $save->handle($customer, $current, [
                    'line1' => $address['line1'],
                    'unit' => $address['unit'],
                    'city' => $address['city'],
                    'region' => $address['region'],
                    'postal_code' => $address['postal_code'],
                    'country' => $current->country ?? currentCompany()->country,
                ]);
                $job->forceFill(['property_id' => $property->id])->save();
                $filled[] = 'address';
            }

            if (filled($fields['problem']) && blank($job->description)) {
                $job->forceFill(['description' => $fields['problem']])->save();
                $filled[] = 'problem';
            }
        });

        Inertia::flash('toast', $filled === []
            ? ['type' => 'info', 'message' => __('jobs.fill.nothing')]
            : ['type' => 'success', 'message' => __('jobs.fill.done')]);

        return back();
    }
}
