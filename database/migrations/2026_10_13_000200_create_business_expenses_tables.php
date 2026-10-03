<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 160);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'normalized_name']);
            $table->unique(['id', 'company_id']);
        });

        Schema::create('business_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id');
            $table->date('spent_on');
            $table->string('description', 255);
            $table->string('merchant', 150)->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->char('currency', 3);
            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['category_id', 'company_id'])->references(['id', 'company_id'])->on('business_expense_categories');
            $table->index(['company_id', 'spent_on']);
            $table->index(['company_id', 'created_by', 'spent_on']);
            $table->index(['company_id', 'category_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_expenses');
        Schema::dropIfExists('business_expense_categories');
    }
};
