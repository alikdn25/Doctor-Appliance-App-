<?php

namespace App\Support\Parts;

use App\Enums\UserRole;
use App\Jobs\GeocodeAddress;
use App\Models\JobPart;
use App\Models\Membership;
use App\Models\Supplier;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Automatic "Buy at {supplier}: N parts" tasks (owner's task of November 19): one open task for today per supplier
 * that has parts in the Order list, with the supplier's address. When none of that supplier's parts is left to order
 * (bought or ordered), the task closes by itself. Code only. Must run in a tenant context.
 */
class SupplierTasks
{
    public static function sync(): void
    {
        $company = currentCompany();
        $today = CarbonImmutable::now($company->timezone)->toDateString();
        $counts = JobPart::query()
            ->where('status', JobPart::TO_ORDER)
            ->whereNotNull('supplier_id')
            ->whereHas('job', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->selectRaw('supplier_id, count(*) as parts')
            ->groupBy('supplier_id')
            ->pluck('parts', 'supplier_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $open = Task::query()->whereNotNull('supplier_id')->whereNull('completed_at')->get()->keyBy('supplier_id');

        // Nothing left to buy there: the task is done.
        foreach ($open as $supplierId => $task) {
            if (! isset($counts[$supplierId])) {
                $task->forceFill(['completed_at' => now()])->save();
            }
        }

        if ($counts === []) {
            return;
        }

        $owner = self::owner();
        if ($owner === null) {
            return;
        }

        foreach (Supplier::query()->whereIn('id', array_keys($counts))->get() as $supplier) {
            $title = trans_choice('parts.buy_task', $counts[$supplier->id], ['supplier' => $supplier->name]);
            $task = $open->get($supplier->id) ?? new Task(['user_id' => $owner, 'task_date' => $today, 'all_day' => true]);

            $task->fill(['title' => mb_substr($title, 0, 160)]);
            $task->kind = Task::KIND_PARTS;
            $task->supplier_id = $supplier->id;
            $task->address = $supplier->address;
            $task->latitude = $supplier->latitude;
            $task->longitude = $supplier->longitude;
            $task->created_by ??= $owner;
            $task->save();

            if ($task->address !== null && $task->latitude === null) {
                GeocodeAddress::dispatch(Task::class, $task->id)->afterCommit();
            }
        }
    }

    /** The task is the Owner's (the first active one). */
    private static function owner(): ?int
    {
        $id = Membership::query()->where('is_active', true)->where('role', UserRole::Owner->value)->orderBy('id')->value('user_id');

        return $id === null ? null : (int) $id;
    }
}
