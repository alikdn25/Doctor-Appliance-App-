<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CashLedger;
use App\Enums\OfficePermission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Models\CashMovement;
use App\Models\Membership;
use App\Models\User;
use App\Support\Jobs\JobPresenter;
use App\Support\Locale\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash on hand (office): what each person collected in cash and has not handed in yet, the journal of every
 * movement, recording a cash deposit to the office, and reversing a wrong entry with a reason.
 */
class CashController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeOffice($request->user());

        $balances = CashLedger::balances();
        $people = Membership::query()->with('user')->get()
            ->filter(fn (Membership $m) => $m->user !== null && ($m->is_active || isset($balances[$m->user_id])))
            ->map(fn (Membership $m) => [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'balances' => $balances[$m->user_id] ?? [],
            ])
            ->sortBy('name')
            ->values();

        $reversed = CashMovement::query()->whereNotNull('reverses_id')->pluck('reverses_id')->all();

        return Inertia::render('cash/index', [
            'people' => $people,
            'currency' => currentCompany()->currency,
            'acceptsCash' => currentCompany()->accepts_cash,
            'today' => CarbonImmutable::now(currentCompany()->timezone)->toDateString(),
            'movements' => CashMovement::query()
                ->with(['user', 'creator', 'payment.invoice'])
                ->when($request->integer('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (CashMovement $m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'type_label' => __("cash.types.{$m->type}"),
                    'amount' => $m->amount,
                    'currency' => $m->currency,
                    'occurred_on' => $m->occurred_on->toDateString(),
                    'user' => $m->user?->name,
                    'by' => $m->creator?->name,
                    'note' => $m->note,
                    'invoice' => $m->payment?->invoice ? ['id' => $m->payment->invoice->id, 'number' => $m->payment->invoice->number] : null,
                    'created_at' => JobPresenter::iso($m->created_at),
                    'can_reverse' => $m->type !== CashMovement::REVERSAL && ! in_array($m->id, $reversed, true),
                ]),
        ]);
    }

    public function deposit(Request $request, CashLedger $ledger): RedirectResponse
    {
        $this->authorizeOffice($request->user());

        $currency = currentCompany()->currency;
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', DocumentRequest::moneyRule($currency)],
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => __('payments.fields.amount')]);

        $holder = User::query()->whereHas('memberships', fn ($q) => $q->where('company_id', currentCompany()->id))->find($data['user_id']);
        abort_if($holder === null, 404);

        $ledger->deposit($holder, Currencies::toMinor($data['amount'], $currency), $currency, CarbonImmutable::parse($data['date']), $data['note'] ?? null, $request->user());

        return back();
    }

    public function reverse(Request $request, CashMovement $movement, CashLedger $ledger): RedirectResponse
    {
        $this->authorizeOffice($request->user());

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $ledger->reverse($movement, $reason, $request->user());

        return back();
    }

    private function authorizeOffice(User $user): void
    {
        abort_unless($user->hasRole(UserRole::Owner, UserRole::Admin) && $user->canOffice(OfficePermission::Reports), 403);
    }
}
