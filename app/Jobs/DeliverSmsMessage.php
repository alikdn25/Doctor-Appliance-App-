<?php

namespace App\Jobs;

use App\Messaging\Messenger;
use App\Models\Company;
use App\Models\Message;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a scheduled SMS (delayed until the quiet hours end). The minute command messages:deliver-due also
 * picks up anything whose job was lost.
 */
class DeliverSmsMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId, public int $companyId) {}

    public function handle(Messenger $messenger, CurrentCompany $tenancy): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($messenger) {
            $message = Message::query()->find($this->messageId);

            if ($message !== null && ($message->send_after === null || $message->send_after->lessThanOrEqualTo(now()))) {
                $messenger->deliver($message);
            }
        });
    }
}
