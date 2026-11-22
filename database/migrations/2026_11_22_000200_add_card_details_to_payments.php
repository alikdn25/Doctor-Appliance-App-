<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card in the Android app (owner's task of November 22): the card brand and last 4 digits of a card payment, and the
 * Square reference_id of a card reader charge (the invoice number). New nullable columns only; nothing is changed or
 * deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('card_brand', 40)->nullable();
            $table->string('card_last4', 4)->nullable();
        });

        Schema::table('charges', function (Blueprint $table) {
            // The invoice number sent to Square as reference_id (a charge without a booking reserves it at Card).
            $table->string('square_reference', 40)->nullable();
            $table->index(['company_id', 'square_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'square_reference']);
            $table->dropColumn('square_reference');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['card_brand', 'card_last4']);
        });
    }
};
