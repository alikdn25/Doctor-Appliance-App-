<?php

namespace App\Messaging;

use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\PublicDocument;
use App\Support\Jobs\JobPresenter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Messaging data for the React pages: the company's SMS mode, whether a text can go to the customer,
 * the ready-made texts for the technician's phone, and the message history. Must run in a tenant context.
 */
class MessagingPresenter
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly ReviewRequests $reviews,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forJob(ServiceJob $job, User $user, ?JobVisit $myVisit): array
    {
        $company = currentCompany();
        $customer = $job->customer;
        $phone = $this->messenger->mobile($customer);
        $context = MessageContext::for($customer, $job, $myVisit ?? $job->visits->sortBy('scheduled_start')->last(), $user);

        return [
            'mode' => $company->sms_mode->value,
            'phone' => $phone?->number,
            'opted_out' => $phone?->sms_opted_out_at !== null,
            'sms_blocked' => $company->sms_mode === SmsMode::Automatic ? $this->messenger->smsBlockedReason($customer) : null,
            'texts' => [
                'general' => MessageTemplates::render($company, MessageKind::General, $context),
                'on_my_way' => MessageTemplates::render($company, MessageKind::OnMyWay, $context),
            ],
            'messages' => $this->history(Message::query()->where('service_job_id', $job->id)),
        ];
    }

    /**
     * The Google review request at the end of the work: shown on the paid invoice, next to sending the receipt.
     * The technician always chooses the location (Google profile); those of the job's brand and brand-free ones are offered.
     *
     * @return array<string, mixed>
     */
    public function review(ServiceJob $job): array
    {
        $review = ReviewRequest::query()->where('service_job_id', $job->id)->first();
        $phone = $this->messenger->mobile($job->customer);

        return [
            'job_id' => $job->id,
            'mode' => currentCompany()->sms_mode->value,
            'status' => $review ? $this->reviewStatus($review) : null,
            'sent' => $review?->status === ReviewRequest::SENT,
            'phone' => $phone?->sms_opted_out_at === null ? $phone?->number : null,
            'can_email' => $job->customer->emails()->exists(),
            // {review_link} is replaced with the chosen link before sending.
            'text' => $this->reviews->text($job, null),
            'locations' => GoogleProfile::query()
                ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $job->brand_id))
                ->orderBy('label')->get(['id', 'label', 'review_url'])
                ->map(fn (GoogleProfile $profile) => ['id' => $profile->id, 'label' => $profile->label, 'url' => $profile->review_url])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forCustomer(Customer $customer, User $user): array
    {
        $query = Message::query()->where('customer_id', $customer->id)
            ->where(function (Builder $query) use ($customer, $user) {
                $query->whereIn('service_job_id', ServiceJob::query()->visibleTo($user)->select('id'));
                // Customer-level correspondence is office-only; technicians see assigned-job messages.
                if ($user->can('update', $customer)) {
                    $query->orWhereNull('service_job_id');
                }
            });

        return [
            'mode' => currentCompany()->sms_mode->value,
            'messages' => $this->history($query),
        ];
    }

    /**
     * "Send by SMS" next to "Send by email" on an estimate or invoice.
     *
     * @return array<string, mixed>|null Null in Off mode (documents go by email only)
     */
    public function forDocument(Estimate|Invoice $document): ?array
    {
        $company = currentCompany();

        if ($company->sms_mode === SmsMode::Off) {
            return null;
        }

        $document->loadMissing(['customer', 'job']);
        $phone = $this->messenger->mobile($document->customer);

        return [
            'mode' => $company->sms_mode->value,
            'phone' => $phone?->number,
            'blocked' => $company->sms_mode === SmsMode::Automatic ? $this->messenger->smsBlockedReason($document->customer) : ($phone === null ? __('messages.blocked.no_phone') : null),
            'text' => self::documentText($document),
            'url' => route($document instanceof Invoice ? 'invoices.sms' : 'estimates.sms', $document),
            'opened_url' => route('jobs.messages.opened', $document->service_job_id),
            'kind' => ($document instanceof Invoice ? MessageKind::InvoiceLink : MessageKind::EstimateLink)->value,
        ];
    }

    public static function documentText(Estimate|Invoice $document): string
    {
        $company = currentCompany();
        $document->loadMissing(['customer', 'job']);
        $invoice = $document instanceof Invoice;

        return MessageTemplates::render($company, $invoice ? MessageKind::InvoiceLink : MessageKind::EstimateLink, [
            ...MessageContext::for($document->customer, $document->job),
            'number' => $document->number,
            'amount' => Money::format($invoice ? $document->balance : $document->total, $document->currency, $company->locale),
            'link' => PublicDocument::url($document),
        ]);
    }

    public function reviewStatus(ReviewRequest $review): string
    {
        return match ($review->status) {
            ReviewRequest::SCHEDULED => __('reviews.statuses.scheduled', ['date' => MessageContext::time($review->send_after)]),
            ReviewRequest::SENT => __('reviews.statuses.sent', ['date' => MessageContext::date($review->sent_at)]),
            default => __('reviews.statuses.skipped', ['reason' => $review->skip_reason]),
        };
    }

    /**
     * @param  Builder<Message>  $query
     * @return list<array<string, mixed>>
     */
    private function history($query): array
    {
        return $query->with(['user', 'job'])->latest('id')->limit(50)->get()->map(fn (Message $m) => self::item($m))->values()->all();
    }

    public static function item(Message $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'channel' => $m->channel,
            'channel_label' => __("messages.channels.{$m->channel}"),
            'kind_label' => $m->kind->label(),
            'body' => $m->body,
            'status' => $m->status,
            'status_label' => __("messages.statuses.{$m->status}"),
            'status_reason' => $m->status_reason,
            'to' => $m->to,
            'from' => $m->from,
            'user' => $m->user?->name,
            'at' => JobPresenter::iso($m->sent_at ?? $m->send_after ?? $m->created_at),
            'job_id' => $m->job?->trashed() ? null : $m->service_job_id,
        ];
    }
}
