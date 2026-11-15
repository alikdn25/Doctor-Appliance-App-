<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Find problems (owner's task of November 15, PR 4): the company's limits for jobs waiting for parts (7 days) and
 * estimates without an answer (3 days), and the reports the Owner asked for. New columns with defaults and a new
 * table, and the version of the assistant's tools on its messages; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('parts_stale_days')->default(7);
            $table->unsignedSmallInteger('estimate_stale_days')->default(3);
        });

        Schema::create('problem_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->uuid('conversation')->nullable();
            // The chat message it came after (its place in the chat).
            $table->unsignedBigInteger('after_message_id')->nullable();
            // What the code found (sent to the AI) and the AI's answer.
            $table->json('findings');
            $table->json('report');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'user_id', 'conversation']);
        });

        // The assistant's tools of a conversation never change; a new set of tools starts a new conversation.
        Schema::table('assistant_messages', fn (Blueprint $table) => $table->unsignedSmallInteger('tools_version')->default(1));
        Schema::table('assistant_actions', fn (Blueprint $table) => $table->unsignedBigInteger('after_message_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('assistant_actions', fn (Blueprint $table) => $table->dropColumn('after_message_id'));
        Schema::table('assistant_messages', fn (Blueprint $table) => $table->dropColumn('tools_version'));
        Schema::dropIfExists('problem_reports');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['parts_stale_days', 'estimate_stale_days']));
    }
};
