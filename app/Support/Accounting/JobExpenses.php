<?php

namespace App\Support\Accounting;

use App\Enums\UserRole;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * "My expenses" of a job (owner's task of November 24): what the company paid itself for the job (a part bought on the
 * way, parking). Every line is an ordinary business expense linked to the job (service_job_id), so it is the same
 * record in the job and in Expenses & taxes — nothing is counted twice. Never on anything the customer sees.
 * The Owner sees every line; a technician assigned to the job sees and adds only their own.
 */
class JobExpenses
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function canSee(User $user, ServiceJob $job): bool
    {
        if ($user->hasRole(UserRole::Owner)) {
            return true;
        }

        return Gate::forUser($user)->allows('create', BusinessExpense::class) && $job->isAssigned($user);
    }

    /**
     * The block on the job page, or null when this person may not see it.
     *
     * @return array{currency: string, total: int, can_add: bool, lines: list<array<string, mixed>>}|null
     */
    public static function present(User $user, ServiceJob $job): ?array
    {
        if (! self::canSee($user, $job)) {
            return null;
        }

        $lines = BusinessExpense::query()->where('service_job_id', $job->id)
            ->when(! $user->hasRole(UserRole::Owner), fn ($q) => $q->where('created_by', $user->id))
            ->with('creator')->orderBy('spent_on')->orderBy('id')->get();

        return [
            'currency' => currentCompany()->currency,
            'total' => (int) $lines->where('currency', currentCompany()->currency)->sum(fn (BusinessExpense $e) => $e->total()),
            'can_add' => ! $job->trashed(),
            'lines' => $lines->map(fn (BusinessExpense $e) => [
                'id' => $e->id,
                'description' => $e->description,
                'amount' => $e->total(),
                'currency' => $e->currency,
                'spent_on' => $e->spent_on->toDateString(),
                'by' => $e->created_by !== $user->id ? $e->creator?->name : null,
                'can_edit' => Gate::forUser($user)->allows('update', $e),
            ])->values()->all(),
        ];
    }

    /**
     * Saves the lines as business expenses of the job. Amounts are in minor units and are what was paid (taxes
     * included); a line without a name gets "Expenses for job #N".
     *
     * @param  list<array{description?: string|null, amount: int}>  $lines
     * @return list<BusinessExpense>
     */
    public function add(ServiceJob $job, User $user, array $lines): array
    {
        $saved = [];
        $today = CarbonImmutable::now(currentCompany()->timezone)->toDateString();

        foreach ($lines as $line) {
            if ((int) $line['amount'] < 0) {
                continue;
            }
            $expense = new BusinessExpense([
                'category_id' => $this->category($user)->id,
                'service_job_id' => $job->id,
                'spent_on' => $today,
                'description' => self::description($line['description'] ?? null, $job),
                'amount' => (int) $line['amount'],
                'tax_amount' => 0,
                'taxes' => null,
            ]);
            $expense->forceFill(['currency' => currentCompany()->currency, 'created_by' => $user->id])->save();
            $this->audit->record('business_expense.created', $expense, ['before' => null, 'after' => $expense->getAttributes()]);
            $saved[] = $expense;
        }

        return $saved;
    }

    public function update(BusinessExpense $expense, ?string $description, int $amount): void
    {
        $before = $expense->getAttributes();
        // What was paid: taxes the office split off before are dropped, the line is the amount typed.
        $expense->fill([
            'description' => self::description($description, $expense->job),
            'amount' => $amount,
            'tax_amount' => 0,
            'taxes' => null,
        ])->save();
        $this->audit->record('business_expense.updated', $expense, ['before' => $before, 'after' => $expense->getAttributes()]);
    }

    public static function description(?string $description, ?ServiceJob $job): string
    {
        $description = mb_substr(trim((string) $description), 0, 255);

        return $description !== '' ? $description : __('expenses.job_line_default', ['number' => $job?->number ?? '']);
    }

    /** The category the job's lines go to ("Job expenses"), made on first use. */
    private function category(User $user): BusinessExpenseCategory
    {
        $name = __('expenses.job_category');
        $category = BusinessExpenseCategory::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['name' => $name]);
        if ($category->wasRecentlyCreated) {
            $category->forceFill(['created_by' => $user->id])->save();
        }

        return $category;
    }
}
