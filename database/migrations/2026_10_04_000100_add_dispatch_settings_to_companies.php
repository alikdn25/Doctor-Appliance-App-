<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Minutes kept free after a visit for driving to the next one (calendar conflicts).
            $table->unsignedSmallInteger('travel_buffer_minutes')->default(30)->after('business_hours');
            // Set when the company was created without a timezone: the Owner's browser fills it in.
            $table->boolean('timezone_pending')->default(false)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['travel_buffer_minutes', 'timezone_pending']);
        });
    }
};
