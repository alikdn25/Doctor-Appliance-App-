<?php

namespace App\Console\Commands;

use App\Messaging\Messenger;
use App\Messaging\ReviewRequests;
use App\Models\Company;
use App\Models\Message;
use App\Models\ReviewRequest;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: SMS held back by the quiet hours (and any whose queue job was lost) and review requests whose
 * delay is over.
 */
#[Signature('messages:deliver-due')]
#[Description('Send scheduled SMS and review requests that are due')]
class DeliverDueMessages extends Command
{
    public function handle(Messenger $messenger, ReviewRequests $reviews, CurrentCompany $tenancy): int
    {
        $sms = Message::withoutCompanyScope()->where('status', 'scheduled')->where('send_after', '<=', now())->orderBy('id')->limit(500)->get();
        $requests = ReviewRequest::withoutCompanyScope()->where('status', ReviewRequest::SCHEDULED)->where('send_after', '<=', now())->orderBy('id')->limit(500)->get();
        $companies = Company::query()->whereIn('id', $sms->pluck('company_id')->merge($requests->pluck('company_id'))->unique())->get()->keyBy('id');

        foreach ($sms as $message) {
            $tenancy->runAs($companies[$message->company_id], fn () => $messenger->deliver(Message::query()->findOrFail($message->id)));
        }

        foreach ($requests as $request) {
            $tenancy->runAs($companies[$request->company_id], fn () => $reviews->deliver(ReviewRequest::query()->with('job.customer', 'profile')->findOrFail($request->id)));
        }

        return self::SUCCESS;
    }
}
