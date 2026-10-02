<?php

namespace App\Messaging;

use App\Enums\EstimateStatus;
use App\Enums\SmsMode;
use App\Enums\UserRole;
use App\Jobs\SendOfficeSms;
use App\Models\Estimate;
use App\Models\Membership;
use App\Models\SmsAccount;
use App\Models\User;
use App\Notifications\EstimateDecided;
use App\Sms\SmsProvider;
use App\Support\Billing\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * Tells the office (Owners and Admins who work for the document's brand) about things customers do online:
 * an email always, plus a text when the company's SMS mode is Automatic (approvals only; texts wait for the end of
 * the quiet hours). Must run in a tenant context.
 */
class OfficeNotifier
{
    public function __construct(
        private readonly SmsProvider $sms,
        private readonly Messenger $messenger,
    ) {}

    public function estimateDecided(Estimate $estimate): void
    {
        $estimate->loadMissing(['customer', 'brand']);
        $company = currentCompany();
        $approved = $estimate->status === EstimateStatus::Approved;
        $money = fn (int $minor) => Money::format($minor, $estimate->currency, $company->locale);

        $details = [
            'number' => $estimate->number,
            'customer' => (string) $estimate->customer?->display_name,
            'total' => $money($estimate->total),
            'deposit' => $estimate->deposit_amount > 0 ? $money($estimate->deposit_amount) : null,
            'signer' => $estimate->signer_name,
            'reason' => $estimate->decline_reason,
            'brand' => $estimate->brand?->name ?? $company->name,
        ];
        $url = route('estimates.show', $estimate);
        $recipients = $this->recipients($estimate);

        foreach ($recipients as $user) {
            $user->notify(new EstimateDecided($approved, $details, $url));
        }

        if ($approved && $company->sms_mode === SmsMode::Automatic && $this->sms->isConfigured()
            && SmsAccount::query()->whereNotNull('phone_number')->exists()) {
            $body = __('notifications.estimate_approved.sms', array_map(fn (?string $value) => (string) $value, [...$details, 'url' => $url]));

            foreach ($recipients as $user) {
                $to = PhoneNumber::normalize($user->phone, $company->country);

                if ($to !== '' && ! $this->messenger->registrationBlocks($to)) {
                    SendOfficeSms::dispatch($company->id, $to, $body)->delay(QuietHours::nextAllowed($company));
                }
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Estimate $estimate): Collection
    {
        return Membership::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value])
            ->with('user')
            ->get()
            ->map(fn (Membership $membership) => $membership->user)
            ->filter(function (?User $user) use ($estimate) {
                if ($user === null) {
                    return false;
                }

                $brands = $user->limitedBrandIds();

                return $brands === [] || in_array($estimate->brand_id, $brands, true);
            })
            ->values();
    }
}
