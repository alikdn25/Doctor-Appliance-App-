<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Good / Better / Best on an estimate (SPEC §7.5, UX-REDESIGN §10.4): a line can belong to one option ("good",
 * "better", "best"); lines without one are in every option. The option the customer picked is the one whose lines
 * are selected. Existing lines stay as they are (no option). Approved by the owner on October 15, 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', fn (Blueprint $table) => $table->string('option_name', 10)->nullable());
    }

    public function down(): void
    {
        Schema::table('estimate_items', fn (Blueprint $table) => $table->dropColumn('option_name'));
    }
};
