<?php

namespace App\Services\AI\Sms;

use App\Enums\JobStatus;
use App\Enums\VisitStatus;
use App\Http\Controllers\SelfBookingController;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\ServiceJob;
use App\Services\AI\AiFeature;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Support\Billing\PaymentReminders;
use App\Support\Jobs\NearbyWindows;
use App\Support\Locale\RelativeDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads an incoming SMS with AI_MODEL_BOOKING (owner's task of November 18): the text, the latest messages of the
 * conversation and the customer's open jobs go in; urgency (urgent / normal), proposed actions (book, move to another
 * day, change the time, cancel) and a reply draft come back. Dates like "tomorrow" or "Thursday" are turned into a date
 * by code in the company's time zone. Nothing is done here: the actions wait for Confirm in the chat, the draft for the
 * person's own Send. Must run in a tenant context.
 */
class SmsTriage
{
    public const ACTIONS = ['book', 'reschedule', 'change_time', 'cancel'];

    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array<string, mixed> What is stored on the message (ai_triage).
     *
     * @throws AiUnavailable
     */
    public function triage(Message $message, Company $company): array
    {
        $jobs = $message->customer_id ? self::openJobs($message->customer_id) : new Collection;
        // An answer to a soft payment reminder: the draft carries the amount and the invoice link.
        $invoice = PaymentReminders::answered($message);
        $debt = $invoice === null ? '' : "\n\nThis customer answers our soft payment reminder about invoice {$invoice->number}: "
            .'balance '.PaymentReminders::facts($invoice)['amount'].', link '.PaymentReminders::facts($invoice)['link']
            .'. The reply draft must politely give the amount and the link.';

        $raw = $this->ai->send(
            AiFeature::SmsTriage,
            $company,
            null,
            $this->instructions($company),
            [['role' => 'user', 'content' => $this->context($message, $jobs, $company).$debt]],
            schema: self::schema(),
            maxTokens: 1500,
            effort: 'low',
        )->json();

        $result = [...self::normalize($raw, $message, $jobs, $company), ...ReplyGuide::compose($raw, $message, $invoice), 'invoice_id' => $invoice?->id];

        // The customer asks about a time or asks to come: a self-booking link in the draft (7 days, one booking).
        if (($raw['wants_time'] ?? false) === true) {
            $link = SelfBookingController::link($message->customer, (string) $message->from);
            $result['reply_draft'] = trim(($result['reply_draft'] ?? '').' '.__('booking.link_line', ['link' => $link]));
            $result['booking_link'] = $link;
        }

        return $result;
    }

