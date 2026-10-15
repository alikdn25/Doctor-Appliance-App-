<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person picks their bottom menu (UX-REDESIGN §22): "field" (works on calls: Today · Calendar · Book · Invoices)
 * or "office" (runs the team: Today · Calendar · Book · Jobs). Empty = the default of their role. Approved by the
 * owner on October 14, 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('menu_mode', 10)->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('menu_mode'));
    }
};
