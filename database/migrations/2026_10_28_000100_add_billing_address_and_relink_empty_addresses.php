<?php

use App\Support\Customers\EmptyAddresses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's update of October 24 (booking and customer):
 * - The customer is the one who pays: a billing address of their own (empty = the customer's primary address).
 * - Jobs, estimates, invoices and appliances still on an empty address move to the customer's primary address
 *   (the October 19 clean-up moved them only when the customer had exactly one address).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('billing_line1')->nullable();
            $table->string('billing_unit', 50)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_region', 50)->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->char('billing_country', 2)->nullable();
        });

        EmptyAddresses::clean();
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn([
            'billing_line1', 'billing_unit', 'billing_city', 'billing_region', 'billing_postal_code', 'billing_country',
        ]));
    }
};
