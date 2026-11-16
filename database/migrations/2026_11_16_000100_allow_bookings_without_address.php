<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's update of November 16: a booking saves with any data. A job without an address keeps an empty address of
 * its customer (street and city empty) until someone adds one; the job shows "No address".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('line1')->nullable()->change();
            $table->string('city', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('properties')->whereNull('line1')->update(['line1' => '']);
        DB::table('properties')->whereNull('city')->update(['city' => '']);

        Schema::table('properties', function (Blueprint $table) {
            $table->string('line1')->nullable(false)->change();
            $table->string('city', 100)->nullable(false)->change();
        });
    }
};
