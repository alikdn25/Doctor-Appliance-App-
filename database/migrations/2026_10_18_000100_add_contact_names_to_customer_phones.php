<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each phone can belong to a named person ("Anna · wife"). A customer with a second person gets a couple icon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_phones', fn (Blueprint $table) => $table->string('contact_name', 100)->nullable()->after('label'));
        Schema::table('customers', fn (Blueprint $table) => $table->boolean('has_second_contact')->default(false));
    }

    public function down(): void
    {
        Schema::table('customer_phones', fn (Blueprint $table) => $table->dropColumn('contact_name'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('has_second_contact'));
    }
};
