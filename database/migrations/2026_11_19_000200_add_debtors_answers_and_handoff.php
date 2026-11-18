<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 19, part 2: debtors and soft payment reminders, Russian → English replies, prices and
 * answers for the AI, clarifying questions and handing a job to a technician. New columns with defaults only; nothing
 * existing changes or is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('payment_reminder_template')->nullable();
            $table->boolean('translate_to_english')->default(true);
            // Prices and answers the AI may use in reply drafts.
            $table->bigInteger('diagnostic_fee')->nullable();
            $table->text('service_areas')->nullable();
            $table->text('hours_text')->nullable();
            $table->text('answer_notes')->nullable();
            $table->boolean('auto_clarify')->default(false);
            // Outside technicians a job can be handed to: [{name, phone}].
            $table->jsonb('outside_technicians')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
            $table->unsignedBigInteger('reminder_message_id')->nullable()->index();
            $table->timestamp('reminder_answered_at')->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // Imported debt without an amount: the line waits for one.
            $table->boolean('needs_amount')->default(false);
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('handed_to')->nullable();
            $table->timestamp('handed_at')->nullable();
        });

        Schema::table('sms_threads', function (Blueprint $table) {
            // Clarifying questions sent automatically (at most once per 24 hours per number).
            $table->timestamp('last_clarify_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sms_threads', fn (Blueprint $table) => $table->dropColumn('last_clarify_at'));
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropColumn(['handed_to', 'handed_at']));
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn('needs_amount'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['reminder_sent_at', 'reminder_message_id', 'reminder_answered_at']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'payment_reminder_template', 'translate_to_english', 'diagnostic_fee', 'service_areas', 'hours_text',
            'answer_notes', 'auto_clarify', 'outside_technicians',
        ]));
    }
};
