<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App review (owner's task of November 15, PR 5): what people do in the app (screens, taps, forms, searches, errors —
 * never customer data; kept 90 days), "Inconvenient" notes, and the reviews the Owner asked for with their
 * suggestions and statuses. New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('role', 20)->nullable();
            $table->string('session', 40)->nullable();
            $table->string('type', 20);
            $table->string('screen', 80)->nullable();
            $table->string('name', 80)->nullable();
            $table->unsignedInteger('value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'user_id', 'occurred_at']);
        });

        Schema::create('inconvenience_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('role', 20)->nullable();
            $table->string('screen', 80)->nullable();
            $table->text('text');
            $table->json('recent_actions');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('app_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamp('period_from');
            $table->timestamp('period_to');
            $table->string('period', 20);
            $table->json('summary');
            $table->json('report');
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'id']);
        });

        Schema::create('app_review_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('app_review_id')->constrained();
            $table->unsignedSmallInteger('position');
            $table->string('importance', 10);
            $table->string('title', 300);
            $table->text('why');
            $table->text('fix');
            // null (new) | done | not_needed | later
            $table->string('status', 20)->nullable();
            $table->timestamp('status_at')->nullable();

            $table->index(['company_id', 'app_review_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_review_suggestions');
        Schema::dropIfExists('app_reviews');
        Schema::dropIfExists('inconvenience_reports');
        Schema::dropIfExists('usage_events');
    }
};