    /**
     * Jobs of the customer that are not finished: the ones a text can be about.
     *
     * @return Collection<int, ServiceJob>
     */
    public static function openJobs(int $customerId): Collection
    {
        return ServiceJob::query()->where('customer_id', $customerId)
            ->whereNotIn('status', [JobStatus::Completed, JobStatus::Invoiced, JobStatus::AwaitingPayment, JobStatus::Paid, JobStatus::Cancelled])
            ->whereNull('outcome')->whereNull('closed_at')
            ->with(['visits' => fn ($q) => $q->orderBy('scheduled_start'), 'appliances'])
            ->latest('id')->limit(5)->get();
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];
        $action = [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => self::ACTIONS],
                'job_number' => ['type' => 'string', 'description' => 'Number of the open job it is about, "" for a new booking'],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or the customer\'s words like "tomorrow" or "thursday", "" when not given'],
                'arrival_start' => ['type' => 'string', 'description' => 'HH:MM 24-hour, "" when not given'],
                'arrival_end' => ['type' => 'string', 'description' => 'HH:MM 24-hour, "" when not given'],
                'arrival_now' => ['type' => 'boolean'],
            ],
            'required' => ['type', 'job_number', 'date', 'arrival_start', 'arrival_end', 'arrival_now'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'urgency' => ['type' => 'string', 'enum' => ['urgent', 'normal']],
                'summary' => ['type' => 'string', 'description' => 'One short line in English: who writes and what they want'],
                'actions' => ['type' => 'array', 'items' => $action],
                'reply_draft' => $text,
                'about_breakdown' => ['type' => 'boolean'],
                'questions' => ['type' => 'array', 'items' => $text],
                'appliance_type' => ['type' => 'string', 'description' => 'refrigerator, freezer, washer, dryer, dishwasher, range, oven, cooktop, other, or "" when not named'],
                'wants_time' => ['type' => 'boolean'],
            ],
            'required' => ['urgency', 'summary', 'actions', 'reply_draft', 'about_breakdown', 'questions', 'appliance_type', 'wants_time'],
            'additionalProperties' => false,
        ];
    }

    private function instructions(Company $company): string
    {
        $now = CarbonImmutable::now($company->timezone);
        $today = $now->format('Y-m-d (l)');
        $windows = implode(', ', array_map(fn (array $w) => "{$w['start']}-{$w['end']}", $company->arrivalWindows()));
        $facts = ReplyGuide::facts($company);

        return <<<TEXT
            You read a text message (SMS) a customer sent to a repair company ({$company->name}; appliance repair or handyman)
            and help the office answer it. You never send or change anything; a person confirms every action.

            - urgency: "urgent" for water leaking, flooding, gas or burning smell, sparks, smoke, no heat in cold weather, a
              fridge/freezer full of food not cooling, an angry customer, or "today / right now / ASAP"; otherwise "normal".
            - actions: what the office may want to do, [] when nothing.
              "book" = a new visit is wanted (no open job, or a different problem);
              "reschedule" = move an open job's visit to another day;
              "change_time" = same day, another time;
              "cancel" = the customer cancels an open job.
              job_number: the open job it is about (from the list), "" for "book".
              date: YYYY-MM-DD when sure, otherwise the customer's own words for the day ("tomorrow", "thursday"); "".
              Today is {$today}, company time zone {$company->timezone}.
              Arrival windows of the company: {$windows}. Give arrival_start and arrival_end (HH:MM) when the customer
              names a time or a part of the day; arrival_now = true only for "right now / ASAP today".
            - summary: one line in English, max 80 characters, for a phone notification: who writes and what they want
              (e.g. "Wants the washer visit moved to Thursday morning").
            - reply_draft: a short, polite reply in the customer's language, signed as nobody, max 300 characters. Never
              promise a time that is not confirmed; say the office will confirm. Name only the prices listed under
              "Prices and answers" below; any other price: say the technician will tell them after the diagnosis. Do
              not put clarifying questions or a request for a photo into reply_draft.
            - about_breakdown: true when the customer writes about something broken or not working.
            - questions: only when about_breakdown and details are few: at most 2 short questions (what exactly happens,
              since when, any error code on the display); [] otherwise.
            - appliance_type: the appliance the text is about.
            - wants_time: true when the customer asks when we can come, asks for a time or asks us to come (a link to
              pick a time is then added by the app; do not invent one).
              When proposing a day, prefer the "Nearby days" listed in the message (a job is already near them).

            Prices and answers of the company:
            {$facts}
            TEXT;
    }

    /**
     * @param  Collection<int, ServiceJob>  $jobs
     */
    private function context(Message $message, Collection $jobs, Company $company): string
    {
        $history = Message::query()->where(fn ($q) => $q->where('from', $message->from)->orWhere('to', $message->from))
            ->where('id', '<', $message->id)->whereIn('channel', [Message::SMS, Message::TECHNICIAN_PHONE])
            ->latest('id')->limit(10)->get()->reverse()
            ->map(fn (Message $m) => ($m->direction === Message::INBOUND ? 'Customer: ' : 'Company: ').mb_substr($m->body, 0, 400))
            ->implode("\n");

        $open = $jobs->map(function (ServiceJob $job) use ($company) {
            $visit = $job->visits->first(fn (JobVisit $v) => $v->status === VisitStatus::Scheduled);
            $when = $visit ? CarbonImmutable::instance($visit->scheduled_start)->setTimezone($company->timezone)->format('D Y-m-d H:i')
                .'-'.CarbonImmutable::instance($visit->scheduled_end)->setTimezone($company->timezone)->format('H:i') : 'no time';

            return "#{$job->number}: {$job->status->label()}, {$when}, ".($job->appliances->map->label()->implode(', ') ?: '').' '.mb_substr((string) $job->description, 0, 200);
        })->implode("\n");

        // Windows by area: days that already have a job near the customer's address.
        $property = $message->customer?->primaryProperty;
        $nearby = collect(NearbyWindows::days($company, NearbyWindows::point($property)))
            ->sortBy('km')->map(fn (array $d, string $date) => CarbonImmutable::parse($date)->format('D Y-m-d')." ({$d['label']})")->implode(', ');

        return "Earlier messages:\n".($history ?: '(none)')."\n\nOpen jobs of this customer:\n".($open ?: '(none)')
            ."\n\nNearby days: ".($nearby ?: '(none)')
            ."\n\nNew message from the customer:\n".$message->body;
    }

    /**
     * Only actions the app can do: a known open job with a visit to move, a real date and time.
     *
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, ServiceJob>  $jobs
     * @return array<string, mixed>
     */
    public static function normalize(array $raw, Message $message, Collection $jobs, Company $company): array
    {
        $time = fn ($value) => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
        $actions = [];

        foreach (array_slice((array) ($raw['actions'] ?? []), 0, 3) as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;

            if (! in_array($type, self::ACTIONS, true)) {
                continue;
            }

            $date = RelativeDate::resolve((string) ($action['date'] ?? ''), $company);
            $start = $time($action['arrival_start'] ?? null);
            $end = $time($action['arrival_end'] ?? null);
            $now = ($action['arrival_now'] ?? false) === true;
            if ($start !== null && ($end === null || $end <= $start)) {
                $end = null;
            }

            if ($type === 'book') {
                $actions[] = ['type' => 'book', 'text' => mb_substr($message->body, 0, 2000), 'date' => $date, 'start' => $start, 'end' => $end, 'now' => $now, 'status' => 'pending'];

                continue;
            }

            $job = $jobs->first(fn (ServiceJob $j) => (string) $j->number === ltrim((string) ($action['job_number'] ?? ''), '#'))
                ?? ($jobs->count() === 1 ? $jobs->first() : null);
            if ($job === null) {
                continue;
            }
            $visit = $job->visits->first(fn (JobVisit $v) => $v->status === VisitStatus::Scheduled);

            if ($type === 'cancel') {
                $actions[] = ['type' => 'cancel', 'job_id' => $job->id, 'job_number' => $job->number, 'status' => 'pending'];

                continue;
            }

            // Moving needs a visit that has not started, and something to move it to.
            if ($visit === null || ($date === null && $start === null && ! $now)) {
                continue;
            }

            $actions[] = [
                'type' => $type, 'job_id' => $job->id, 'job_number' => $job->number, 'visit_id' => $visit->id,
                'date' => $date, 'start' => $start, 'end' => $end, 'now' => $now, 'status' => 'pending',
            ];
        }

        return [
            'urgency' => ($raw['urgency'] ?? null) === 'urgent' ? 'urgent' : 'normal',
            'summary' => mb_substr(trim((string) ($raw['summary'] ?? '')), 0, 120) ?: null,
            'actions' => $actions,
            'reply_draft' => mb_substr(trim((string) ($raw['reply_draft'] ?? '')), 0, 600) ?: null,
        ];
    }
}
