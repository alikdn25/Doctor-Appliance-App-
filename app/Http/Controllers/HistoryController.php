<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Support\Audit\HistoryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The change log, for the Owner only: the history of one record (on its page) and the whole company's log
 * (Settings → Change log).
 */
class HistoryController extends Controller
{
    /** type in the URL => model class */
    public const TYPES = [
        'customer' => Customer::class,
        'job' => ServiceJob::class,
        'estimate' => Estimate::class,
        'invoice' => Invoice::class,
    ];

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeOwner($request);
        abort_unless(isset(self::TYPES[$type]), 404);

        $class = self::TYPES[$type];
        // The record must be the company's own (the global scope); deleted ones keep their history.
        $record = $class::withTrashed()->findOrFail($id);

        $logs = $this->companyLogs()
            ->where(function (Builder $query) use ($record, $type) {
                $query->where(fn (Builder $q) => $q->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->getKey()));

                // An invoice's history includes its payments.
                if ($type === 'invoice') {
                    $paymentIds = Payment::withTrashed()->where('invoice_id', $record->getKey())->pluck('id');
                    $query->orWhere(fn (Builder $q) => $q->where('auditable_type', (new Payment)->getMorphClass())->whereIn('auditable_id', $paymentIds));
                }
            })
            ->limit(200)
            ->get();

        return response()->json(['entries' => $logs->map(fn (AuditLog $log) => HistoryPresenter::entry($log))->values()]);
    }

    public function index(Request $request): Response
    {
        $this->authorizeOwner($request);

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['customer', 'job', 'estimate', 'invoice', 'payment'])],
            'source' => ['nullable', Rule::in(['web', 'bot', 'public', 'console'])],
            'user' => ['nullable', 'integer'],
        ]);

        $logs = $this->companyLogs()
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('action', 'like', "{$type}.%"))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['user'] ?? null, fn (Builder $q, int $user) => $q->where('user_id', $user))
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('company/change-log', [
            'entries' => $logs->through(fn (AuditLog $log) => HistoryPresenter::entry($log)),
            'filters' => [
                'type' => $filters['type'] ?? null,
                'source' => $filters['source'] ?? null,
                'user' => isset($filters['user']) ? (int) $filters['user'] : null,
            ],
            'people' => currentCompany()->users()->orderBy('name')->get(['users.id', 'users.name'])
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values(),
        ]);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function companyLogs(): Builder
    {
        return AuditLog::query()
            ->where('company_id', currentCompany()->id)
            ->with(['user', 'impersonator'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()?->hasRole(UserRole::Owner), 403);
    }
}
