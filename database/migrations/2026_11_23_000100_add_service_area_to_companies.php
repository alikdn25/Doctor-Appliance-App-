<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service area (Company → Service area): a south/north/west/east box that address suggestions are limited to.
 * All four empty = suggestions anywhere in the company's country. The owner's own companies (Doctor Appliance,
 * Duct Works, Metro Vancouver) get the Lower Mainland box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('service_area_south', 9, 6)->nullable();
            $table->decimal('service_area_north', 9, 6)->nullable();
            $table->decimal('service_area_west', 9, 6)->nullable();
            $table->decimal('service_area_east', 9, 6)->nullable();
        });

        DB::table('companies')
            ->where('country', 'CA')
            ->where(fn ($q) => $q->where('name', 'ilike', '%doctor appliance%')->orWhere('name', 'ilike', '%duct works%'))
            ->update([
                'service_area_south' => 49.00,
                'service_area_north' => 49.50,
                'service_area_west' => -123.35,
                'service_area_east' => -121.30,
            ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['service_area_south', 'service_area_north', 'service_area_west', 'service_area_east']);
        });
    }
};
