<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payment providers (SPEC §7.6): each company connects its own account (OAuth). Tokens are encrypted
 * by the model. Payment links are kept so a provider's webhook can be matched back to its invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            // The company's account at the provider (Square merchant ID).
            $table->string('account_id', 100);
            $table->string('account_name')->nullable();
            // Where payments go (Square location) and in which currency.
            $table->string('location_id', 100)->nullable();
            $table->string('location_name')->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'provider']);
            $table->index(['provider', 'account_id']);
        });

        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_link_id', 100);
            // What the provider puts on the payment (Square order ID), to find the invoice from a webhook.
            $table->string('provider_order_id', 100)->nullable();
            $table->string('url', 500);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            // active → paid, or replaced by a newer link when the balance changed.
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'invoice_id']);
            $table->index(['provider', 'provider_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
        Schema::dropIfExists('payment_provider_connections');
    }
};
