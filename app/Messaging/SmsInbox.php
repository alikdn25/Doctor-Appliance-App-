<?php

namespace App\Messaging;

use App\Enums\SmsMode;
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
        return Message::query()->visibleTo($user)->where('channel', Message::SMS)
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
        $latest = self::messages($user)->with(['customer', 'user'])
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
        abort_unless(self::forPhone($user, $phone)->exists(), 404);
        $recipient = $this->recipient($user, $phone);
        $messages = self::forPhone($user, $phone)->with('user')->latest('id')
            ->paginate(50, ['*'], 'message_page')->withQueryString();
        $unreadIds = self::unread($user)->whereIn('id', $messages->getCollection()->pluck('id'))->pluck('id')->all();
        $blocked = match (true) {
            currentCompany()->sms_mode !== SmsMode::Automatic => __('messages.inbox.automatic_required'),
            $recipient === null => __('messages.inbox.link_customer'),
            default => $this->messenger->smsBlockedReason($recipient->customer, $recipient),
        };

        return [
            'phone' => $phone,
            'customer' => $recipient ? $recipient->customer->only(['id', 'display_name']) : null,
            'blocked' => $blocked,
            'messages' => $messages->through(fn (Message $message) => MessagingPresenter::item($message)),
            'unread_ids' => $unreadIds,
        ];
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
