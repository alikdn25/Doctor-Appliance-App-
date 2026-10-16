<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax reserve (UX-REDESIGN §17): how much to move to the tax account each month. Settings on the company and the
 * transfers the Owner marks as done. Only new columns and a new table. Approved by the owner on October 15, 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('tax_reserve_enabled')->default(false);
            // Income and dividend tax, % of the month's net profit.
            $table->decimal('tax_reserve_rate', 5, 2)->default(21);
            // Take the recoverable sales tax paid on expenses off the sales tax collected.
            $table->boolean('tax_reserve_deduct_expense_tax')->default(false);
            // paid = invoices paid in the month; issued = invoices issued in the month.
            $table->string('tax_reserve_basis', 10)->default('paid');
            // Day of the month to remind the Owner (1–28); null = no reminder.
            $table->unsignedTinyInteger('tax_reserve_reminder_day')->nullable();
            // The month (Y-m) the last reminder was about, so it goes once.
            $table->string('tax_reserve_reminded_month', 7)->nullable();
        });

        Schema::create('tax_reserve_transfers', function (Blueprint $table) {
            $table->id();
            // No cascade: deleting a company never takes its records with it (data protection).
            $table->foreignId('company_id')->constrained();
            // First day of the month the money was set aside for.
            $table->date('month');
            // Minor units of the currency.
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->date('transferred_on');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_reserve_transfers');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'tax_reserve_enabled', 'tax_reserve_rate', 'tax_reserve_deduct_expense_tax', 'tax_reserve_basis',
            'tax_reserve_reminder_day', 'tax_reserve_reminded_month',
        ]));
    }
};
