<?php

namespace App\Services\AI\Sms;

use App\Actions\Customers\SaveAppliance;
use App\Actions\Jobs\CloseJob;
use App\Actions\Jobs\DirectBooking;
use App\Actions\Jobs\SaveVisit;
use App\Enums\ApplianceType;
use App\Enums\JobOutcome;
use App\Http\Controllers\Jobs\JobController;
use App\Messaging\BookingConfirmation;
use App\Models\Appliance;
use App\Models\Customer;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AI\AiUnavailable;
use App\Services\AI\Assistant\AssistantActions;
use App\Services\AI\QuickBookingParser;
use App\Services\AuditLogger;
use App\Support\Audit\ActionSource;
use App\Support\Customers\CustomerFinder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The actions the AI proposed under an incoming text: a preview (what will change) in the chat card, and the change
 * itself only on Confirm, as the person who pressed it, marked "AI assistant" in the change log. Dismiss drops a
 * proposal. Nothing is ever deleted. Must run in a tenant context.
 */
class SmsActions
{
    /**
     * The card under an incoming message: urgency, previews of the actions, the plate, the reply draft.
     *
     * @return array<string, mixed>|null
     */
    public static function card(Message $message): ?array
    {
        $triage = $message->ai_triage;

        if (! is_array($triage)) {
            return null;
        }

        $actions = [];
        foreach ((array) ($triage['actions'] ?? []) as $index => $action) {
            $actions[] = ['index' => $index, 'type' => $action['type'], 'status' => $action['status'] ?? 'pending', 'preview' => self::preview($action)];
        }

        $plate = $triage['plate'] ?? null;

        return [
            'urgent' => ($triage['urgency'] ?? null) === 'urgent',
            'actions' => $actions,
            'reply_draft' => $triage['reply_draft'] ?? null,
            'plate' => is_array($plate) ? [
                ...$plate,
                'preview' => __('automation.inbox.plate_preview', [
                    'brand' => $plate['brand'] ?? '—', 'model' => $plate['model'] ?? '—', 'serial' => $plate['serial'] ?? '—',
                    'job' => $plate['job_number'] ? '#'.$plate['job_number'] : __('automation.inbox.no_open_job'),
                ]),
            ] : null,
        ];
    }

    /**
     * What Confirm will do, in words.
     *
     * @param  array<string, mixed>  $action
     */
    public static function preview(array $action): string
    {
        $company = currentCompany();

        if ($action['type'] === 'book') {
            return __('automation.inbox.preview_book');
        }

        $job = ServiceJob::query()->with('customer')->find($action['job_id']);
        $number = $job ? '#'.$job->number : '';

        if ($action['type'] === 'cancel') {
            return __('automation.inbox.preview_cancel', ['job' => $number, 'customer' => $job?->customer?->display_name]);
        }

        $visit = JobVisit::query()->find($action['visit_id'] ?? 0);
        if ($visit === null) {
            return __('automation.inbox.preview_gone');
        }

        [$start, $end] = self::newTime($action, $visit);
        $from = CarbonImmutable::instance($visit->scheduled_start)->setTimezone($company->timezone);

        return __('automation.inbox.preview_move', [
            'job' => $number,
            'from' => $from->isoFormat('ddd MMM D').', '.$from->format('H:i').'–'.CarbonImmutable::instance($visit->scheduled_end)->setTimezone($company->timezone)->format('H:i'),
            'to' => $start->isoFormat('ddd MMM D').', '.$start->format('H:i').'–'.$end->format('H:i'),
        ]);
    }

    /**
     * The new start and end of a move: the given day and window, the visit's own day or time for what was not
     * given, "Now" = the next quarter hour today. Company time zone.
     *
     * @param  array<string, mixed>  $action
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function newTime(array $action, JobVisit $visit): array
    {
        $tz = currentCompany()->timezone;
        $current = CarbonImmutable::instance($visit->scheduled_start)->setTimezone($tz);
        $length = max(15, (int) $visit->scheduled_start->diffInMinutes($visit->scheduled_end));

        if (($action['now'] ?? false) === true) {
            $now = CarbonImmutable::now($tz);
            $start = $now->setTime($now->hour, intdiv($now->minute, 15) * 15)->addMinutes(15);

            return [$start, $start->addMinutes($length)];
        }

        $date = $action['date'] ?? $current->toDateString();
        $time = $action['start'] ?? $current->format('H:i');
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $tz) ?? $current;
        if (! empty($action['start']) && ! empty($action['end'])) {
            $end = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$action['end']}", $tz);
            $length = $end && $end->greaterThan($start) ? (int) $start->diffInMinutes($end) : $length;
        }

        return [$start, $start->addMinutes($length)];
    }

    /**
     * Confirm: runs the action. A booking returns the filled Book customer form (saved only by the person's Save).
     *
     * @return array{message: string, booking?: array<string, mixed>}
     */
    public function confirm(Message $message, int $index, User $user): array
    {
        $triage = (array) $message->ai_triage;
        $action = $triage['actions'][$index] ?? null;

        if (! is_array($action) || ($action['status'] ?? 'pending') !== 'pending') {
            throw ValidationException::withMessages(['action' => __('automation.inbox.already_decided')]);
        }

        $result = app(ActionSource::class)->as(AssistantActions::SOURCE, fn () => match ($action['type']) {
            'book' => $this->book($action, $user, (string) $message->from),
            'cancel' => $this->cancel($action, $user),
            default => $this->move($action, $user),
        });

        $triage['actions'][$index]['status'] = 'done';
        $message->forceFill(['ai_triage' => $triage])->save();
        if ($action['type'] !== 'book') {
            app(AuditLogger::class)->record('assistant.sms_'.$action['type'], $message, ['label' => self::preview($action)]);
        }

        return $result;
    }

