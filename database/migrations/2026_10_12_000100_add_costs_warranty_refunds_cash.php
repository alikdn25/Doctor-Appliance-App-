<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parts and materials with cost on estimate/invoice lines, warranty per line, internal (not billed) lines, markup
 * scales, supplier receipts, job cost lines, refunds that settle an invoice, processor fees, cash on hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['estimate_items', 'invoice_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // service (labour, call-out …), part or material.
                $table->string('kind', 10)->default('service');
                $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
                $table->string('part_number', 100)->nullable();
                $table->string('supplier', 150)->nullable();
                // Unit of a material: ft, m, pcs, lb, oz or a custom word.
                $table->string('unit', 20)->nullable();
                // Cost per unit, minor units of the document currency.
                $table->bigInteger('unit_cost')->nullable();
                // Taxes paid to the supplier: [{tax_rate_id, name, amount, recoverable}].
                $table->json('supplier_taxes')->nullable();
                // Off = internal line (cost only): never shown to the customer and not in the total.
                $table->boolean('bill_to_customer')->default(true);
                // Warranty: 0 = no warranty.
                $table->unsignedSmallInteger('warranty_value')->nullable();
                $table->string('warranty_unit', 10)->nullable();
                $table->date('warranty_ends_on')->nullable();
            });
        }

        Schema::table('services', function (Blueprint $table) {
            $table->string('kind', 10)->default('service');
            $table->string('part_number', 100)->nullable();
            $table->string('supplier', 150)->nullable();
            $table->string('unit', 20)->nullable();
            $table->bigInteger('unit_cost')->nullable();
            $table->unsignedSmallInteger('warranty_value')->nullable();
            $table->string('warranty_unit', 10)->nullable();
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            // Tax paid to suppliers that the company gets back (e.g. GST input tax credits); otherwise it is a cost.
            $table->boolean('is_recoverable')->default(true);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_labor_value')->default(30);
            $table->string('warranty_labor_unit', 10)->default('days');
            $table->unsignedSmallInteger('warranty_parts_value')->default(90);
            $table->string('warranty_parts_unit', 10)->default('days');
            // Parts priced above this (minor units) get the second warranty (e.g. manufacturer warranty).
            $table->bigInteger('warranty_parts_threshold')->nullable();
            $table->unsignedSmallInteger('warranty_parts_above_value')->nullable();
            $table->string('warranty_parts_above_unit', 10)->nullable();
            $table->text('warranty_terms')->nullable();
            // Markup tiers: [{up_to: minor|null, multiplier: "2.0"}].
            $table->json('markup_parts')->nullable();
            $table->json('markup_materials')->nullable();
            $table->boolean('technicians_see_costs')->default(false);
            $table->boolean('accepts_cash')->default(true);
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Money given back that the customer no longer owes (warranty refunds …).
            $table->bigInteger('credited_amount')->default(0);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('processing_fee')->default(0);
            $table->string('refund_reason', 500)->nullable();
            // Photo of a cash receipt (optional), private disk.
            $table->string('receipt_path')->nullable();
        });

        // Costs of a job that are on no invoice (parts used on a no-charge job, consumables …).
        Schema::create('job_cost_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('part');
            $table->string('description');
            $table->string('part_number', 100)->nullable();
            $table->string('supplier', 150)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->nullable();
            $table->bigInteger('unit_cost')->default(0);
            $table->char('currency', 3);
            $table->json('supplier_taxes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'service_job_id']);
        });

        // Supplier receipts: strictly internal, kept for years (not removed with a job).
        Schema::create('supplier_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('supplier', 150)->nullable();
            $table->date('receipt_date')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_receipt_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_receipt_id')->constrained()->cascadeOnDelete();
            // No cascade: a deleted (soft) job keeps its receipts.
            $table->foreignId('service_job_id')->constrained();
            $table->string('line_label')->nullable();
            $table->timestamps();

            $table->unique(['supplier_receipt_id', 'service_job_id']);
            $table->index(['company_id', 'service_job_id']);
        });

        // Cash on hand: every movement of cash a technician holds. Rows are never deleted; mistakes are reversed.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            // collected (cash payment), deposit (handed to the office), reversal.
            $table->string('type', 20);
            // Signed, minor units: + collected, − deposited.
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->date('occurred_on');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reverses_id')->nullable()->constrained('cash_movements')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('supplier_receipt_links');
        Schema::dropIfExists('supplier_receipts');
        Schema::dropIfExists('job_cost_items');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['processing_fee', 'refund_reason', 'receipt_path']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'warranty_labor_value', 'warranty_labor_unit', 'warranty_parts_value', 'warranty_parts_unit',
                'warranty_parts_threshold', 'warranty_parts_above_value', 'warranty_parts_above_unit', 'warranty_terms',
                'markup_parts', 'markup_materials', 'technicians_see_costs', 'accepts_cash',
            ]);
        });
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropColumn('is_recoverable');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['kind', 'part_number', 'supplier', 'unit', 'unit_cost', 'warranty_value', 'warranty_unit']);
        });
        foreach (['estimate_items', 'invoice_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('service_id');
                $table->dropColumn([
                    'kind', 'part_number', 'supplier', 'unit', 'unit_cost', 'supplier_taxes', 'bill_to_customer',
                    'warranty_value', 'warranty_unit', 'warranty_ends_on',
                ]);
            });
        }
    }
};
