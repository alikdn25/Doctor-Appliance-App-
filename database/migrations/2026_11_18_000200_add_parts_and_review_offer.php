<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 18, part 2: the part a job waits for (number, supplier, order date, the AI's suggestion)
 * and when "Send a review request?" was offered after an invoice was paid. New nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('part_name', 255)->nullable();
            $table->string('part_number', 100)->nullable();
            $table->string('part_supplier', 255)->nullable();
            $table->date('part_ordered_on')->nullable();
            // What the AI suggested (a possible part number marked "check"), never saved into the fields by itself.
            $table->jsonb('part_suggestion')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('review_offered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('review_offered_at'));
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropColumn([
            'part_name', 'part_number', 'part_supplier', 'part_ordered_on', 'part_suggestion',
        ]));
    }
};
