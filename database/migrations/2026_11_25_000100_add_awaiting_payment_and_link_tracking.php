<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Awaiting payment, invoice opens and review link clicks (owner's task of November 25). New nullable columns only;
 * nothing is removed. Finished jobs that already have a sent invoice with money owed move to "awaiting_payment".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // The customer followed the invoice link (/i/{token}); no open pixels.
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            // Key of the job's review links (/r/{token}/{location}) and the first click on one.
            $table->string('review_token', 40)->nullable()->unique();
            $table->timestamp('review_clicked_at')->nullable();
        });

        Schema::table('review_requests', function (Blueprint $table) {
            // The one review reminder, 24 hours after the job was paid in full.
            $table->timestamp('reminder_sent_at')->nullable();
        });

        DB::table('service_jobs')
            ->whereIn('status', ['completed', 'invoiced', 'paid'])
            ->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('invoices')
                ->whereColumn('invoices.service_job_id', 'service_jobs.id')
                ->whereNull('invoices.deleted_at')
                ->whereNotNull('invoices.sent_at')
                ->whereIn('invoices.status', ['unpaid', 'partially_paid'])
                ->where('invoices.balance', '>', 0))
            ->update(['status' => 'awaiting_payment']);
    }

    public function down(): void
    {
        DB::table('service_jobs')->where('status', 'awaiting_payment')->update(['status' => 'invoiced']);

        Schema::table('review_requests', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at'));
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropUnique(['review_token']);
            $table->dropColumn(['review_token', 'review_clicked_at']);
        });
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['first_opened_at', 'last_opened_at']));
    }
};
