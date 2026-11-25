<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 25:
 * - Settings → Company → Booking confirmation (companies.booking_confirmation; empty = the defaults) and the result of
 *   the last confirmation on the job (SMS / email / when / why not).
 * - Reports: a report with photos in an invoice or estimate (report_text, has_report, report_photos) and separate job
 *   reports (job_reports).
 * - The app's microphone language: per person (users.dictation_language) and the company's default (RU for the
 *   owner's own companies).
 * - "Resend" after a sent invoice or estimate was corrected (edited_after_send_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('booking_confirmation')->nullable();
            $table->string('default_dictation_language', 2)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('dictation_language', 2)->nullable();
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->timestamp('confirmation_sent_at')->nullable();
            // sent / failed / off (channel switched off) / none (no phone or email)
            $table->string('confirmation_sms_status', 16)->nullable();
            $table->string('confirmation_email_status', 16)->nullable();
            $table->string('confirmation_error', 500)->nullable();
        });

        foreach (['invoices', 'estimates'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('edited_after_send_at')->nullable();
                $table->boolean('has_report')->default(false);
                $table->text('report_text')->nullable();
            });
        }

        Schema::create('job_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('service_job_id')->constrained();
            $table->foreignId('brand_address_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('appliance_type', 100)->nullable();
            $table->string('appliance_brand', 100)->nullable();
            $table->string('appliance_model', 100)->nullable();
            $table->string('appliance_serial', 100)->nullable();
            $table->date('report_date')->nullable();
            $table->string('technician_name')->nullable();
            $table->text('description')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signer_name')->nullable();
            $table->string('public_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'service_job_id']);
        });

        Schema::create('report_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            // invoice, estimate or job_report
            $table->string('reportable_type', 32);
            $table->unsignedBigInteger('reportable_id');
            $table->foreignId('job_photo_id')->constrained();
            $table->string('caption', 300)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['reportable_type', 'reportable_id']);
        });

        // The owner's own companies: Russian microphone by default, cancellation notice 24 hours and an 85 fee.
        DB::table('companies')
            ->where(fn ($q) => $q->where('name', 'ilike', '%doctor appliance%')->orWhere('name', 'ilike', '%duct works%'))
            ->update([
                'default_dictation_language' => 'ru',
                'booking_confirmation' => json_encode(['cancellation_hours' => 24, 'cancellation_fee' => '85']),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_photos');
        Schema::dropIfExists('job_reports');
        foreach (['invoices', 'estimates'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['edited_after_send_at', 'has_report', 'report_text']);
            });
        }
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropColumn(['confirmation_sent_at', 'confirmation_sms_status', 'confirmation_email_status', 'confirmation_error']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dictation_language');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['booking_confirmation', 'default_dictation_language']);
        });
    }
};
