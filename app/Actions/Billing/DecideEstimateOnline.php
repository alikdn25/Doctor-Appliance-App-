<?php

namespace App\Actions\Billing;

use App\Enums\EstimateStatus;
use App\Messaging\OfficeNotifier;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Services\AuditLogger;
use App\Support\PrivateMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The customer approves (with a signature) or declines an estimate on its online page (SPEC §7.5).
 * On approval the optional lines they picked are included and the totals and deposit recalculated.
 * The office is told either way. Must run in the estimate's company.
 */
class DecideEstimateOnline
{
    /** Largest drawn signature accepted (PNG bytes). */
    public const MAX_SIGNATURE_BYTES = 512 * 1024;

    public function __construct(
        private readonly SaveBillingDocument $documents,
        private readonly OfficeNotifier $office,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $selectedItemIds  Optional lines the customer wants
     * @param  string|null  $signaturePng  Raw PNG of a drawn signature; null when the name is typed
     */
    public function approve(
        Estimate $estimate,
        array $selectedItemIds,
        string $signerName,
        ?string $signaturePng,
        ?string $ip,
        ?string $userAgent,
    ): Estimate {
        if ($signaturePng !== null && (strlen($signaturePng) > self::MAX_SIGNATURE_BYTES || ! str_starts_with($signaturePng, "\x89PNG\r\n\x1a\n"))) {
            throw ValidationException::withMessages(['signature' => __('estimates.online.errors.signature')]);
        }

        $estimate = DB::transaction(function () use ($estimate, $selectedItemIds, $signerName, $signaturePng, $ip, $userAgent) {
            $estimate = $this->lockOpen($estimate);

            if ($estimate->isExpired()) {
                throw ValidationException::withMessages(['estimate' => __('estimates.online.errors.expired')]);
            }

            $selected = array_map('intval', $selectedItemIds);
            $estimate->items->each(function (EstimateItem $item) use ($selected) {
                if ($item->optional) {
                    $item->update(['selected' => in_array($item->id, $selected, true)]);
                }
            });
            $this->documents->recalculate($estimate);

            $path = null;
            if ($signaturePng !== null) {
                $path = "companies/{$estimate->company_id}/estimates/{$estimate->id}/signature-".Str::uuid().'.png';
                PrivateMedia::disk()->put($path, $signaturePng);
            }

            $estimate->forceFill([
                'status' => EstimateStatus::Approved,
                'approved_at' => now(),
                'declined_at' => null,
                'decline_reason' => null,
                'declined_ip' => null,
                'signer_name' => Str::limit(trim($signerName), 100, ''),
                'signature_type' => $path !== null ? Estimate::SIGNATURE_DRAWN : Estimate::SIGNATURE_TYPED,
                'signature_path' => $path,
                'approved_ip' => $ip,
                'approved_user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
            ])->save();

            $this->audit->record('estimate.approved_online', $estimate, [
                'number' => $estimate->number,
                'total' => $estimate->total,
                'signer' => $estimate->signer_name,
            ]);

            return $estimate;
        });

        $this->office->estimateDecided($estimate);

        return $estimate;
    }

    public function decline(Estimate $estimate, ?string $reason, ?string $ip): Estimate
    {
        $estimate = DB::transaction(function () use ($estimate, $reason, $ip) {
            $estimate = $this->lockOpen($estimate);

            if ($estimate->status === EstimateStatus::Declined) {
                throw ValidationException::withMessages(['estimate' => __('estimates.online.errors.decided')]);
            }

            $estimate->forceFill([
                'status' => EstimateStatus::Declined,
                'declined_at' => now(),
                'approved_at' => null,
                'decline_reason' => filled($reason) ? trim((string) $reason) : null,
                'declined_ip' => $ip,
            ])->save();

            $this->audit->record('estimate.declined_online', $estimate, [
                'number' => $estimate->number,
                'reason' => $estimate->decline_reason,
            ]);

            return $estimate;
        });

        $this->office->estimateDecided($estimate);

        return $estimate;
    }

    private function lockOpen(Estimate $estimate): Estimate
    {
        $estimate = Estimate::query()->lockForUpdate()->findOrFail($estimate->id);

        if (! $estimate->awaitsCustomer()) {
            throw ValidationException::withMessages(['estimate' => __('estimates.online.errors.decided')]);
        }

        return $estimate;
    }
}
