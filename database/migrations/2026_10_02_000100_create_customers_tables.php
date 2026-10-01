<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name')->nullable();
            $table->string('display_name');
            $table->string('lead_source', 50)->nullable();
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'display_name']);
            $table->index(['company_id', 'type']);
        });

        DB::statement('CREATE INDEX customers_tags_gin ON customers USING gin (tags)');

        Schema::create('customer_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20)->default('mobile');
            $table->string('number', 32);
            $table->string('number_normalized', 20);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'number_normalized']);
            $table->index('customer_id');
        });

        Schema::create('customer_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20)->default('personal');
            $table->string('email');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'email']);
            $table->index('customer_id');
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('city', 100);
            $table->string('province', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2)->default('CA');
            // Filled by geocoding (Google Places) in a later task.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('access_notes')->nullable();
            $table->string('gate_code', 100)->nullable();
            $table->string('site_contact_name')->nullable();
            $table->string('site_contact_phone', 32)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('appliances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('manufacturer', 100)->nullable();
            $table->string('model_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('rating_plate_path')->nullable();
            $table->date('install_date')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_expires_on')->nullable();
            $table->text('warranty_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'property_id']);
            $table->index(['company_id', 'model_number']);
            $table->index(['company_id', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appliances');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('customer_emails');
        Schema::dropIfExists('customer_phones');
        Schema::dropIfExists('customers');
    }
};
