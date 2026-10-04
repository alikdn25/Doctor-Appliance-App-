<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null = every office permission (existing Office members keep what they had).
        Schema::table('company_user', fn (Blueprint $table) => $table->json('permissions')->nullable());
    }

    public function down(): void
    {
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('permissions'));
    }
};
