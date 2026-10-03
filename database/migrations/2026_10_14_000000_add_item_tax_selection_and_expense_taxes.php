<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['estimate_items', 'invoice_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // Null keeps existing lines applying the document's taxes; [] is explicitly exempt.
                $table->jsonb('tax_rate_ids')->nullable();
            });
        }
        Schema::table('business_expenses', function (Blueprint $table) {
            // Historical entries with an undivided receipt tax retain their existing tax_amount.
            $table->jsonb('taxes')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['estimate_items', 'invoice_items'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('tax_rate_ids'));
        }
        Schema::table('business_expenses', fn (Blueprint $table) => $table->dropColumn('taxes'));
    }
};
