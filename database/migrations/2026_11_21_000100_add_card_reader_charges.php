<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card reader payments on site, Cash / E-transfer from the Take payment screen and charges without a booking
 * (owner's task of November 21). New table and one defaulted column; nothing is changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            // Passed to the card reader payment (Square reference_id): ties the Square payment back to this charge.
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('method', 20);
            $table->string('status', 20)->default('pending');
            // A charge without a booking: what was done, the amount, the tax mode and the customer typed.
            $table->json('draft')->nullable();
            $table->string('provider_payment_id', 100)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // The one "Walk-in" customer of a company: charges where nobody's details were typed.
            $table->boolean('is_walk_in')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_walk_in');
        });

        Schema::dropIfExists('charges');
    }
};
