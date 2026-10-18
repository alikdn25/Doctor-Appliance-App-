<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklists are removed from the product (UX-REDESIGN §20, the owner's decision; dropping the tables approved by the
 * owner on October 16, 2026). The checklist data goes with them; take a backup before deploying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('job_checklist_items');
        Schema::dropIfExists('checklist_templates');
    }

    public function down(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('job_type', 30);
            $table->json('items');
            $table->timestamps();
            $table->unique(['company_id', 'job_type']);
        });

        Schema::create('job_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('service_job_id')->constrained();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('label', 200);
            $table->boolean('is_done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }
};
