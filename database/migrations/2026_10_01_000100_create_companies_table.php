<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active')->index();
            $table->string('plan', 50)->nullable();
            $table->string('subscription_status', 20)->nullable();
            $table->string('timezone', 64)->default('America/Vancouver');
            $table->char('currency', 3)->default('CAD');
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->unsignedInteger('invoice_next_number')->default(1);
            $table->string('estimate_prefix', 20)->default('EST-');
            $table->unsignedInteger('estimate_next_number')->default(1);
            $table->jsonb('business_hours')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_company_id')->references('id')->on('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_company_id']);
        });

        Schema::dropIfExists('companies');
    }
};
