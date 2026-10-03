<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->string('avatar_style', 12)->default('auto'));
        Schema::table('companies', fn (Blueprint $table) => $table->unsignedSmallInteger('estimate_followup_days')->nullable());
        Schema::table('estimates', fn (Blueprint $table) => $table->timestampTz('followup_processed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('avatar_style'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('estimate_followup_days'));
        Schema::table('estimates', fn (Blueprint $table) => $table->dropColumn('followup_processed_at'));
    }
};
