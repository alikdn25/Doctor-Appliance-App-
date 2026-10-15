<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Conversations are grouped by the actual phone number, including unknown callers. */
class SmsInbox
{
    private const PEER = "CASE WHEN messages.direction = 'inbound' THEN messages.\"from\" ELSE messages.\"to\" END";

    public function __construct(private readonly Messenger $messenger) {}

    public static function messages(User $user): Builder
    {
        // Texts from the company number, and those opened on a technician's phone (shown, replies go to the phone).
        return Message::query()->visibleTo($user)->whereIn('channel', [Message::SMS, Message::TECHNICIAN_PHONE])
            ->whereRaw(self::PEER.' IS NOT NULL');
    }

    public static function unread(User $user): Builder
    {
        return self::messages($user)->where('direction', Message::INBOUND)
            ->whereNotIn('messages.id', MessageRead::query()->where('user_id', $user->id)->select('message_id'));
    }

    public static function unreadCount(User $user): int
    {
        return self::unread($user)->count();
    }

    public static function forPhone(User $user, string $phone): Builder
    {
        return self::messages($user)->whereRaw(self::PEER.' = ?', [$phone]);
    }

    /** Texts with this number that the user may not see (another brand's job): never open them as a new chat. */
    public static function hiddenFrom(User $user, string $phone): bool
    {
        return ! self::forPhone($user, $phone)->exists()
            && Message::query()->whereIn('channel', [Message::SMS, Message::TECHNICIAN_PHONE])->whereRaw(self::PEER.' = ?', [$phone])->exists();
    }

    /** A number no customer has and no earlier text links to a customer: it can be texted as it is. */
    public function unlinked(User $user, string $phone): bool
    {
        return self::forPhone($user, $phone)->whereNotNull('customer_id')->doesntExist()
            && CustomerPhone::query()->with('customer')->where('number_normalized', $phone)->get()
                ->filter(fn (CustomerPhone $phone) => $phone->customer !== null)->isEmpty();
    }

