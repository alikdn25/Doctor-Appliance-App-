<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reopen job and refunds (owner's task of November 26):
 * - service_jobs.reopened_at: a closed job taken up again; a paid invoice does not close it again until it is
 *   completed or closed by hand.
 * - payments.refund_taxes: the taxes inside a refund row ([{name, rate, amount}], positive minor units);
 *   payments.refund_status: a provider refund's state (pending / completed / failed) from Square's webhooks.
 * Nothing is removed and no row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->json('refund_taxes')->nullable();
            $table->string('refund_status', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropColumn('reopened_at'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['refund_taxes', 'refund_status']));
    }
};
