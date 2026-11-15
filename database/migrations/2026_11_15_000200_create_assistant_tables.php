<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI assistant (owner's task of November 15, PR 2): the chat as sent to the AI (append-only, one row per message)
 * and the actions it proposes, which run only after Confirm. New tables; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->uuid('conversation');
            // user (a question or tool results) or assistant (the AI's content blocks).
            $table->string('role', 20);
            $table->json('content');
            // What the chat shows for this row; null for tool results and pure tool calls.
            $table->text('text')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'user_id', 'conversation', 'id']);
        });

        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->uuid('conversation');
            $table->string('type', 40);
            $table->json('params');
            $table->text('summary');
            // pending → confirmed / cancelled / failed
            $table->string('status', 20)->default('pending');
            $table->text('result')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'conversation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
        Schema::dropIfExists('assistant_messages');
    }
};
