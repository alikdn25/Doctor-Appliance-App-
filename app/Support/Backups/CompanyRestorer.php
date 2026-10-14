<?php

namespace App\Support\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Brings one company's records back from a database backup into the live database, without touching other
 * companies. The backup is first restored into the scratch database; then, table by table (parents first), every
 * row of the company that is missing in the live database is inserted, and every row that differs is put back to
 * its backed-up values. Rows created after the backup are kept: nothing is deleted.
 *
 * Users are shared between companies: a user missing in the live database is added back, an existing one is never
 * changed.
 */
class CompanyRestorer
{
    /**
     * Tables never restored: logs that only grow, queue and cache tables, per-device data, and credentials (a revoked
     * bot token, a disconnected payment account or a replaced SMS key must not come back from an old backup).
     */
    private const SKIP = [
        'audit_logs', 'impersonation_logs', 'backup_runs', 'data_snapshots', 'sessions', 'jobs', 'failed_jobs',
        'job_batches', 'cache', 'cache_locks', 'notifications', 'push_subscriptions',
        'bot_tokens', 'payment_provider_connections', 'sms_accounts',
    ];

    /** Live values that are never put back: numbering must keep counting up, or numbers would repeat. */
    private const KEEP_LIVE = [
        'companies' => ['job_next_number', 'invoice_next_number', 'estimate_next_number'],
    ];

    private ?Connection $scratch = null;

    public function __construct(private readonly BackupManager $backups) {}

    /**
     * Restores the backup into the scratch database and compares it with the live one.
     *
     * @return list<array{table: string, add: int, overwrite: int, newer: int}>
     */
    public function plan(string $backupPath, int $companyId): array
    {
        $this->scratch = DB::connection($this->backups->connectionTo($this->backups->restoreDatabase($backupPath)));

        if (! $this->scratch->table('companies')->where('id', $companyId)->exists()) {
            throw new RuntimeException("Company {$companyId} is not in this backup.");
        }

        $plan = [];

        foreach ($this->tables() as $table) {
            [$add, $overwrite, $live] = $this->compare($table, $companyId);
            $plan[] = ['table' => $table, 'add' => count($add), 'overwrite' => count($overwrite), 'newer' => $live];
        }

        return $plan;
    }

    /**
     * Applies the plan made by plan() (which must run first, in the same process).
     *
     * @return array{added: int, overwritten: int, skipped: list<string>}
     */
    public function apply(int $companyId): array
    {
        if ($this->scratch === null) {
            throw new RuntimeException('Call plan() first.');
        }

        $result = ['added' => 0, 'overwritten' => 0, 'skipped' => []];

        DB::transaction(function () use ($companyId, &$result) {
            foreach ($this->tables() as $table) {
                [$add, $overwrite] = $this->compare($table, $companyId);
                $columns = $this->sharedColumns($table);
                $insertColumns = $this->sharedColumns($table, forInsert: true);

                foreach ($add as $row) {
                    $this->attempt($table, $row, $result, fn () => DB::table($table)->insert(array_intersect_key($row, $insertColumns)));
                }

                foreach ($overwrite as $row) {
                    $this->attempt($table, $row, $result, fn () => DB::table($table)->where('id', $row['id'])->update(array_intersect_key($row, $columns)), 'overwritten');
                }
            }
        });

        $this->resetSequences();

        return $result;
    }

    public function cleanup(): void
    {
        $this->scratch?->disconnect();
        $this->scratch = null;
    }

