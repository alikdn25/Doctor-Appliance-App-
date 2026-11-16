<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to an invoice (owner's task of November 16: the photos or screenshots an invoice was made from in
 * the assistant). Private files, served only after an access check. New table; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_attachments');
    }
};
