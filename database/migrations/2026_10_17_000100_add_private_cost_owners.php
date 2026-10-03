<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoice_items', 'estimate_items', 'services'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('cost_owner_id')->nullable()->constrained('users')->nullOnDelete());
        }
        // Historical document costs belong to the original document author. Unknown price-book ownership stays private.
        DB::statement('UPDATE invoice_items SET cost_owner_id = invoices.created_by FROM invoices WHERE invoice_items.invoice_id = invoices.id AND invoice_items.unit_cost IS NOT NULL');
        DB::statement('UPDATE estimate_items SET cost_owner_id = estimates.created_by FROM estimates WHERE estimate_items.estimate_id = estimates.id AND estimate_items.unit_cost IS NOT NULL');
    }

    public function down(): void
    {
        foreach (['invoice_items', 'estimate_items', 'services'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('cost_owner_id'));
        }
    }
};
