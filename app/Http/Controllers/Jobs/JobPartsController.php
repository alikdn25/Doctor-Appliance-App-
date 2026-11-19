<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\JobStatus;
use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Messaging\Messenger;
use App\Models\ServiceJob;
use App\Support\Jobs\FreeWindows;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The part a job waits for (owner's task of November 18): part name, number, supplier and order date (with the AI's
 * possible number marked "check"), and "Part arrived": a text to the customer with the three nearest free arrival
 * windows, sent only after Confirm. The customer's answer comes into the SMS chat, where the AI reads it.
 */
class JobPartsController extends Controller
{
    public const WINDOWS = 3;

    public function update(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('work', $job);
        $data = $request->validate([
            'part_name' => ['nullable', 'string', 'max:255'],
            'part_number' => ['nullable', 'string', 'max:100'],
            'part_supplier' => ['nullable', 'string', 'max:255'],
            'part_ordered_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $job->forceFill(array_map(fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null, $data))->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.parts.saved')]);

        return back();
    }

    /**
     * The "Part arrived" text, ready to check (nothing is sent).
     */
    public function arrived(ServiceJob $job): JsonResponse
    {
        Gate::authorize('update', $job);
        abort_unless(in_array($job->status, [JobStatus::WaitingForParts, JobStatus::PartsReady], true), 404);

        return response()->json(['text' => self::text($job), 'windows' => self::windows()]);
    }

    /**
     * Confirm: the text goes to the customer.
     */
    public function sendArrived(Request $request, ServiceJob $job, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('update', $job);
        abort_unless(in_array($job->status, [JobStatus::WaitingForParts, JobStatus::PartsReady], true), 404);
        $body = trim($request->validate(['text' => ['required', 'string', 'max:1600']])['text']);

        if (currentCompany()->sms_mode !== SmsMode::Automatic) {
            throw ValidationException::withMessages(['text' => __('messages.inbox.automatic_required')]);
        }

        $message = $messenger->sms(MessageKind::General, $job->customer, $job, $body, $request->user());

        return JobMessageController::result($message->status, $message->status_reason, $message->send_after);
    }

    /**
     * The three nearest free arrival windows from tomorrow (company time zone), up to two weeks ahead.
     *
     * @return list<array{date: string, start: string, end: string, label: string}>
     */
    public static function windows(): array
    {
        $company = currentCompany();
        $day = CarbonImmutable::now($company->timezone)->startOfDay();
        $found = [];

        for ($i = 1; $i <= 14 && count($found) < self::WINDOWS; $i++) {
            $date = $day->addDays($i);
            $hours = ($company->business_hours ?? $company::defaultBusinessHours())[strtolower($date->format('D'))] ?? null;
            if (is_array($hours) && ($hours['closed'] ?? false)) {
                continue;
            }

            foreach (FreeWindows::on($company, $date->toDateString()) as $window) {
                $start = $date->setTimeFromTimeString($window['start']);
                $found[] = [
                    'date' => $date->toDateString(),
                    'start' => $window['start'],
                    'end' => $window['end'],
                    'label' => MessageContext::date($start).', '.MessageContext::time($start).' - '.MessageContext::time($date->setTimeFromTimeString($window['end'])),
                ];

                if (count($found) === self::WINDOWS) {
                    break;
                }
            }
        }

        return $found;
    }

    private static function text(ServiceJob $job): string
    {
        $job->loadMissing(['customer', 'brand']);
        $windows = array_map(fn (array $w, int $i) => ($i + 1).') '.$w['label'], self::windows(), array_keys(self::windows()));

        return __('automation.parts.arrived_text', [
            'name' => $job->customer?->first_name ?: $job->customer?->display_name,
            'brand' => $job->brand?->name ?? currentCompany()->name,
            'windows' => $windows === [] ? __('automation.parts.no_windows') : implode("\n", $windows),
        ]);
    }
}
