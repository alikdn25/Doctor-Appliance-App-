<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs are stored as "service_jobs": the "jobs" table belongs to Laravel's queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('job_next_number')->default(1001)->after('estimate_next_number');
        });

        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('brand_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('property_id')->constrained();
            $table->string('job_type', 30);
            $table->string('lead_source', 50)->nullable();
            $table->string('status', 30);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->text('tech_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'brand_id']);
        });

        Schema::create('job_appliance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appliance_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_job_id', 'appliance_id']);
            $table->index(['company_id', 'appliance_id']);
        });

        Schema::create('job_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->unsignedSmallInteger('estimated_duration_minutes')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->timestamp('on_the_way_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'scheduled_start']);
            $table->index('service_job_id');
        });

        Schema::create('job_visit_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['job_visit_id', 'user_id']);
            $table->index(['company_id', 'user_id']);
        });

        Schema::create('job_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'service_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_status_changes');
        Schema::dropIfExists('job_visit_user');
        Schema::dropIfExists('job_visits');
        Schema::dropIfExists('job_appliance');
        Schema::dropIfExists('service_jobs');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('job_next_number');
        });
    }
};
