<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use App\Sms\SmsProvider;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily: asks the SMS provider whether submitted US A2P 10DLC registrations were approved or rejected.
 */
#[Signature('sms:sync-registrations')]
#[Description('Update A2P 10DLC registration statuses from the SMS provider')]
class SyncSmsRegistrations extends Command
{
    public function handle(SmsProvider $sms, CurrentCompany $tenancy): int
    {
        SmsRegistration::withoutCompanyScope()->where('status', SmsRegistration::SUBMITTED)->get()
            ->each(function (SmsRegistration $registration) use ($sms, $tenancy) {
                $company = Company::query()->find($registration->company_id);

                if ($company === null) {
                    return;
                }

                $tenancy->runAs($company, function () use ($sms, $registration) {
                    $account = SmsAccount::query()->first();

                    try {
                        $result = $account ? $sms->registrationStatus($registration, $account) : null;
                    } catch (Throwable $e) {
                        $this->error("Company {$registration->company_id}: {$e->getMessage()}");

                        return;
                    }

                    if ($result !== null) {
                        $registration->update([
                            'status' => $result['status'],
                            'rejection_reason' => $result['reason'],
                            'approved_at' => $result['status'] === SmsRegistration::APPROVED ? now() : null,
                        ]);
                    }
                });
            });

        return self::SUCCESS;
    }
}
