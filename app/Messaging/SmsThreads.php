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
use App\Notifications\AppAlert;
use App\Support\Locale\BusinessHours;
use App\Support\PhoneNumber;
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
 * Must run in a tenant context, except the scheduled sweeps, which visit each company in turn.
 */
class SmsThreads
{
    public const AUTO_REPLY_GAP_HOURS = 24;

    public const TEXT = 'text';

    public const MISSED_CALL = 'missed_call';

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
     * @return array{auto_replies: int, reminders: int}
     */
    public function sweep(): array
    {
        $counts = ['auto_replies' => 0, 'reminders' => 0];
        $companyIds = SmsThread::withoutCompanyScope()
            ->where(fn ($q) => $q->where('auto_reply_due_at', '<=', now())->orWhere('next_reminder_at', '<=', now()))
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
        $alert = new AppAlert(
            'sms_unanswered',
            __('automation.reminders.title', ['name' => $name]),
            __('automation.reminders.body', ['text' => mb_substr((string) $latest?->body, 0, 120)]),
            route('messages.index', ['phone' => $thread->phone]),
            mail: false,
        );

        foreach ($this->office() as $user) {
            try {
                $user->notify($alert);
            } catch (Throwable $e) {
                Log::warning('Reminder delivery failed', ['error' => $e->getMessage()]);
            }
        }

        return true;
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
