<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks: personal to-dos without a customer (call the supplier, pick up a part), for oneself or a teammate, on a day
 * at a time or all day, with an optional reminder. They show in the calendar in their own colour.
 *
 * Job notes: the team's notes on a job (the Notes tab), each with its author and time. The customer never sees them.
 *
 * Nothing cascades (data protection): both are soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Who it is for.
            $table->foreignId('user_id')->constrained();
            $table->string('title', 160);
            $table->text('note')->nullable();
            $table->date('task_date');
            $table->boolean('all_day')->default(false);
            // The time in the company's time zone, also kept as moments for the calendar and the reminder.
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Minutes before the start (all day: before the start of the working day); null = no reminder.
            $table->unsignedInteger('remind_minutes')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'task_date']);
            $table->index(['company_id', 'user_id', 'task_date']);
            $table->index(['remind_at', 'reminded_at']);
        });

        Schema::create('job_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('service_job_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'service_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_notes');
        Schema::dropIfExists('tasks');
    }
};
