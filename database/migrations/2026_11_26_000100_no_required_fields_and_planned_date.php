<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No required fields anywhere (owner's task of November 25): the columns forms fill become nullable (defaults are
 * kept; the app still fills sensible values such as "Untitled", today, 0). Jobs get the day of a booking taken without
 * a time ("No time"); nothing is removed and no row is changed.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'service_jobs' => ['brand_id', 'job_type'],
        'tasks' => ['title', 'task_date'],
        'job_parts' => ['name'],
        'job_cost_items' => ['description'],
        'job_bring_items' => ['description'],
        'customers' => ['type'],
        'properties' => ['country'],
        'appliances' => ['type'],
        'estimates' => ['issued_on'],
        'estimate_items' => ['description', 'quantity', 'unit_price'],
        'invoices' => ['issued_on'],
        'invoice_items' => ['description', 'quantity', 'unit_price'],
        'payments' => ['amount', 'method'],
        'business_expenses' => ['category_id', 'spent_on', 'description', 'amount'],
        'trips' => ['trip_date', 'type'],
    ];

    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->date('planned_date')->nullable()->index();
        });

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                Schema::getConnection()->statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" DROP NOT NULL");
            }
        }
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropIndex(['planned_date']);
            $table->dropColumn('planned_date');
        });
        // The columns stay nullable: rows saved empty since then would not fit NOT NULL again.
    }
};
