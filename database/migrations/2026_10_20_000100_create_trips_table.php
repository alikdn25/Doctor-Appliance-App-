<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mileage log: trips to customers (made automatically from the day's route), to parts stores, suppliers and other
 * business trips. Distances are kept in kilometres and shown in the company's unit (km or mi). The company sets a
 * rate per unit for the profit report; each member keeps the address their working day starts from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('trip_date');
            $table->string('type', 20);
            $table->string('from_address', 255)->nullable();
            $table->string('to_address', 255)->nullable();
            $table->decimal('distance_km', 8, 1)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->foreignId('service_job_id')->nullable()->constrained()->nullOnDelete();
            // Set on trips made from the route: one per visit, so a repeated Start never adds a second one.
            $table->foreignId('job_visit_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('distance_edited')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'trip_date']);
            $table->index(['company_id', 'user_id', 'trip_date']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('distance_unit', 2)->default('km');
            // Minor units of the company currency per distance unit (e.g. 72 = 0.72 per km); null = not set.
            $table->unsignedInteger('mileage_rate')->nullable();
        });

        Schema::table('company_user', fn (Blueprint $table) => $table->string('trip_start_address', 255)->nullable());
    }

    public function down(): void
    {
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('trip_start_address'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['distance_unit', 'mileage_rate']));
        Schema::dropIfExists('trips');
    }
};
