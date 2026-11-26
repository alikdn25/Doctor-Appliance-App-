<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One window to close a job (owner's task of November 26):
 * - service_jobs.close_request: the id of the last "Done" of the Close job window; the same request sent twice (a
 *   double tap, a retry) is applied once.
 * - users.review_profile_id: the review location last picked in "Request review" (picked again next time).
 * Nothing is removed and no row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('close_request', 64)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('review_profile_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropColumn('close_request'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('review_profile_id'));
    }
};
