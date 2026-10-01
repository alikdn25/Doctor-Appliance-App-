<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('gst_number', 50)->nullable();
            $table->string('business_number', 50)->nullable();
            $table->text('invoice_footer')->nullable();
            $table->text('invoice_terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('brand_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city', 100);
            $table->string('province', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2)->default('CA');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'brand_id']);
        });

        Schema::create('brand_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'user_id']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_user');
        Schema::dropIfExists('brand_addresses');
        Schema::dropIfExists('brands');
    }
};
