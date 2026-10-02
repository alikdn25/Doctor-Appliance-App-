<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Tips taken by the payment provider are kept on the payment, apart from the amount applied to the invoice,
 *   so payment totals match the provider's payouts while invoice revenue stays the invoice total.
 * - Refunds reported by the provider are payment rows with a negative amount linked to the refunded payment.
 * - Estimates and invoices get a secret token for their online page, and when/to whom they were last sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('tip_amount')->default(0)->after('amount');
            $table->foreignId('refunded_payment_id')->nullable()->after('provider_payment_id')->constrained('payments')->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            // Customers can add a tip when paying online (provider checkout).
            $table->boolean('online_tips')->default(false)->after('payment_provider');
        });

        foreach (['estimates', 'invoices'] as $documents) {
            Schema::table($documents, function (Blueprint $table) {
                $table->string('public_token', 64)->nullable()->unique();
                $table->timestamp('sent_at')->nullable();
                $table->string('sent_to')->nullable();
                $table->timestamp('viewed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['estimates', 'invoices'] as $documents) {
            Schema::table($documents, fn (Blueprint $table) => $table->dropColumn(['public_token', 'sent_at', 'sent_to', 'viewed_at']));
        }

        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('online_tips'));

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_payment_id');
            $table->dropColumn('tip_amount');
        });
    }
};
