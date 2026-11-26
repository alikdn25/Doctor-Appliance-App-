<?php

namespace App\Services\AI\Assistant;

use App\Actions\Jobs\SaveVisit;
use App\Enums\InvoiceStatus;
use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Enums\VisitStatus;
use App\Http\Controllers\Jobs\JobController;
use App\Http\Controllers\Jobs\QuickBookingController;
use App\Messaging\MessagingPresenter;
use App\Messaging\Messenger;
use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\QuickBookingParser;
use App\Services\AI\TaskParser;
use App\Services\AuditLogger;
use App\Support\Audit\ActionSource;
use App\Support\Billing\Money;
use App\Support\Customers\CustomerFinder;
use App\Support\Jobs\BookingPhotos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The assistant's actions: a payment reminder by SMS, moving a booking, a new booking, a new task, an invoice read from photos. The AI only proposes one (a
 * pending "what I will do" card); it runs when the person presses Confirm, as that person, marked "AI assistant" in
 * the change log. Cancel drops it. The assistant can delete nothing.
 */
class AssistantActions
{
    public const SOURCE = 'ai_assistant';

    /**
     * Checks the proposal and stores it as pending; what the AI is told back.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function propose(string $type, array $input, User $user, string $conversation): array
    {
        [$params, $summary] = match ($type) {
            AssistantAction::PAYMENT_REMINDER => $this->reminderProposal($input),
            AssistantAction::RESCHEDULE => $this->rescheduleProposal($input, $user),
            AssistantAction::BOOKING => $this->bookingProposal($input, $conversation),
            AssistantAction::INVOICE => $this->invoiceProposal($input, $conversation),
            AssistantAction::TASK => $this->taskProposal($input, $user),
        };

        $action = AssistantAction::create([
            'user_id' => $user->id, 'conversation' => $conversation, 'type' => $type,
            'after_message_id' => AssistantMessage::query()->where('conversation', $conversation)->max('id'),
            'params' => $params, 'summary' => $summary, 'status' => 'pending',
        ]);

        return [
            'proposed' => true,
            'action_id' => $action->id,
            'what_i_will_do' => $summary,
            ...($type === AssistantAction::INVOICE ? ['preview' => self::invoiceForAi($params['draft'])] : []),
            'note' => $type === AssistantAction::INVOICE
                ? 'Shown to the user as a preview card with Confirm & send, Edit and Cancel; taxes and the total were calculated by the app. Nothing is saved or sent until they press a button. Do not propose it again; tell them briefly to check the card. If they want changes, call create_invoice again with the corrected data.'
                : 'Shown to the user with Confirm and Cancel. Nothing is done until they press Confirm. Do not propose it again; tell them briefly to confirm.',
        ];
    }

    /**
     * Runs a confirmed action. Returns what the chat shows and, for a booking, the filled form's data.
     *
     * @return array{message: string, booking?: array<string, mixed>, task?: array<string, mixed>, redirect?: string}
     */
    public function confirm(AssistantAction $action, User $user, QuickBookingParser $parser, CustomerFinder $finder, bool $send = true): array
    {
        return app(ActionSource::class)->as(self::SOURCE, function () use ($action, $user, $parser, $finder, $send) {
            $result = match ($action->type) {
                AssistantAction::PAYMENT_REMINDER => $this->sendReminder($action, $user),
                AssistantAction::RESCHEDULE => $this->reschedule($action, $user),
                AssistantAction::BOOKING => $this->booking($action, $user, $parser, $finder),
                AssistantAction::INVOICE => $this->invoice($action, $user, $send),
                AssistantAction::TASK => $this->task($action, $user),
            };

            app(AuditLogger::class)->record('assistant.'.$action->type, $action, ['label' => $action->summary, 'result' => $result['message']]);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function reminderProposal(array $input): array
    {
        $invoice = Invoice::query()->with('customer.primaryPhone')->find((int) ($input['invoice_id'] ?? 0));

        if ($invoice === null) {
            throw new AssistantError('No invoice with this id.');
        }
        if (! in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true) || $invoice->balance <= 0) {
            throw new AssistantError('This invoice has nothing to pay.');
        }
        if (currentCompany()->sms_mode !== SmsMode::Automatic) {
            throw new AssistantError('Texts from the company number are off (SMS mode is not Automatic), so a reminder cannot be sent.');
        }

        return [['invoice_id' => $invoice->id], __('assistant.actions.reminder', [
            'customer' => $invoice->customer?->display_name,
            'phone' => $invoice->customer?->primaryPhone?->number ?? '—',
            'number' => $invoice->number,
            'balance' => Money::format($invoice->balance, $invoice->currency, currentCompany()->locale),
        ])];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function rescheduleProposal(array $input, User $user): array
    {
        $visit = JobVisit::query()->with('job.customer')->find((int) ($input['visit_id'] ?? 0));

        if ($visit === null || ! Gate::forUser($user)->allows('update', $visit)) {
            throw new AssistantError('No visit with this id.');
        }
        if ($visit->status !== VisitStatus::Scheduled) {
            throw new AssistantError('Only a visit that has not started can be moved.');
        }

        $start = $this->start((string) ($input['date'] ?? ''), (string) ($input['start_time'] ?? ''));
        $length = (int) $visit->scheduled_start->diffInMinutes($visit->scheduled_end);
        $from = CarbonImmutable::instance($visit->scheduled_start)->setTimezone(currentCompany()->timezone);

        return [
            ['visit_id' => $visit->id, 'date' => $start->toDateString(), 'start_time' => $start->format('H:i')],
            __('assistant.actions.reschedule', [
                'number' => $visit->job->number,
                'customer' => $visit->job->customer?->display_name,
                'from' => $from->format('Y-m-d H:i'),
                'to' => $start->format('Y-m-d H:i').'–'.$start->addMinutes($length)->format('H:i'),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function bookingProposal(array $input, string $conversation): array
    {
        $text = trim((string) ($input['text'] ?? ''));
        // A booking read from a photo or screenshot: the photos of the latest question with photos go with it, to be
        // read again for the form and attached to the new job.
        $photos = ($input['from_photos'] ?? false) === true ? AssistantPhotos::latest($conversation) : [];

        if ($text === '' && $photos === []) {
            throw new AssistantError('Say what the booking is.');
        }

        return [
            ['text' => mb_substr($text, 0, 5000), 'photos' => $photos],
            $photos === []
                ? __('assistant.actions.booking', ['text' => mb_substr($text, 0, 300)])
                : trans_choice('assistant.actions.booking_photos', count($photos), ['text' => mb_substr($text, 0, 300)]),
        ];
    }

    /**
     * A new to-do: the text (and the customer's job the assistant found) is kept; the form is read on Confirm.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function taskProposal(array $input, User $user): array
    {
        if (! Gate::forUser($user)->allows('create', Task::class)) {
            throw new AssistantError('This user cannot create tasks.');
        }

        $text = trim((string) ($input['text'] ?? ''));

        if ($text === '') {
            throw new AssistantError('Say what the task is.');
        }

        $jobId = is_numeric($input['job_id'] ?? null) ? (int) $input['job_id'] : null;
        $job = $jobId === null ? null : ServiceJob::query()->visibleTo($user)->with('customer')->find($jobId);

        if ($jobId !== null && $job === null) {
            throw new AssistantError('No job with this id.');
        }

        return [
            ['text' => mb_substr($text, 0, 5000), 'job_id' => $job?->id],
            $job === null
                ? __('assistant.actions.task', ['text' => mb_substr($text, 0, 300)])
                : __('assistant.actions.task_job', ['text' => mb_substr($text, 0, 300), 'number' => $job->number, 'customer' => $job->customer?->display_name]),
        ];
    }

    /**
     * Confirm opens the task form filled in (TaskParser); nothing is saved until the person presses Save.
     *
     * @return array{message: string, task: array<string, mixed>}
     */
    private function task(AssistantAction $action, User $user): array
    {
        Gate::forUser($user)->authorize('create', Task::class);
        $task = app(TaskParser::class)->parse((string) $action->params['text'], currentCompany(), $user, $action->params['job_id'] ?? null);

        if ($task === null) {
            throw ValidationException::withMessages(['action' => __('tasks.parse_failed')]);
        }

        return ['message' => __('assistant.actions.task_opened'), 'task' => $task];
    }

    /**
     * The invoice read from photos or text: checked and priced by code (InvoiceDraft); nothing is stored but the
     * proposal. The photos of the latest question with photos go with it, to become the invoice's attachments.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function invoiceProposal(array $input, string $conversation): array
    {
        $draft = InvoiceDraft::build($input, currentCompany());

        return [['draft' => $draft, 'photos' => AssistantPhotos::latest($conversation)], InvoiceDraft::summary($draft, currentCompany())];
    }

    /**
     * The invoice preview as the AI is told it (amounts formatted).
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private static function invoiceForAi(array $draft): array
    {
        $money = fn (int $minor) => Money::format($minor, $draft['currency'], currentCompany()->locale);

        return [
            'customer' => $draft['customer']['name'],
            'existing_customer' => $draft['customer']['id'] !== null,
            'job' => $draft['job_number'],
            'lines' => array_map(fn (array $line) => ['description' => $line['description'], 'amount' => $money($line['total'])], $draft['lines']),
            'taxes' => array_map(fn (array $tax) => ['name' => $tax['name'], 'rate' => $tax['rate'].'%', 'amount' => $money($tax['amount'])], $draft['taxes']),
            'total' => $money($draft['total']),
        ];
    }

    /**
     * @return array{message: string, redirect?: string}
     */
    private function invoice(AssistantAction $action, User $user, bool $send): array
    {
        Gate::forUser($user)->authorize('viewAny', Invoice::class);
        $result = app(AssistantInvoice::class)->confirm($action, $user, $send);

        return ['message' => $result['message'], ...(isset($result['redirect']) ? ['redirect' => $result['redirect']] : [])];
    }

    private function start(string $date, string $time): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new AssistantError('Give the date as YYYY-MM-DD and the time as HH:MM.');
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", currentCompany()->timezone);

        if ($start === null || $start->isPast()) {
            throw new AssistantError('The new time must be in the future.');
        }

        return $start;
    }

    /**
     * @return array{message: string}
     */
    private function sendReminder(AssistantAction $action, User $user): array
    {
        $invoice = Invoice::query()->with(['customer', 'job'])->findOrFail($action->params['invoice_id']);
        Gate::forUser($user)->authorize('view', $invoice);

        if ($invoice->balance <= 0 || currentCompany()->sms_mode !== SmsMode::Automatic) {
            throw ValidationException::withMessages(['action' => __('assistant.actions.not_possible')]);
        }

        $message = app(Messenger::class)->sms(MessageKind::InvoiceLink, $invoice->customer, $invoice->job, MessagingPresenter::documentText($invoice), $user);

        return ['message' => $message->status === 'blocked'
            ? __('messages.sms_blocked', ['reason' => $message->status_reason])
            : __('assistant.actions.reminder_sent', ['number' => $invoice->number])];
    }

    /**
     * @return array{message: string}
     */
    private function reschedule(AssistantAction $action, User $user): array
    {
        $visit = JobVisit::query()->with('job')->findOrFail($action->params['visit_id']);
        Gate::forUser($user)->authorize('update', $visit);
        $start = $this->start($action->params['date'], $action->params['start_time']);

        // Moving never texts the customer (like dragging on the calendar).
        app(SaveVisit::class)->move($visit, $start, null, null, $user);

        return ['message' => __('assistant.actions.rescheduled', ['number' => $visit->job->number, 'when' => $start->format('Y-m-d H:i')])];
    }

    /**
     * @return array{message: string, booking: array<string, mixed>}
     */
    private function booking(AssistantAction $action, User $user, QuickBookingParser $parser, CustomerFinder $finder): array
    {
        $photos = array_values((array) ($action->params['photos'] ?? []));
        $fields = $parser->parse((string) $action->params['text'], currentCompany(), $user, $photos);
        $found = $finder->find($fields['phone'], $fields['first_name'], $fields['last_name'], $fields['address']['line1'] ?? null);

        return [
            'message' => __('assistant.actions.booking_opened'),
            'booking' => [
                'fields' => $fields,
                'customer' => $found === null ? null : JobController::customerOption($found['customer']),
                'matched_by' => $found['by'] ?? null,
                // Copies of the chat's photos, attached to the job when the booking is saved.
                'photos' => QuickBookingController::photoList(BookingPhotos::copy($photos, currentCompany()->id, $user->id)),
            ],
        ];
    }
}
