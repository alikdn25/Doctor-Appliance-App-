<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 18, part 3: the morning summary (per person and company: on/off, time, last day sent) and
 * draft trips (the day's route measured at the end of the day, counted once the Owner confirms it). New columns with
 * defaults only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->boolean('morning_summary_enabled')->default(true);
            $table->string('morning_summary_time', 5)->default('07:00');
            $table->date('morning_summary_sent_on')->nullable();
            $table->date('day_route_drafted_on')->nullable();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('trips', fn (Blueprint $table) => $table->dropColumn('is_draft'));
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn([
            'morning_summary_enabled', 'morning_summary_time', 'morning_summary_sent_on', 'day_route_drafted_on',
        ]));
    }
};
