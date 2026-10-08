<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which line types a tax is charged on by default (e.g. a sales tax on parts and materials but not on labor).
 * Null keeps every existing rate applying to all line types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', fn (Blueprint $table) => $table->jsonb('applies_to')->nullable());
    }

    public function down(): void
    {
        Schema::table('tax_rates', fn (Blueprint $table) => $table->dropColumn('applies_to'));
    }
};