    /**
     * The company's tables, parents first: "companies", "users" (only users of the company's memberships), then every
     * table with a company_id column, ordered by their foreign keys.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = collect(DB::select(
            "select table_name from information_schema.columns
             where table_schema = current_schema() and column_name = 'company_id'
             order by table_name",
        ))->pluck('table_name')->diff(self::SKIP)->values()->all();

        $edges = collect(DB::select(
            "select c.conrelid::regclass::text as child, c.confrelid::regclass::text as parent
             from pg_constraint c where c.contype = 'f' and c.connamespace = current_schema()::regnamespace",
        ))->map(fn ($e) => [trim($e->child, '"'), trim($e->parent, '"')]);

        // Topological order (Kahn); self-references and cycles fall back to name order.
        $ordered = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, fn (string $table) => $edges
                ->filter(fn (array $e) => $e[0] === $table && $e[1] !== $table && in_array($e[1], $remaining, true))
                ->isEmpty()));
            $ready = $ready === [] ? [$remaining[0]] : $ready;
            $ordered = [...$ordered, ...$ready];
            $remaining = array_values(array_diff($remaining, $ready));
        }

        return ['companies', 'users', ...$ordered];
    }

    /**
     * Rows to add and rows to put back for a table, and how many live rows are newer than the backup (kept).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int}
     */
    private function compare(string $table, int $companyId): array
    {
        $backup = $this->rows($this->scratch, $table, $companyId);
        $ids = array_column($backup, 'id');
        $live = collect($this->rows(DB::connection(), $table, $companyId, $table === 'users' ? $ids : null))->keyBy('id');
        $columns = $this->sharedColumns($table);

        $add = [];
        $overwrite = [];

        foreach ($backup as $row) {
            $current = $live->get($row['id']);

            if ($current === null) {
                $add[] = $row;
            } elseif ($table !== 'users' && array_intersect_key($row, $columns) != array_intersect_key($current, $columns)) {
                $overwrite[] = $row;
            }
        }

        $newer = $table === 'users' ? 0 : $live->keys()->diff($ids)->count();

        return [$add, $overwrite, $newer];
    }

    /**
     * @param  list<int>|null  $userIds
     * @return list<array<string, mixed>>
     */
    private function rows(Connection $connection, string $table, int $companyId, ?array $userIds = null): array
    {
        $query = $connection->table($table)->orderBy('id');

        if ($table === 'companies') {
            $query->where('id', $companyId);
        } elseif ($table === 'users') {
            $query->whereIn('id', $userIds ?? $connection->table('company_user')->where('company_id', $companyId)->pluck('user_id'));
        } else {
            $query->where('company_id', $companyId);
        }

        return $query->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Columns present in both the backup and the live table (the live schema may be newer); for an update, without
     * the columns whose live value is kept.
     *
     * @return array<string, true>
     */
    private function sharedColumns(string $table, bool $forInsert = false): array
    {
        $live = DB::connection()->getSchemaBuilder()->getColumnListing($table);
        $backup = $this->scratch->getSchemaBuilder()->getColumnListing($table);

        return array_fill_keys(array_values(array_diff(array_intersect($live, $backup), $forInsert ? [] : (self::KEEP_LIVE[$table] ?? []))), true);
    }

    /**
     * One row in its own savepoint: a row that no longer fits (e.g. its unique value is taken by a newer row) is
     * skipped and reported instead of stopping the restore.
     *
     * @param  array<string, mixed>  $row
     * @param  array{added: int, overwritten: int, skipped: list<string>}  $result
     */
    private function attempt(string $table, array $row, array &$result, callable $write, string $counter = 'added'): void
    {
        try {
            DB::transaction(fn () => $write());
            $result[$counter]++;
        } catch (Throwable $e) {
            $result['skipped'][] = "{$table}#{$row['id']}: ".Str::limit(BackupManager::safeMessage($e), 200);
        }
    }

    /** Sequences stay ahead of the restored ids. */
    private function resetSequences(): void
    {
        foreach ($this->tables() as $table) {
            $sequence = DB::selectOne('select pg_get_serial_sequence(?, ?) as name', [$table, 'id'])?->name;

            if ($sequence !== null) {
                DB::statement("select setval(?, greatest((select coalesce(max(id), 0) from {$table}), (select last_value from {$sequence})))", [$sequence]);
            }
        }
    }
}