    public function threads(User $user, string $search, bool $unreadOnly): LengthAwarePaginator
    {
        $query = self::messages($user);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $matching = self::messages($user)->selectRaw(self::PEER)->where(function (Builder $query) use ($like) {
                $query->where('body', 'ilike', $like)->orWhereRaw(self::PEER.' ILIKE ?', [$like])
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('display_name', 'ilike', $like));
            });
            // Filter whole conversations: retain their latest message and full unread count.
            $query->whereIn(DB::raw(self::PEER), $matching);
        }
        if ($unreadOnly) {
            $query->whereIn(DB::raw(self::PEER), self::unread($user)->selectRaw(self::PEER));
        }

        $threads = $query->selectRaw(self::PEER.' AS phone, MAX(messages.id) AS latest_id')
            ->selectRaw("SUM(CASE WHEN direction = 'inbound' AND NOT EXISTS (SELECT 1 FROM message_reads WHERE message_reads.message_id = messages.id AND message_reads.user_id = ? AND message_reads.company_id = ?) THEN 1 ELSE 0 END) AS unread_count", [$user->id, currentCompany()->id])
            ->groupByRaw(self::PEER)->orderByRaw('MAX(messages.id) DESC')->paginate(25)->withQueryString();
        $latest = self::messages($user)->with(['customer', 'user', 'job'])
            ->whereIn('id', $threads->getCollection()->pluck('latest_id'))->get()->keyBy('id');

        return $threads->through(function ($thread) use ($latest) {
            $message = $latest->get($thread->latest_id);

            return [
                'phone' => $thread->phone,
                'customer_name' => $message->customer?->display_name,
                'preview' => mb_substr($message->body, 0, 160),
                'unread_count' => (int) $thread->unread_count,
                'latest' => MessagingPresenter::item($message),
            ];
        });
    }

    /** Never guess between customers sharing a phone, or switch to their primary number. */
    public function recipient(User $user, string $phone): ?CustomerPhone
    {
        $customers = self::forPhone($user, $phone)->whereNotNull('customer_id')->select('customer_id');
        $phones = CustomerPhone::query()->with('customer')->where('number_normalized', $phone)
            ->get()->filter(fn (CustomerPhone $phone) => $phone->customer !== null);
        if ($phones->pluck('customer_id')->unique()->count() !== 1) {
            return null;
        }
        $recipient = $phones->first();

        return ! $customers->exists() || (clone $customers)->where('customer_id', $recipient->customer_id)->exists()
            ? $recipient : null;
    }

    public function job(User $user, string $phone, CustomerPhone $recipient): ?ServiceJob
    {
        $id = self::forPhone($user, $phone)->where('customer_id', $recipient->customer_id)
            ->whereNotNull('service_job_id')->latest('id')->value('service_job_id');

        return $id === null ? null : ServiceJob::query()->visibleTo($user)->find($id);
    }

    public function conversation(User $user, string $phone): array
    {
        // A number without texts yet opens as a new, empty conversation.
        abort_if(self::hiddenFrom($user, $phone), 404);
        $recipient = $this->recipient($user, $phone);
        $unlinked = $recipient === null && $this->unlinked($user, $phone);
        $messages = self::forPhone($user, $phone)->with(['user', 'job'])->latest('id')
            ->paginate(50, ['*'], 'message_page')->withQueryString();
        $unreadIds = self::unread($user)->whereIn('id', $messages->getCollection()->pluck('id'))->pluck('id')->all();
        $blocked = match (true) {
            currentCompany()->sms_mode !== SmsMode::Automatic => __('messages.inbox.automatic_required'),
            $unlinked => $this->messenger->numberBlockedReason($phone),
            $recipient === null => __('messages.inbox.link_customer'),
            default => $this->messenger->smsBlockedReason($recipient->customer, $recipient),
        };

        $job = $recipient ? $this->job($user, $phone, $recipient) : null;

        return [
            'phone' => $phone,
            'sms_mode' => currentCompany()->sms_mode->value,
            'customer' => $recipient ? $recipient->customer->only(['id', 'display_name']) : null,
            'job' => $job ? ['id' => $job->id, 'number' => $job->number] : null,
            'templates' => $recipient ? $this->templates($recipient->customer, $job, $user) : [],
            'blocked' => $blocked,
            'messages' => $messages->through(fn (Message $message) => MessagingPresenter::item($message)),
            'unread_ids' => $unreadIds,
        ];
    }

    /**
     * Older messages of a conversation, loaded while scrolling up (newest first, like the first page).
     *
     * @return list<array<string, mixed>>
     */
    public function older(User $user, string $phone, int $beforeId): array
    {
        abort_if(self::hiddenFrom($user, $phone), 404);

        return self::forPhone($user, $phone)->with(['user', 'job'])->where('messages.id', '<', $beforeId)
            ->latest('id')->limit(50)->get()
            ->map(fn (Message $message) => MessagingPresenter::item($message))->values()->all();
    }

    /**
     * Ready texts next to the reply box (On my way, Reminder, Invoice link, General), filled in for the customer and
     * their latest job.
     *
     * @return list<array{key: string, label: string, text: string}>
     */
    private function templates(Customer $customer, ?ServiceJob $job, User $user): array
    {
        $company = currentCompany();
        $visit = $job?->visits()->orderByDesc('scheduled_start')->first();
        $context = MessageContext::for($customer, $job, $visit, $user);
        $invoice = $job?->invoices()->outstanding()->where('balance', '>', 0)->latest('id')->first();
        $kinds = array_filter([
            $visit ? MessageKind::OnMyWay : null,
            $visit ? MessageKind::VisitReminder : null,
            MessageKind::General,
        ]);

        $templates = array_map(fn (MessageKind $kind) => [
            'key' => $kind->value,
            'label' => $kind->label(),
            'text' => MessageTemplates::render($company, $kind, $context),
        ], array_values($kinds));

        if ($invoice) {
            $templates[] = ['key' => MessageKind::InvoiceLink->value, 'label' => MessageKind::InvoiceLink->label(), 'text' => MessagingPresenter::documentText($invoice)];
        }

        return $templates;
    }

    public function markRead(User $user, string $phone, array $ids): void
    {
        $visibleIds = self::forPhone($user, $phone)->where('direction', Message::INBOUND)->whereIn('id', $ids)->pluck('id');
        $now = now();
        // Bulk insertion explicitly supplies the current tenant after filtering every message.
        MessageRead::query()->insertOrIgnore($visibleIds->map(fn (int $id) => [
            'company_id' => currentCompany()->id, 'user_id' => $user->id, 'message_id' => $id,
            'read_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }
}
