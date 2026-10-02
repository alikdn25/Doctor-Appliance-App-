<?php

namespace App\Actions\Billing;

use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash on hand per technician: cash payments they record add to it, cash they hand to the office (recorded by an
 * Owner/Admin) takes from it. Nothing is deleted: a wrong entry is reversed with a reason. Must run in a tenant
 * context.
 */
class CashLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function collected(Payment $payment, User $holder): CashMovement
    {
        return CashMovement::query()->create([
            'user_id' => $holder->id,
            'type' => CashMovement::COLLECTED,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'occurred_on' => $payment->received_at->copy()->setTimezone(currentCompany()->timezone)->toDateString(),
            'payment_id' => $payment->id,
            'created_by' => $holder->id,
        ]);
    }

    /**
     * A voided cash payment takes the cash back off the technician who held it.
     */
    public function paymentVoided(Payment $payment, User $by): void
    {
        $movement = CashMovement::query()->where('payment_id', $payment->id)->where('type', CashMovement::COLLECTED)->first();

        if ($movement !== null && ! CashMovement::query()->where('reverses_id', $movement->id)->exists()) {
            $this->reverse($movement, (string) ($payment->void_reason ?: __('cash.payment_voided')), $by);
        }
    }

    public function deposit(User $holder, int $amount, string $currency, CarbonInterface $on, ?string $note, User $by): CashMovement
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('payments.errors.amount_required')]);
        }

        $movement = CashMovement::query()->create([
            'user_id' => $holder->id,
            'type' => CashMovement::DEPOSIT,
            'amount' => -$amount,
            'currency' => $currency,
            'occurred_on' => $on->toDateString(),
            'note' => $note,
            'created_by' => $by->id,
        ]);
        $this->audit->record('cash.deposit', $movement, ['user_id' => $holder->id, 'amount' => $amount]);

        return $movement;
    }

    public function reverse(CashMovement $movement, string $reason, User $by): CashMovement
    {
        return DB::transaction(function () use ($movement, $reason, $by) {
            $movement = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);

            if ($movement->type === CashMovement::REVERSAL || CashMovement::query()->where('reverses_id', $movement->id)->exists()) {
                throw ValidationException::withMessages(['reason' => __('cash.already_reversed')]);
            }

            $reversal = CashMovement::query()->create([
                'user_id' => $movement->user_id,
                'type' => CashMovement::REVERSAL,
                'amount' => -$movement->amount,
                'currency' => $movement->currency,
                'occurred_on' => now(currentCompany()->timezone)->toDateString(),
                'reverses_id' => $movement->id,
                'note' => $reason,
                'created_by' => $by->id,
            ]);
            $this->audit->record('cash.reversed', $movement, ['reason' => $reason, 'amount' => $movement->amount]);

            return $reversal;
        });
    }

    /**
     * Cash on hand per person and currency.
     *
     * @return array<int, array<string, int>>
     */
    public static function balances(): array
    {
        $rows = CashMovement::query()->selectRaw('user_id, currency, sum(amount) as total')->groupBy('user_id', 'currency')->get();
        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row->user_id][$row->currency] = (int) $row->total;
        }

        return $balances;
    }
}
