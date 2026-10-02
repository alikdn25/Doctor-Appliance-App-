<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\SmsAccount;
use App\Sms\SmsProvider;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A short text to a staff member from the company's SMS number (e.g. "estimate approved"). Staff texts are not
 * customer messages, so they are not recorded on a customer; failures are only logged.
 */
class SendOfficeSms implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId, public string $to, public string $body) {}

    public function handle(SmsProvider $sms, CurrentCompany $tenancy): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($sms) {
            $account = SmsAccount::query()->whereNotNull('phone_number')->first();

            if ($account === null) {
                return;
            }

            try {
                $sms->send($account, $this->to, $this->body);
            } catch (Throwable $e) {
                Log::warning('Office SMS not sent', ['company_id' => $this->companyId, 'error' => $e->getMessage()]);
            }
        });
    }
}
