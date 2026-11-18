<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 19, part 1: "customer is quiet" reminders, the morning SMS to the owner's own phone, the
 * nightly backup to Google Drive and actions right from a push notification. New columns with defaults and new
 * tables; nothing existing is deleted. The reminder about an unanswered text now defaults to 2 hours (companies that
 * still had the old default of 3 move to 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('sms_reminder_hours')->default(2)->change();
            $table->unsignedSmallInteger('silence_reminder_hours')->default(2);
            $table->boolean('morning_sms_enabled')->default(false);
            $table->string('morning_sms_phone', 32)->nullable();
            $table->string('morning_sms_time', 5)->default('06:30');
            $table->date('morning_sms_sent_on')->nullable();
            // Google Drive backup (drive.file scope: only the files the app created). The refresh token is encrypted.
            $table->text('google_drive_token')->nullable();
            $table->string('google_drive_email')->nullable();
            $table->string('google_drive_folder_id')->nullable();
            $table->date('drive_backup_ran_on')->nullable();
        });
        DB::table('companies')->where('sms_reminder_hours', 3)->update(['sms_reminder_hours' => 2]);

        Schema::table('sms_threads', function (Blueprint $table) {
            // "Customer is quiet": a person texted and no answer came yet.
            $table->timestamp('silent_since')->nullable();
            $table->unsignedBigInteger('silent_message_id')->nullable();
            $table->timestamp('silent_remind_at')->nullable()->index();
            $table->timestamp('silent_reminded_at')->nullable();
            $table->date('silent_reported_on')->nullable();
            $table->text('silent_draft')->nullable();
        });

        Schema::table('messages', function (Blueprint $table) {
            // "Not waiting for a reply" on one of our texts.
            $table->boolean('no_reply_expected')->default(false);
        });

        // Buttons on a push notification: each tap is checked against this row (person, company, not used yet).
        Schema::create('push_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('token', 64)->unique();
            $table->string('kind', 40);
            $table->string('title');
            $table->text('body');
            $table->string('url')->nullable();
            $table->jsonb('actions');
            $table->timestamp('resend_at')->nullable()->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // One row per nightly upload to Google Drive (two CSV files); the 30 newest are kept.
        Schema::create('drive_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('status', 10);
            $table->text('error')->nullable();
            $table->jsonb('files')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_backups');
        Schema::dropIfExists('push_actions');
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('no_reply_expected'));
        Schema::table('sms_threads', fn (Blueprint $table) => $table->dropColumn([
            'silent_since', 'silent_message_id', 'silent_remind_at', 'silent_reminded_at', 'silent_reported_on', 'silent_draft',
        ]));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'silence_reminder_hours', 'morning_sms_enabled', 'morning_sms_phone', 'morning_sms_time', 'morning_sms_sent_on',
                'google_drive_token', 'google_drive_email', 'google_drive_folder_id', 'drive_backup_ran_on',
            ]);
            $table->unsignedSmallInteger('sms_reminder_hours')->default(3)->change();
        });
    }
};