    /**
     * The action a button on a push notification does at once (owner's task of November 19): the first pending move,
     * time change or cancel, or a booking for a known customer with an address, a day and a window. The words say
     * exactly what the tap will do.
     *
     * @return array{index: int, title: string, does: string}|null
     */
    public static function main(Message $message): ?array
    {
        foreach ((array) ($message->ai_triage['actions'] ?? []) as $index => $action) {
            if (! is_array($action) || ($action['status'] ?? 'pending') !== 'pending') {
                continue;
            }

            if ($action['type'] === 'book') {
                $plan = self::directPlan($message, $action);
                if ($plan === null) {
                    continue;
                }
                $when = self::shortWhen($plan['start'], $plan['end']);

                return ['index' => $index, 'title' => __('push.book', ['when' => $when]), 'does' => __('push.book_does', [
                    'customer' => $plan['customer']->display_name, 'when' => $when, 'address' => $plan['property']->fullAddress(),
                ])];
            }

            if ($action['type'] === 'cancel') {
                return ['index' => $index, 'title' => __('push.cancel', ['job' => '#'.$action['job_number']]), 'does' => self::preview($action)];
            }

            $visit = JobVisit::query()->find($action['visit_id'] ?? 0);
            if ($visit === null) {
                continue;
            }
            [$start, $end] = self::newTime($action, $visit);

            return ['index' => $index, 'title' => __('push.move', ['when' => self::shortWhen($start, $end)]), 'does' => self::preview($action)];
        }

        return null;
    }

    /**
     * A booking that can be made without the form: a known customer, their address, a day and a window.
     *
     * @param  array<string, mixed>  $action
     * @return array{customer: Customer, property: Property, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private static function directPlan(Message $message, array $action): ?array
    {
        $customer = $message->customer;
        $property = $customer === null ? null : Property::query()->where('customer_id', $customer->id)
            ->orderByDesc('is_primary')->orderBy('id')->get()->first(fn (Property $p) => $p->hasAddress());

        if ($customer === null || $customer->trashed() || $property === null || empty($action['date']) || empty($action['start']) || empty($action['end'])) {
            return null;
        }

        $tz = currentCompany()->timezone;
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$action['date']} {$action['start']}", $tz);
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$action['date']} {$action['end']}", $tz);

        return $start && $end && $end->greaterThan($start) && $start->isFuture()
            ? ['customer' => $customer, 'property' => $property, 'start' => $start, 'end' => $end]
            : null;
    }

    /**
     * "Thu 10:00–12:00" in the company's time zone.
     */
    public static function shortWhen(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $tz = currentCompany()->timezone;

        return $start->setTimezone($tz)->isoFormat('ddd').' '.$start->setTimezone($tz)->format('H:i').'–'.$end->setTimezone($tz)->format('H:i');
    }

    /**
     * The tap on the main button of a push notification: the same as Confirm in the chat; a booking is made right
     * away (the form is skipped because everything it needs is known) and the usual booking confirmation goes out.
     */
    public function runFromPush(Message $message, int $index, User $user): string
    {
        $triage = (array) $message->ai_triage;
        $action = $triage['actions'][$index] ?? null;

        if (! is_array($action) || ($action['status'] ?? 'pending') !== 'pending') {
            throw ValidationException::withMessages(['action' => __('automation.inbox.already_decided')]);
        }

        if ($action['type'] !== 'book') {
            return $this->confirm($message, $index, $user)['message'];
        }

        Gate::forUser($user)->authorize('create', ServiceJob::class);
        $plan = self::directPlan($message, $action);
        if ($plan === null) {
            throw ValidationException::withMessages(['action' => __('push.book_needs_form')]);
        }

        $job = app(ActionSource::class)->as(AssistantActions::SOURCE, fn () => app(DirectBooking::class)->create(
            $plan['customer'], $plan['property'], $plan['start'], $plan['end'], mb_substr($message->body, 0, 500), $user,
        ));
        app(BookingConfirmation::class)->send($job, $user);

        $triage['actions'][$index]['status'] = 'done';
        $triage['actions'][$index]['job_id'] = $job->id;
        $message->forceFill(['ai_triage' => $triage])->save();

        return __('push.booked', ['job' => '#'.$job->number, 'when' => self::shortWhen($plan['start'], $plan['end'])]);
    }

