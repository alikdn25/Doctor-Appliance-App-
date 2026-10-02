<?php

use App\Support\Jobs\ChecklistDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('path');
            $table->unsignedInteger('size')->nullable();
            // Generated on the phone before the first attempt; makes retried uploads idempotent.
            $table->uuid('client_uuid');
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'client_uuid']);
            $table->index(['company_id', 'service_job_id']);
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('job_type', 30);
            $table->jsonb('items')->default(DB::raw("'[]'::jsonb"));
            $table->timestamps();

            $table->unique(['company_id', 'job_type']);
        });

        // A job keeps its own copy of the checklist, so later template edits do not change past jobs.
        Schema::create('job_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 200);
            $table->boolean('is_done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'service_job_id']);
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('tech_notes');
            $table->string('signature_name', 100)->nullable()->after('signature_path');
            $table->timestamp('signed_at')->nullable()->after('signature_name');
            $table->foreignId('signed_by')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
        });

        // Existing companies start with the default checklists.
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (ChecklistDefaults::all() as $jobType => $items) {
                DB::table('checklist_templates')->insert([
                    'company_id' => $companyId,
                    'job_type' => $jobType,
                    'items' => json_encode($items),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_by');
            $table->dropColumn(['signature_path', 'signature_name', 'signed_at']);
        });

        Schema::dropIfExists('job_checklist_items');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('job_photos');
    }
};
