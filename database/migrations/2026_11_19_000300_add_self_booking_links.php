<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 19, part 3: self-booking by link (/book/{token}: 7 days, one booking) and the "Self-booked"
 * mark on jobs. A new table and one column with a default; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('token', 64)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 32)->nullable();
            $table->string('name')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('service_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'phone']);
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->boolean('self_booked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropColumn('self_booked'));
        Schema::dropIfExists('booking_links');
    }
};
