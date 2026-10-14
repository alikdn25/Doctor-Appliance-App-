<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data protection: nothing is removed for good, every change of the main records is logged, backups and the daily
 * watchdog keep their own records, and the website booking bot gets its own tokens.
 *
 * Foreign keys that deleted children together with their parent (ON DELETE CASCADE) now refuse the delete
 * (RESTRICT): customers, jobs and documents are soft-deleted, so a hard delete of a parent can only be a mistake,
 * and it must never take jobs, invoices or history with it. Only the people of a not-yet-started visit time keep the
 * cascade (removing such a time is an ordinary edit).
 */
return new class extends Migration
{
    /** Foreign keys that keep ON DELETE CASCADE: table => columns. */
    private const KEEP_CASCADE = [
        'job_visit_user' => ['job_visit_id'],
    ];

    public function up(): void
    {
        foreach (['payments', 'job_cost_items'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->softDeletes());
            }
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            // web, bot, console or system: who acted (a person in the app, the booking bot, a server command).
            $table->string('source', 16)->nullable();
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('reason', 32);
            $table->string('status', 16);
            $table->json('paths')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['kind', 'status', 'started_at']);
        });

        Schema::create('data_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('taken_on');
            $table->unsignedInteger('customers');
            $table->unsignedInteger('jobs');
            $table->unsignedInteger('invoices');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'taken_on']);
        });

        // The booking bot acts as its own user (no membership, no password anyone knows, cannot sign in).
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_bot')->default(false));

        Schema::create('bot_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('token_hint', 8);
            $table->unsignedSmallInteger('requests_per_minute')->default(30);
            $table->unsignedSmallInteger('bookings_per_hour')->default(5);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        $this->replaceCascades('CASCADE', 'RESTRICT');
    }

    public function down(): void
    {
        $this->replaceCascades('RESTRICT', 'CASCADE');

        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('bot_tokens');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_bot'));
        Schema::dropIfExists('data_snapshots');
        Schema::dropIfExists('backup_runs');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'auditable_type', 'auditable_id']);
            $table->dropColumn('source');
        });
    }

    private function replaceCascades(string $from, string $to): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // confdeltype: c = cascade, r = restrict.
        $type = $from === 'CASCADE' ? 'c' : 'r';
        $keys = DB::select(
            "select c.conrelid::regclass::text as table_name, c.conname as name, pg_get_constraintdef(c.oid) as definition,
                    (select string_agg(a.attname, ',') from pg_attribute a where a.attrelid = c.conrelid and a.attnum = any(c.conkey)) as columns
             from pg_constraint c
             where c.contype = 'f' and c.confdeltype = ? and c.connamespace = current_schema()::regnamespace",
            [$type],
        );

        foreach ($keys as $key) {
            $table = trim($key->table_name, '"');

            if (in_array($key->columns, self::KEEP_CASCADE[$table] ?? [], true)) {
                continue;
            }

            // Down only reverts the keys this migration changed (the new tables were created with RESTRICT).
            if ($from === 'RESTRICT' && in_array($table, ['data_snapshots', 'bot_tokens', 'push_subscriptions'], true)) {
                continue;
            }

            $definition = str_replace("ON DELETE {$from}", "ON DELETE {$to}", $key->definition);
            DB::statement(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s, ADD CONSTRAINT %s %s',
                $key->table_name,
                DB::getQueryGrammar()->wrap($key->name),
                DB::getQueryGrammar()->wrap($key->name),
                $definition,
            ));
        }
    }
};
