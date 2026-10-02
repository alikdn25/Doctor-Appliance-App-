<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates, invoices and payments. Money is stored in cents (bigint).
 * Line items and taxes are copied onto the document, so later price or tax rate changes
 * never change an existing estimate or invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Key of the online payment provider (SPEC §7.6), null = manual payments only.
            $table->string('payment_provider', 30)->nullable()->after('travel_buffer_minutes');
        });

        foreach (['estimates', 'invoices'] as $documents) {
            Schema::create($documents, function (Blueprint $table) use ($documents) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('service_job_id')->constrained();
                $table->foreignId('brand_id')->constrained();
                $table->foreignId('customer_id')->constrained();
                $table->foreignId('property_id')->nullable()->constrained();
                if ($documents === 'invoices') {
                    $table->foreignId('estimate_id')->nullable()->constrained();
                }
                $table->string('number', 40);
                $table->string('status', 20);
                $table->date('issued_on');
                $table->date($documents === 'invoices' ? 'due_on' : 'valid_until')->nullable();
                $table->string('discount_type', 10)->nullable();
                $table->decimal('discount_value', 12, 2)->default(0);
                $table->bigInteger('subtotal')->default(0);
                $table->bigInteger('discount_total')->default(0);
                $table->bigInteger('tax_total')->default(0);
                $table->bigInteger('total')->default(0);
                // Snapshot: [{tax_rate_id, name, rate, amount}]
                $table->jsonb('taxes')->default(DB::raw("'[]'::jsonb"));
                $table->text('notes')->nullable();
                if ($documents === 'estimates') {
                    $table->timestamp('approved_at')->nullable();
                    $table->timestamp('declined_at')->nullable();
                } else {
                    $table->bigInteger('amount_paid')->default(0);
                    $table->bigInteger('balance')->default(0);
                    $table->timestamp('paid_at')->nullable();
                    $table->timestamp('voided_at')->nullable();
                    $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->string('void_reason', 500)->nullable();
                }
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'number']);
                $table->index(['company_id', 'service_job_id']);
                $table->index(['company_id', 'customer_id']);
                $table->index(['company_id', 'status']);
            });

            $parent = $documents === 'estimates' ? 'estimate_id' : 'invoice_id';

            Schema::create(rtrim($documents, 's').'_items', function (Blueprint $table) use ($documents, $parent) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId($parent)->constrained($documents)->cascadeOnDelete();
                $table->unsignedSmallInteger('position');
                $table->string('description', 500);
                $table->decimal('quantity', 10, 2);
                $table->bigInteger('unit_price');
                $table->boolean('taxable')->default(true);
                $table->bigInteger('total');
                $table->timestamps();

                $table->index(['company_id', $parent]);
            });
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained();
            $table->bigInteger('amount');
            $table->string('method', 20);
            // Cheque number, e-Transfer or terminal transaction reference.
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('received_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Set for payments recorded by an online payment provider (webhooks).
            $table->string('provider', 30)->nullable();
            $table->string('provider_payment_id', 100)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'invoice_id']);
            $table->unique(['company_id', 'provider', 'provider_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('payment_provider');
        });
    }
};
