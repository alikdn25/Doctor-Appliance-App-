<?php

namespace App\Actions\Billing;

use App\Enums\EstimateStatus;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\InvoicePaymentLink;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentProviders;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A signed estimate is never changed: "Revise" makes a new version (a copy, back to draft, with its own number and
 * online link) and the signed one stays read-only in the history. A deposit already paid moves to the new version.
 * Must run in a tenant context.
 */
class ReviseEstimate
{
    public function __construct(
        private readonly PaymentProviders $providers,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Estimate $estimate, User $user): Estimate
    {
        $revision = DB::transaction(function () use ($estimate, $user) {
            $old = Estimate::query()->lockForUpdate()->findOrFail($estimate->id);

            if (! self::canBeRevised($old)) {
                throw ValidationException::withMessages(['estimate' => __('estimates.errors_revise')]);
            }

            $new = $old->replicate([
                'number', 'status', 'public_token', 'sent_at', 'sent_to', 'viewed_at', 'approved_at', 'declined_at',
                'signer_name', 'signature_type', 'signature_path', 'approved_ip', 'approved_user_agent',
                'decline_reason', 'declined_ip', 'revised_at', 'revised_by', 'created_by',
            ]);
            $new->status = EstimateStatus::Draft;
            $new->revision = $old->revision + 1;
            $new->revision_root_id = $old->revision_root_id ?? $old->id;
            $new->revised_from_id = $old->id;
            $new->number = $this->number($old);
            $new->created_by = $user->id;

            // An expired estimate gets a fresh "valid until".
            $days = currentCompany()->estimate_valid_days;
            if ($old->isExpired() && $days) {
                $new->valid_until = CarbonImmutable::now(currentCompany()->timezone)->addDays($days)->toDateString();
            }

            $new->save();

            $old->items->each(fn (EstimateItem $item) => $new->items()->create(
                $item->only(['position', 'description', 'quantity', 'unit_price', 'taxable', 'total', 'optional', 'selected', ...EstimateItem::LINE_FIELDS]),
            ));

            // The deposit belongs to the current version.
            Payment::query()->where('estimate_id', $old->id)->whereNull('invoice_id')->update(['estimate_id' => $new->id]);
            $links = InvoicePaymentLink::query()->where('estimate_id', $old->id)->where('status', InvoicePaymentLink::ACTIVE)->get();
            foreach ($links as $link) {
                $link->update(['status' => InvoicePaymentLink::REPLACED]);
                $this->providers->find($link->provider)?->cancelPaymentLink(currentCompany(), $link->provider_link_id);
            }

            $old->forceFill(['status' => EstimateStatus::Revised, 'revised_at' => now(), 'revised_by' => $user->id])->save();

            $this->audit->record('estimate.revised', $old, ['number' => $old->number, 'new_number' => $new->number]);

            return $new;
        });

        return $revision;
    }

    /**
     * Only the customer's signature locks an estimate; once invoiced it is final.
     */
    public static function canBeRevised(Estimate $estimate): bool
    {
        return $estimate->status === EstimateStatus::Approved && $estimate->approvedOnline() && ! $estimate->trashed();
    }

    /**
     * EST-1001 → EST-1001-R2 → EST-1001-R3 (taken numbers are skipped).
     */
    private function number(Estimate $old): string
    {
        $base = preg_replace('/-R\d+$/', '', $old->number);
        $revision = $old->revision + 1;

        while (Estimate::query()->withTrashed()->where('number', "{$base}-R{$revision}")->exists()) {
            $revision++;
        }

        return "{$base}-R{$revision}";
    }
}
