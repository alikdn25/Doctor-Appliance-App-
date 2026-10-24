<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An expense can be for one job ("+" → Expense → Job): it then counts in that job's Costs and profit instead of the
 * company's overhead. Optional; existing expenses stay overhead. Nothing cascades: jobs are soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_expenses', function (Blueprint $table) {
            $table->foreignId('service_job_id')->nullable()->after('category_id')->constrained('service_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_job_id');
        });
    }
};
