<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import of customers and invoice history from Square (owner's task of November 15, PR 3).
 *
 * - customers.square_customer_id: the customer's id at Square (empty for everyone today), so a repeated import finds
 *   the same customer; customers.square_import_id: the import that created the customer (for Undo).
 * - square_imports: one run (who, when, status, progress, counts, what was merged).
 * - square_import_customers: the customers read from Square for the review screen, with their match and decision.
 * - square_invoices: the invoice history from Square, read-only, separate from the app's own invoices (no job, not in
 *   the tax reserve).
 *
 * Only new columns (nullable) and new tables; nothing existing changes or is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('square_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            // scanning → review → importing → done; failed; undone
            $table->string('status', 20);
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->string('step', 40)->nullable();
            $table->json('counts')->nullable();
            // What a merge added to an existing customer, so Undo can take it back.
            $table->json('merged')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'id']);
        });

        Schema::create('square_import_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('square_import_id')->constrained();
            $table->string('square_id', 64);
            $table->json('data');
            $table->string('square_updated_at', 40)->nullable();
            // new | match | linked (already imported before)
            $table->string('kind', 20);
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('matched_by', 20)->nullable();
            // merge | skip | new
            $table->string('decision', 10)->nullable();
            $table->boolean('has_contact')->default(true);

            $table->index(['square_import_id', 'kind']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('square_customer_id', 64)->nullable();
            $table->foreignId('square_import_id')->nullable()->constrained();
            $table->string('square_updated_at', 40)->nullable();

            $table->index(['company_id', 'square_customer_id']);
        });

        Schema::create('square_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('square_import_id')->constrained();
            $table->string('square_invoice_id', 64);
            $table->string('number', 100)->nullable();
            $table->string('status', 40);
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->string('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->json('items');
            $table->json('taxes');
            $table->json('payments');
            $table->string('square_updated_at', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'square_invoice_id']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_invoices');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'square_customer_id']);
            $table->dropConstrainedForeignId('square_import_id');
            $table->dropColumn(['square_customer_id', 'square_updated_at']);
        });
        Schema::dropIfExists('square_import_customers');
        Schema::dropIfExists('square_imports');
    }
};
