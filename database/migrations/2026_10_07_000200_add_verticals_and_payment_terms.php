<?php

use App\Enums\Vertical;
use App\Support\Jobs\ServiceDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vertical of a company (SPEC §1.2), its starting set of services, and payment terms (SPEC §7.6):
 * a company default that a customer can override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('vertical', 30)->default('appliance_repair')->after('country');
            $table->string('default_payment_terms', 20)->default('due_on_receipt')->after('prices_include_tax');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Null = the company's default terms.
            $table->string('payment_terms', 20)->nullable()->after('lead_source');
        });

        // Starting services of the price book. Prices are left to the company (null = not set yet).
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->bigInteger('unit_price')->nullable();
            $table->boolean('taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'sort_order']);
        });

        // Existing companies are appliance repair companies: give them the starting services too.
        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (ServiceDefaults::forVertical(Vertical::ApplianceRepair) as $position => $item) {
                DB::table('services')->insert([
                    ...$item,
                    'company_id' => $companyId,
                    'sort_order' => $position + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('payment_terms'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['vertical', 'default_payment_terms']));
    }
};
