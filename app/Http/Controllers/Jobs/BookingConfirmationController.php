<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\ChangeJobStatus;
use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Messaging\BookingConfirmation;
use App\Models\ServiceJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The booking confirmation (owner's task, Oct 24): "Finish booking" on a draft, "Resend confirmation" in the job's
 * "…" menu and "Notify client?" after a finished booking moves. It goes by itself, as the company's SMS setting says.
 */
class BookingConfirmationController extends Controller
{
    /** What would be sent and how: the text and the plan (channel, where to, why not the usual way). */
    public function show(ServiceJob $job, BookingConfirmation $confirmation): JsonResponse
    {
        Gate::authorize('work', $job);
        $job->loadMissing(['customer', 'brand', 'property']);

        return response()->json(['body' => $confirmation->body($job), ...$confirmation->plan($job)]);
    }

    /** Resend confirmation / Notify client: sent now by the plan (a text from the technician's phone is the page's). */
    public function send(Request $request, ServiceJob $job, BookingConfirmation $confirmation): RedirectResponse
    {
        Gate::authorize('work', $job);

        if ($job->status === JobStatus::Draft) {
            throw ValidationException::withMessages(['job' => __('jobs.confirmation.not_finished')]);
        }

        self::flashResult($confirmation->send($job, $request->user()));

        return back();
    }

    /** "Finish booking" on a draft: Scheduled, and the confirmation goes by itself. */
    public function finish(Request $request, ServiceJob $job, ChangeJobStatus $changeStatus, BookingConfirmation $confirmation): RedirectResponse
    {
        Gate::authorize('update', $job);

        if ($job->status !== JobStatus::Draft) {
            return back();
        }

        DB::transaction(function () use ($job, $changeStatus, $request) {
            $open = $job->visits()->whereIn('status', VisitStatus::openValues())->exists();
            $changeStatus->handle($job, $open ? JobStatus::Scheduled : JobStatus::New, $request->user());
        });

        $visit = $job->visits()->orderBy('scheduled_start')->first();
        $sent = $confirmation->send($job, $request->user());

        if ($visit !== null) {
            JobController::flashBooked($job, $visit, $sent);
        } else {
            self::flashResult($sent);
        }

        return back();
    }

    /**
     * A toast saying how the confirmation went. A text from the technician's phone is sent by the page itself (it
     * opens the messages app within the tap), so it needs no toast.
     *
     * @param  array<string, mixed>  $sent
     */
    public static function flashResult(array $sent): void
    {
        if ($sent['channel'] === BookingConfirmation::PHONE) {
            return;
        }

        Inertia::flash('toast', match (true) {
            $sent['channel'] === null || $sent['status'] === 'blocked' => ['type' => 'error', 'message' => __('jobs.confirmation.not_sent', ['reason' => $sent['reason']])],
            $sent['channel'] === BookingConfirmation::EMAIL => ['type' => 'success', 'message' => __('jobs.confirmation.emailed', ['email' => $sent['to']])],
            $sent['send_after'] !== null => ['type' => 'success', 'message' => __('messages.sms_queued', ['time' => $sent['send_after']])],
            default => ['type' => 'success', 'message' => __('jobs.confirmation.texted')],
        });
    }
}
