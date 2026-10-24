<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Customers\SaveProperty;
use App\Actions\Jobs\AssignJob;
use App\Actions\Jobs\SaveVisit;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Jobs\VisitRequest;
use App\Messaging\BookingConfirmation;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The pencil on the job page's customer card (owner's update, October 24): customer name, address, date, arrival
 * window and technician in one sheet, with "Notify client". The updated confirmation goes only when that box is
 * ticked; otherwise nothing is sent.
 */
class JobDetailsController extends Controller
{
    public function __invoke(Request $request, ServiceJob $job, SaveVisit $saveVisit, AssignJob $assign, SaveProperty $saveProperty, BookingConfirmation $confirmation): RedirectResponse
    {
        Gate::authorize('update', $job);
        $customer = $job->customer()->firstOrFail();

        $data = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Absent = unchanged; property_id = another address of the customer; "new" = the address fields.
            'address' => ['nullable', 'in:new'],
            'property_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'required_with:start_time,end_time', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'required_with:date', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:date', 'date_format:H:i', 'after:start_time'],
            'technician_id' => ['sometimes', 'nullable', 'integer'],
            'notify' => ['boolean'],
        ], [], VisitRequest::visitAttributes());

        $renaming = $request->hasAny(['first_name', 'last_name', 'company_name']);
        if ($renaming) {
            Gate::authorize('update', $customer);
        }
        $reassigning = $request->has('technician_id');
        if ($reassigning) {
            Gate::authorize('dispatch', ServiceJob::class);
        }

        DB::transaction(function () use ($request, $data, $job, $customer, $renaming, $reassigning, $saveVisit, $assign, $saveProperty) {
            if ($renaming) {
                foreach (['first_name', 'last_name', 'company_name'] as $field) {
                    if ($request->has($field)) {
                        $customer->{$field} = filled($data[$field] ?? null) ? trim($data[$field]) : null;
                    }
                }
                // Every customer keeps a name: a person's first or last name, or a business name.
                if (blank($customer->first_name) && blank($customer->last_name) && blank($customer->company_name)) {
                    throw ValidationException::withMessages(['first_name' => __('jobs.details.name_required')]);
                }
                $customer->save();
            }

            if (filled($data['property_id'] ?? null) && (int) $data['property_id'] !== $job->property_id) {
                JobAddressController::change($job, ['property_id' => $data['property_id']], $saveProperty);
            } elseif (($data['address'] ?? null) === 'new') {
                JobAddressController::change($job, $request->except(['property_id']), $saveProperty);
            }

            if (filled($data['date'] ?? null)) {
                $visit = self::currentVisit($job);
                $time = VisitRequest::toVisit([
                    'date' => $data['date'],
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'estimated_duration_minutes' => $visit?->estimated_duration_minutes,
                    'strict_arrival' => $visit?->strict_arrival ?? false,
                ])['attributes'];
                $moved = $visit === null
                    || ! $visit->scheduled_start->equalTo($time['scheduled_start'])
                    || ! $visit->scheduled_end->equalTo($time['scheduled_end']);

                if ($moved) {
                    if ($visit === null && $job->status->isLocked()) {
                        throw ValidationException::withMessages(['date' => __('jobs.details.locked')]);
                    }
                    $ids = $visit?->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all()
                        ?? $job->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
                    $saveVisit->handle($job, $visit, $time, $ids, $request->user());
                }
            }

            if ($reassigning) {
                $to = isset($data['technician_id']) ? (int) $data['technician_id'] : null;
                $visit = self::currentVisit($job->refresh());
                $now = ($visit ? $visit->assignees() : $job->assignees())->pluck('users.id')->map(fn ($id) => (int) $id)->all();
                if ($now !== ($to === null ? [] : [$to])) {
                    $assign->assign($job, $to, $request->user());
                }
            }
        });

        $job->refresh();
        if ($request->boolean('notify') && $job->status !== JobStatus::Draft) {
            $sent = $confirmation->send($job, $request->user());
            $visit = self::currentVisit($job);
            // From the technician's phone the "Booked …" sheet opens the messages app with the text ready.
            if ($sent['channel'] === BookingConfirmation::PHONE && $visit !== null) {
                JobController::flashBooked($job, $visit, $sent);
            } else {
                BookingConfirmationController::flashResult($sent);
            }
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('jobs.details.saved')]);
        }

        return back();
    }

    /** The time the job is booked for now: the latest visit not finished or cancelled. */
    private static function currentVisit(ServiceJob $job): ?JobVisit
    {
        return $job->visits()->whereIn('status', VisitStatus::openValues())
            ->orderByDesc('scheduled_start')->orderByDesc('id')->first();
    }
}
