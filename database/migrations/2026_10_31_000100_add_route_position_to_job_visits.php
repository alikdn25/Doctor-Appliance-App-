<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of October 24 (map and route): the order of a person's visits in the day, saved by "Apply" on the
 * map. Empty = by the start of the arrival window, as before; nothing changes for existing visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_visits', fn (Blueprint $table) => $table->unsignedSmallInteger('route_position')->nullable());
    }

    public function down(): void
    {
        Schema::table('job_visits', fn (Blueprint $table) => $table->dropColumn('route_position'));
    }
};
