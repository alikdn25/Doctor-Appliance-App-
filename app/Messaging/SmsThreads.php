<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CustomerPhone;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\SmsThread;
use App\Models\User;
use App\Services\AI\Sms\SmsActions;
use App\Support\Locale\BusinessHours;
use App\Support\PhoneNumber;
use App\Support\Push\PushActions;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who owes whom a reply (owner's task of November 18):
 *
 * - An incoming text no one has answered starts a wait. Reminders (push + the notification list) go to the office
 *   every N hours (company setting, 3 by default), at most 3 times, only during business hours. A text that came
 *   outside business hours gets one reminder when the company next opens.
 * - A reply from a person, or "Done" in the chat, ends the wait and the reminders.
 * - Auto reply (Auto messages, off by default): after the chosen delay, when no one has replied meanwhile, only during
 *   business hours, at most once per 24 hours per number; never to the company's own staff. A missed call to the
 *   company number (Telnyx voice) gets its own text by the same rules.
 *
 * - "Customer is quiet" (owner's task of November 19): a text a person typed (a reply, a new text, a payment reminder)
 *   that got no answer within N hours (company setting, 2 by default) gives that person one reminder with buttons
 *   (Text again, Call, Not waiting), only during business hours; one still unanswered the next morning is a line in the
 *   morning SMS. Auto replies, On my way, review requests and texts marked "Not waiting for a reply" never count.
 *
 * Must run in a tenant context, except the scheduled sweeps, which visit each company in turn.
 */
class SmsThreads
{
    public const AUTO_REPLY_GAP_HOURS = 24;

    public const TEXT = 'text';

    public const MISSED_CALL = 'missed_call';

    /** Texts that expect an answer: typed by a person (General, Reply) or a payment reminder. */
    public const AWAITS_ANSWER = [MessageKind::General, MessageKind::Reply, MessageKind::PaymentReminder];

    public function __construct(private readonly Messenger $messenger) {}

    public static function for(string $phone): SmsThread
    {
        return SmsThread::query()->firstOrCreate(['phone' => $phone]);
    }

    /**
     * An incoming text was stored: start (or keep) the wait and plan an auto reply.
     */
    public function received(Message $message): SmsThread
    {
        $company = currentCompany();
        $thread = self::for((string) $message->from);
        $at = CarbonImmutable::instance($message->created_at ?? now());

        // The customer answered: they are no longer quiet.
        $thread->fill(self::NOT_SILENT);

        if ($thread->awaiting_since === null) {
            $open = BusinessHours::isOpen($company, $at);
            $thread->fill([
                'awaiting_since' => $at,
                'reminders_sent' => 0,
                'reminders_max' => $open ? 3 : 1,
                'next_reminder_at' => $open
                    ? BusinessHours::nextOpen($company, $at->addHours(max(1, $company->sms_reminder_hours)))
                    : BusinessHours::nextOpen($company, $at),
                'done_at' => null,
                'done_by' => null,
            ]);
        }

        if ($thread->auto_reply_due_at === null && $this->mayAutoReply($company, $thread, (string) $message->from, $at)
            && $company->auto_reply_enabled && ! $this->isKeyword($message->body)) {
            $thread->fill([
                'auto_reply_due_at' => $at->addMinutes(max(0, $company->auto_reply_delay_minutes)),
                'auto_reply_kind' => self::TEXT,
            ]);
        }

        $thread->save();

        if ($thread->auto_reply_due_at !== null && $thread->auto_reply_due_at->lessThanOrEqualTo(now())) {
            $this->sendAutoReply($thread);
        }

        return $thread;
    }

    /**
     * A person texted this number (any outbound text with a user): the wait and its reminders end.
     */
    public static function answered(string $phone, int $companyId): void
    {
        SmsThread::withoutCompanyScope()->where('company_id', $companyId)->where('phone', $phone)->whereNotNull('awaiting_since')->update([
            'awaiting_since' => null, 'urgent' => false, 'next_reminder_at' => null, 'auto_reply_due_at' => null,
            'auto_reply_kind' => null, 'updated_at' => now(),
        ]);
    }

    private const NOT_SILENT = [
        'silent_since' => null, 'silent_message_id' => null, 'silent_remind_at' => null, 'silent_reminded_at' => null,
        'silent_reported_on' => null, 'silent_draft' => null,
    ];

    /**
     * A person texted the customer (Message created): wait for the answer. One reminder after N hours, in business
     * hours (one due after closing comes at the next opening).
     */
    public static function waitForCustomer(Message $message): void
    {
        if ($message->channel !== Message::SMS || $message->status === 'blocked' || $message->no_reply_expected
            || ! in_array($message->kind, self::AWAITS_ANSWER, true)) {
            return;
        }

        $company = Company::query()->find($message->company_id);
        if ($company === null) {
            return;
        }

        $at = CarbonImmutable::instance($message->created_at ?? now());
        $thread = SmsThread::withoutCompanyScope()->firstOrCreate(['company_id' => $company->id, 'phone' => $message->to]);
        $thread->fill([
            'silent_since' => $at,
            'silent_message_id' => $message->id,
            'silent_remind_at' => BusinessHours::nextOpen($company, $at->addHours(max(1, $company->silence_reminder_hours))),
            'silent_reminded_at' => null,
            'silent_reported_on' => null,
            'silent_draft' => null,
        ])->save();
    }

    /**
     * "Not waiting for a reply" on one of our texts: it no longer counts, and the wait it started ends.
     */
    public static function notWaiting(Message $message): void
    {
        $message->forceFill(['no_reply_expected' => true])->save();

        SmsThread::query()->where('phone', (string) $message->to)->whereNotNull('silent_since')
            ->where('silent_message_id', '<=', $message->id)->update([...self::NOT_SILENT, 'updated_at' => now()]);
    }

    /**
     * "Done" in the chat: handled without a reply. Ends the reminders and marks the texts read for this person.
     */
    public function done(string $phone, User $user): void
    {
        $thread = self::for($phone);
        $thread->fill([
            'awaiting_since' => null, 'urgent' => false, 'next_reminder_at' => null, 'auto_reply_due_at' => null,
            'auto_reply_kind' => null, 'done_at' => now(), 'done_by' => $user->id,
        ])->save();

        $ids = SmsInbox::unread($user)->whereRaw('messages."from" = ?', [$phone])->pluck('messages.id')->all();
        if ($ids !== []) {
            app(SmsInbox::class)->markRead($user, $phone, $ids);
        }
    }

    public static function markUrgent(string $phone, bool $urgent): void
    {
        if ($urgent) {
            SmsThread::query()->where('phone', $phone)->whereNotNull('awaiting_since')->update(['urgent' => true]);
        }
    }

    /**
     * A missed call to the company number (Telnyx voice webhook): the missed-call text, by the auto-reply rules.
     */
    public function missedCall(string $from): ?Message
    {
        $company = currentCompany();

        if (! $company->missed_call_reply_enabled || ! config('sms.missed_calls.enabled')) {
            return null;
        }

        $thread = self::for($from);

        if (! $this->mayAutoReply($company, $thread, $from, CarbonImmutable::now())) {
            return null;
        }

        $thread->fill(['auto_reply_due_at' => now(), 'auto_reply_kind' => self::MISSED_CALL])->save();

        return $this->sendAutoReply($thread);
    }

    /**
     * Sends the due auto reply of a thread, when everything still allows it.
     */
    public function sendAutoReply(SmsThread $thread): ?Message
    {
        $company = currentCompany();
        $kind = $thread->auto_reply_kind ?? self::TEXT;
        $now = CarbonImmutable::now();
        $enabled = $kind === self::MISSED_CALL ? $company->missed_call_reply_enabled : $company->auto_reply_enabled;
        // A text auto reply waits for a person: once someone replied, it is no longer needed.
        $stillOwed = $kind === self::MISSED_CALL || $thread->awaiting_since !== null;

        $thread->fill(['auto_reply_due_at' => null, 'auto_reply_kind' => null]);

        if (! $enabled || ! $stillOwed || ! $this->mayAutoReply($company, $thread, $thread->phone, $now)) {
            $thread->save();

            return null;
        }

        $thread->last_auto_reply_at = $now;
        $thread->save();

        $body = self::text($company, $kind);
        $phones = CustomerPhone::query()->with('customer')->where('number_normalized', $thread->phone)->get()
            ->filter(fn (CustomerPhone $phone) => $phone->customer !== null);

        return $phones->pluck('customer_id')->unique()->count() === 1
            ? $this->messenger->sms(MessageKind::AutoReply, $phones->first()->customer, null, $body, recipient: $phones->first())
            : $this->messenger->smsToNumber($thread->phone, $body, null, MessageKind::AutoReply);
    }

    /**
     * The company's text for an auto reply, or the default one.
     */
    public static function text(Company $company, string $kind): string
    {
        $own = trim((string) ($kind === self::MISSED_CALL ? $company->missed_call_reply_text : $company->auto_reply_text));

        return $own !== '' ? $own : __("automation.auto.default_{$kind}", ['company' => $company->name]);
    }

    /**
     * Everything an automatic text needs: Automatic SMS mode, business hours, not staff, not in the last 24 hours.
     */
    private function mayAutoReply(Company $company, SmsThread $thread, string $phone, CarbonImmutable $at): bool
    {
        return $company->sms_mode === SmsMode::Automatic
            && BusinessHours::isOpen($company, $at)
            && ($thread->last_auto_reply_at === null || $thread->last_auto_reply_at->lessThanOrEqualTo($at->subHours(self::AUTO_REPLY_GAP_HOURS)))
            && ! self::isStaff($company, $phone);
    }

    /**
     * Whether the number belongs to someone working in the company (their profile phone).
     */
    public static function isStaff(Company $company, string $phone): bool
    {
        return User::query()
            ->whereNotNull('phone')
            ->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id))
            ->pluck('phone')
            ->contains(fn (string $number) => PhoneNumber::normalize($number, $company->country) === $phone);
    }

    private function isKeyword(string $body): bool
    {
        $keyword = strtoupper(trim($body));

        return in_array($keyword, [...(array) config('sms.opt_out_keywords'), ...(array) config('sms.opt_in_keywords'), 'HELP', 'INFO'], true);
    }

    /**
     * Every minute: due auto replies and reminders, company by company.
     *
     * @return array{auto_replies: int, reminders: int, quiet: int}
     */
    public function sweep(): array
    {
        $counts = ['auto_replies' => 0, 'reminders' => 0, 'quiet' => 0];
        $companyIds = SmsThread::withoutCompanyScope()
            ->where(fn ($q) => $q->where('auto_reply_due_at', '<=', now())->orWhere('next_reminder_at', '<=', now())
                ->orWhere('silent_remind_at', '<=', now()))
            ->distinct()->pluck('company_id');

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            app(CurrentCompany::class)->runAs($company, function () use ($company, &$counts) {
                foreach (SmsThread::query()->where('auto_reply_due_at', '<=', now())->get() as $thread) {
                    try {
                        $counts['auto_replies'] += $this->sendAutoReply($thread) ? 1 : 0;
                    } catch (Throwable $e) {
                        Log::warning('Auto reply failed', ['company' => $company->id, 'error' => $e->getMessage()]);
                    }
                }

                foreach (SmsThread::query()->whereNotNull('awaiting_since')->where('next_reminder_at', '<=', now())->get() as $thread) {
                    $counts['reminders'] += $this->remind($company, $thread) ? 1 : 0;
                }

                foreach (SmsThread::query()->whereNotNull('silent_since')->whereNull('silent_reminded_at')->where('silent_remind_at', '<=', now())->get() as $thread) {
                    $counts['quiet'] += $this->remindQuiet($company, $thread) ? 1 : 0;
                }
            });
        }

        return $counts;
    }

    /**
     * One reminder about an unanswered text, then the next one (or none after the last).
     */
    public function remind(Company $company, SmsThread $thread): bool
    {
        $now = CarbonImmutable::now();

        // Outside business hours: wait for the next opening.
        if (! BusinessHours::isOpen($company, $now)) {
            $thread->update(['next_reminder_at' => BusinessHours::nextOpen($company, $now)]);

            return false;
        }

        $sent = $thread->reminders_sent + 1;
        $thread->update([
            'reminders_sent' => $sent,
            'next_reminder_at' => $sent < $thread->reminders_max
                ? BusinessHours::nextOpen($company, $now->addHours(max(1, $company->sms_reminder_hours)))
                : null,
        ]);

        $latest = Message::query()->with('customer')->where('direction', Message::INBOUND)
            ->where('from', $thread->phone)->latest('id')->first();
        $name = $latest?->customer?->display_name ?? PhoneNumber::display($thread->phone, $company->country);
        $title = __('automation.reminders.title', ['name' => $name]);
        $body = __('automation.reminders.body', ['text' => mb_substr((string) $latest?->body, 0, 120)]);
        $buttons = self::replyButtons($latest, $thread->phone);
        if (isset($buttons['main'])) {
            $body .= "\n".__('push.main_will', ['action' => $buttons['main']['does']]);
        }

        foreach ($this->office() as $user) {
            try {
                PushActions::notify($user, 'sms_unanswered', $title, $body, route('messages.index', ['phone' => $thread->phone]), $buttons);
            } catch (Throwable $e) {
                Log::warning('Reminder delivery failed', ['error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /**
     * Buttons for a text that waits for our answer: the AI's main action (done on tap), Draft (the chat with the
     * reply draft in the box) and Later.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function replyButtons(?Message $latest, string $phone): array
    {
        $main = $latest !== null ? SmsActions::main($latest) : null;

        return array_filter([
            'main' => $main === null ? null : ['title' => $main['title'], 'does' => $main['does'], 'op' => 'sms_action', 'message_id' => $latest?->id, 'index' => $main['index']],
            'draft' => ['title' => __('push.draft'), 'url' => route('messages.index', ['phone' => $phone])],
            PushActions::LATER => PushActions::later(),
        ]);
    }

    /**
     * The one "customer is quiet" reminder, to the person who wrote (else the office), with Text again, Call and
     * Not waiting.
     */
    public function remindQuiet(Company $company, SmsThread $thread): bool
    {
        $now = CarbonImmutable::now();

        if (! BusinessHours::isOpen($company, $now)) {
            $thread->update(['silent_remind_at' => BusinessHours::nextOpen($company, $now)]);

            return false;
        }

        $thread->update(['silent_reminded_at' => $now, 'silent_remind_at' => null]);
        $ours = Message::query()->with(['customer', 'user'])->find($thread->silent_message_id);

        if ($ours === null || $ours->no_reply_expected) {
            return false;
        }

        $name = $ours->customer?->display_name ?? PhoneNumber::display($thread->phone, $company->country);
        $hours = max(1, (int) round($thread->silent_since->diffInMinutes($now) / 60));
        $title = trans_choice('automation.quiet.title', $hours, ['name' => $name, 'hours' => $hours]);
        $body = mb_strimwidth($ours->body, 0, 90, '…');
        $chat = fn (array $query) => route('messages.index', ['phone' => $thread->phone, ...$query]);
        $buttons = [
            'again' => ['title' => __('automation.quiet.again'), 'url' => $chat(['nudge' => 1])],
            'call' => ['title' => __('automation.quiet.call'), 'url' => $chat(['call' => 1])],
            'not_waiting' => ['title' => __('automation.quiet.not_waiting'), 'op' => 'not_waiting', 'message_id' => $ours->id],
        ];
        $people = $ours->user !== null && $ours->user->is_active ? [$ours->user] : $this->office();

        foreach ($people as $user) {
            try {
                PushActions::notify($user, 'sms_quiet', $title, $body, $chat([]), $buttons);
            } catch (Throwable $e) {
                Log::warning('Quiet reminder failed', ['error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /**
     * Customers still quiet after their reminder on an earlier day, for the morning SMS (each listed once).
     *
     * @return list<array{phone: string, name: string, since: CarbonImmutable}>
     */
    public static function quietForMorning(Company $company, CarbonImmutable $todayStart, bool $markReported): array
    {
        $threads = SmsThread::query()->whereNotNull('silent_since')->whereNotNull('silent_reminded_at')
            ->where('silent_reminded_at', '<', $todayStart->utc())->whereNull('silent_reported_on')
            ->orderBy('silent_since')->limit(20)->get();

        $rows = $threads->map(function (SmsThread $thread) use ($company) {
            $ours = Message::query()->with('customer')->find($thread->silent_message_id);

            return [
                'phone' => $thread->phone,
                'name' => $ours?->customer?->display_name ?? PhoneNumber::display($thread->phone, $company->country),
                'since' => CarbonImmutable::instance($thread->silent_since),
            ];
        })->values()->all();

        if ($markReported && $threads->isNotEmpty()) {
            SmsThread::query()->whereKey($threads->modelKeys())->update(['silent_reported_on' => $todayStart->toDateString()]);
        }

        return $rows;
    }

    /**
     * The people who answer texts: active Owners and Admins.
     *
     * @return iterable<User>
     */
    private function office(): iterable
    {
        return User::query()->where('is_active', true)
            ->whereHas('memberships', fn ($q) => $q->where('company_id', currentCompany()->id)->where('is_active', true)
                ->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value]))
            ->get();
    }

    /**
     * Unread incoming texts per customer for this person (the "new message" badge on job cards).
     *
     * @param  list<int>  $customerIds
     * @return array<int, int>
     */
    public static function unreadByCustomer(User $user, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return SmsInbox::unread($user)->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, count(*) as total')->groupBy('customer_id')
            ->pluck('total', 'customer_id')->map(fn ($total) => (int) $total)->all();
    }

    /**
     * Marks every incoming text of the customer read for this person (opening the job page of an open job).
     */
    public static function markCustomerRead(User $user, int $customerId): void
    {
        $unread = SmsInbox::unread($user)->where('customer_id', $customerId)->get(['messages.id', 'messages.from']);
        $now = now();

        MessageRead::query()->insertOrIgnore($unread->map(fn (Message $message) => [
            'company_id' => currentCompany()->id, 'user_id' => $user->id, 'message_id' => $message->id,
            'read_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }
}
