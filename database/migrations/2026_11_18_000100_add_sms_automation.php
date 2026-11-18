<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 18, part 1: the AI reads incoming texts, reminders about unanswered texts, auto replies
 * (texts and missed calls) and the "Possible warranty" mark. New columns with defaults and one new table; nothing
 * existing changes or is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('auto_reply_enabled')->default(false);
            $table->text('auto_reply_text')->nullable();
            $table->unsignedSmallInteger('auto_reply_delay_minutes')->default(15);
            $table->boolean('missed_call_reply_enabled')->default(false);
            $table->text('missed_call_reply_text')->nullable();
            $table->unsignedSmallInteger('sms_reminder_hours')->default(3);
            $table->unsignedSmallInteger('warranty_lookback_days')->default(90);
        });

        Schema::table('messages', function (Blueprint $table) {
            // What the AI read from an incoming text: urgency, proposed actions, a reply draft, a rating plate.
            $table->jsonb('ai_triage')->nullable();
            // Photos of an MMS (private files).
            $table->jsonb('media')->nullable();
        });

        // One row per phone number the company texts with: is a reply owed, reminders, auto replies.
        Schema::create('sms_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('phone', 32);
            $table->timestamp('awaiting_since')->nullable();
            $table->boolean('urgent')->default(false);
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->unsignedSmallInteger('reminders_max')->default(3);
            $table->timestamp('next_reminder_at')->nullable();
            $table->timestamp('auto_reply_due_at')->nullable();
            $table->string('auto_reply_kind', 20)->nullable();
            $table->timestamp('last_auto_reply_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
            $table->index('next_reminder_at');
            $table->index('auto_reply_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_threads');
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn(['ai_triage', 'media']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'auto_reply_enabled', 'auto_reply_text', 'auto_reply_delay_minutes', 'missed_call_reply_enabled',
            'missed_call_reply_text', 'sms_reminder_hours', 'warranty_lookback_days',
        ]));
    }
};
