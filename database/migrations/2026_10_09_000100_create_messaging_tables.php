<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer messaging (SPEC §7.7) and Google review requests (SPEC §8).
 *
 * - companies.sms_mode: automatic (platform SMS provider), technician_phone (sms: links on the tech's phone,
 *   automated messages by email) or off (everything by email).
 * - sms_accounts: the company's SMS subaccount and number at the platform's provider (Twilio).
 * - sms_registrations: US A2P 10DLC registration data and status.
 * - messages: every SMS/email sent to or received from a customer, and SMS opened on a technician's phone.
 * - google_profiles + review_requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('sms_mode', 20)->default('technician_phone')->after('online_tips');
            // No automatic messages between these local times; they wait until the end.
            $table->string('quiet_hours_start', 5)->default('21:00')->after('sms_mode');
            $table->string('quiet_hours_end', 5)->default('08:00')->after('quiet_hours_start');
            // Overrides of the default texts (lang/en/messages.php), by template key.
            $table->jsonb('message_templates')->default(DB::raw("'{}'::jsonb"))->after('quiet_hours_end');
            $table->boolean('review_requests_default')->default(true)->after('message_templates');
            $table->unsignedSmallInteger('review_request_delay_hours')->default(2)->after('review_requests_default');
            $table->unsignedSmallInteger('review_request_cooldown_days')->default(180)->after('review_request_delay_hours');
        });

        Schema::create('sms_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('account_sid', 64);
            $table->text('auth_token');
            $table->string('phone_number', 20)->nullable();
            $table->string('phone_number_sid', 64)->nullable();
            $table->timestamps();

            $table->index(['provider', 'account_sid']);
        });

        Schema::create('sms_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // draft → submitted → approved | rejected
            $table->string('status', 20)->default('draft');
            $table->jsonb('business')->default(DB::raw("'{}'::jsonb"));
            $table->string('brand_registration_sid', 64)->nullable();
            $table->string('messaging_service_sid', 64)->nullable();
            $table->string('campaign_sid', 64)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::table('customer_phones', function (Blueprint $table) {
            // Set when the customer replied STOP (or similar) to our SMS; cleared by START.
            $table->timestamp('sms_opted_out_at')->nullable()->after('is_primary');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 10);
            // sms, email, technician_phone (sms: link opened on the tech's phone)
            $table->string('channel', 20);
            $table->string('kind', 30);
            $table->string('to')->nullable();
            $table->string('from')->nullable();
            $table->text('body');
            // scheduled, sending, sent, delivered, failed, blocked, received, opened
            $table->string('status', 20);
            $table->string('status_reason')->nullable();
            $table->string('provider_message_id', 64)->nullable();
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'created_at']);
            $table->index(['company_id', 'service_job_id']);
            $table->index(['status', 'send_after']);
            $table->index('provider_message_id');
        });

        Schema::create('google_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 100);
            $table->string('review_url', 500);
            $table->timestamps();

            $table->index(['company_id', 'brand_id']);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->foreignId('google_profile_id')->nullable()->after('invoice_terms')->constrained()->nullOnDelete();
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->boolean('ask_for_review')->default(false);
        });

        Schema::table('job_visits', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
        });

        Schema::create('review_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('google_profile_id')->nullable()->constrained()->nullOnDelete();
            // scheduled → sent | skipped
            $table->string('status', 20);
            $table->string('channel', 20)->nullable();
            $table->string('skip_reason')->nullable();
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'service_job_id']);
            $table->index(['company_id', 'customer_id', 'sent_at']);
            $table->index(['status', 'send_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_requests');
        Schema::table('job_visits', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at'));
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropColumn('ask_for_review'));
        Schema::table('brands', fn (Blueprint $table) => $table->dropConstrainedForeignId('google_profile_id'));
        Schema::dropIfExists('google_profiles');
        Schema::dropIfExists('messages');
        Schema::table('customer_phones', fn (Blueprint $table) => $table->dropColumn('sms_opted_out_at'));
        Schema::dropIfExists('sms_registrations');
        Schema::dropIfExists('sms_accounts');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'sms_mode', 'quiet_hours_start', 'quiet_hours_end', 'message_templates',
            'review_requests_default', 'review_request_delay_hours', 'review_request_cooldown_days',
        ]));
    }
};
