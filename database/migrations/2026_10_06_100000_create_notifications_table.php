<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's inbox: one row per recipient, not one per occurrence, so that
 * "read" is each person's own answer.
 *
 * Laravel's own shape (notifiable + data + read_at) so its helpers keep
 * working, plus the few fields the screen filters on as real indexed columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');

            $table->string('category', 40)->default('system');
            $table->string('event', 60);
            $table->string('level', 20)->default('info');

            // The actor's name is frozen, like the audit log: the notice is
            // testimony about what happened, and outlives the account.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();

            // The record it speaks of — the screen opens it from here.
            $table->nullableMorphs('subject');

            // Title, body and link: rendered, never queried.
            $table->json('data');

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell badge counts one user's unread on every request.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