    public function dismiss(Message $message, int $index): void
    {
        $triage = (array) $message->ai_triage;
        abort_unless(isset($triage['actions'][$index]), 404);
        $triage['actions'][$index]['status'] = 'dismissed';
        $message->forceFill(['ai_triage' => $triage])->save();
    }

    /**
     * Confirm on the plate: brand, model and serial go to the job's appliance (a new one when the job has none, or
     * when its appliance already has another model number).
     */
    public function attachPlate(Message $message, User $user): string
    {
        $triage = (array) $message->ai_triage;
        $plate = $triage['plate'] ?? null;

        if (! is_array($plate) || ($plate['status'] ?? null) !== 'pending' || empty($plate['job_id'])) {
            throw ValidationException::withMessages(['plate' => __('automation.inbox.already_decided')]);
        }

        $job = ServiceJob::query()->with(['appliances', 'property'])->findOrFail($plate['job_id']);
        Gate::forUser($user)->authorize('update', $job);

        app(ActionSource::class)->as(AssistantActions::SOURCE, function () use ($job, $plate) {
            $appliance = $job->appliances->first(fn (Appliance $a) => ! $a->trashed() && ($a->model_number === null || $a->model_number === $plate['model']));
            $attributes = array_filter([
                'manufacturer' => $plate['brand'] ?: null,
                'model_number' => $plate['model'] ?: null,
                'serial_number' => $plate['serial'] ?: null,
            ]);
            $saved = app(SaveAppliance::class)->handle($job->property, $appliance, $appliance === null
                ? ['type' => ApplianceType::Other->value, ...$attributes] : $attributes);
            if ($appliance === null) {
                $job->appliances()->syncWithoutDetaching([$saved->id]);
            }
        });

        $triage['plate']['status'] = 'done';
        $message->forceFill(['ai_triage' => $triage])->save();
        app(AuditLogger::class)->record('assistant.sms_plate', $message, ['label' => $triage['plate']['model'] ?? '']);

        return __('automation.inbox.plate_saved', ['job' => '#'.$job->number]);
    }

    public function dismissPlate(Message $message): void
    {
        $triage = (array) $message->ai_triage;
        abort_unless(isset($triage['plate']), 404);
        $triage['plate']['status'] = 'dismissed';
        $message->forceFill(['ai_triage' => $triage])->save();
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{message: string}
     */
    private function move(array $action, User $user): array
    {
        $visit = JobVisit::query()->with('job')->findOrFail($action['visit_id']);
        Gate::forUser($user)->authorize('update', $visit);
        [$start] = self::newTime($action, $visit);

        // Like dragging on the calendar: the customer is not texted; the office answers in the chat.
        app(SaveVisit::class)->move($visit, $start, null, null, $user);

        return ['message' => __('automation.inbox.moved', ['job' => '#'.$visit->job->number])];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{message: string}
     */
    private function cancel(array $action, User $user): array
    {
        $job = ServiceJob::query()->findOrFail($action['job_id']);
        Gate::forUser($user)->authorize('update', $job);
        $reason = currentCompany()->closureReasons(JobOutcome::Cancelled)[0] ?? '';

        app(CloseJob::class)->cancel($job, $reason, __('automation.inbox.cancel_note'), $user);

        return ['message' => __('automation.inbox.cancelled', ['job' => '#'.$job->number])];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{message: string, booking: array<string, mixed>}
     */
    private function book(array $action, User $user, string $from): array
    {
        Gate::forUser($user)->authorize('useQuickBooking');

        try {
            $fields = app(QuickBookingParser::class)->parse((string) $action['text'], currentCompany(), $user);
        } catch (AiUnavailable) {
            throw ValidationException::withMessages(['action' => __('jobs.quick_booking.failed')]);
        }
        // The day the AI already worked out from the text wins over a second reading.
        $fields['date'] = $action['date'] ?? $fields['date'];
        // The text came from this number.
        $fields['phone'] ??= $from;
        $found = app(CustomerFinder::class)->find($fields['phone'], $fields['first_name'], $fields['last_name'], $fields['address']['line1'] ?? null);

        return [
            'message' => __('assistant.actions.booking_opened'),
            'booking' => [
                'fields' => $fields,
                'customer' => $found === null ? null : JobController::customerOption($found['customer']),
                'matched_by' => $found['by'] ?? null,
            ],
        ];
    }
}
