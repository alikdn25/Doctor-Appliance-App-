<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online approval of estimates (signature, optional lines, expiry, deposit) and Google Places on properties.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Prefills "Valid until" on new estimates; null = estimates do not expire by default.
            $table->unsignedSmallInteger('estimate_valid_days')->nullable()->default(30);
        });

        Schema::table('estimates', function (Blueprint $table) {
            // Deposit asked when the customer approves online: percent of the total or a fixed amount.
            $table->string('deposit_type', 10)->nullable();
            $table->decimal('deposit_value', 12, 2)->default(0);
            // The deposit worked out from the current total, in minor units.
            $table->bigInteger('deposit_amount')->default(0);

            // Online approval by the customer: who signed, how, from where.
            $table->string('signer_name', 100)->nullable();
            // drawn (finger on screen, PNG kept on the private disk) or typed (name only).
            $table->string('signature_type', 10)->nullable();
            $table->string('signature_path')->nullable();
            $table->string('approved_ip', 45)->nullable();
            $table->string('approved_user_agent', 500)->nullable();

            $table->text('decline_reason')->nullable();
            $table->string('declined_ip', 45)->nullable();
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            // An optional line is included only when selected (by the office, or the customer on the online page).
            $table->boolean('optional')->default(false);
            $table->boolean('selected')->default(true);
        });

        // A deposit paid on an estimate belongs to the estimate until it becomes an invoice.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->change();
            $table->foreignId('estimate_id')->nullable()->after('invoice_id')->constrained();
            $table->index(['company_id', 'estimate_id']);
        });

        Schema::table('payment_links', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->change();
            $table->foreignId('estimate_id')->nullable()->after('invoice_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('google_place_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('google_place_id');
        });

        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estimate_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'estimate_id']);
            $table->dropConstrainedForeignId('estimate_id');
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn(['optional', 'selected']);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_type', 'deposit_value', 'deposit_amount', 'signer_name', 'signature_type', 'signature_path',
                'approved_ip', 'approved_user_agent', 'decline_reason', 'declined_ip',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('estimate_valid_days');
        });
    }
};
