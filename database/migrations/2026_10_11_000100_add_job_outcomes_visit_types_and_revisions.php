<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimate revisions; deleting/restoring jobs; job outcomes with reasons; visit types (return visits with a
 * "bring with you" list, warranty callbacks linked to the original job); strict arrival time on visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            // Versions of one estimate: the first one is the root (revision 1).
            $table->unsignedSmallInteger('revision')->default(1);
            $table->foreignId('revision_root_id')->nullable()->constrained('estimates')->nullOnDelete();
            $table->foreignId('revised_from_id')->nullable()->constrained('estimates')->nullOnDelete();
            // Set on a version replaced by a newer one.
            $table->timestamp('revised_at')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('technicians_can_delete_jobs')->default(false);
            // Reasons offered when a job is closed without a repair or cancelled, per outcome; null = defaults.
            $table->json('closure_reasons')->nullable();
            // Price book service used for "Invoice diagnosis only".
            $table->foreignId('diagnostic_service_id')->nullable()->constrained('services')->nullOnDelete();
            // How long before a strict-arrival visit the technician is reminded.
            $table->unsignedSmallInteger('strict_arrival_reminder_minutes')->default(60);
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visit_type', 20)->default('new_diagnosis');
            // Return visit or warranty callback: the job it follows up.
            $table->foreignId('previous_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->string('outcome', 30)->nullable();
            $table->string('outcome_reason')->nullable();
            $table->text('outcome_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'visit_type']);
            $table->index(['company_id', 'outcome']);
        });

        Schema::table('job_visits', function (Blueprint $table) {
            $table->boolean('strict_arrival')->default(false);
            $table->timestamp('strict_reminder_sent_at')->nullable();
        });

        Schema::create('job_bring_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->boolean('is_checked')->default(false);
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'service_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_bring_items');

        Schema::table('job_visits', function (Blueprint $table) {
            $table->dropColumn(['strict_arrival', 'strict_reminder_sent_at']);
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'visit_type']);
            $table->dropIndex(['company_id', 'outcome']);
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropConstrainedForeignId('previous_job_id');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['visit_type', 'outcome', 'outcome_reason', 'outcome_note', 'closed_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diagnostic_service_id');
            $table->dropColumn(['technicians_can_delete_jobs', 'closure_reasons', 'strict_arrival_reminder_minutes']);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revision_root_id');
            $table->dropConstrainedForeignId('revised_from_id');
            $table->dropConstrainedForeignId('revised_by');
            $table->dropColumn(['revision', 'revised_at']);
        });
    }
};
