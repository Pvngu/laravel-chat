<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Threads: a reply's thread_id is the message it replies to, which keeps how many replies it has and when the last one
// came; the people following a thread (who started it, replied, or were mentioned) and what they've read of it. Its own
// migration, so apps that already have the chat tables only need to publish and run this one.
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('laravel-chat.table_prefix', 'chat_');

        Schema::table($prefix.'messages', function (Blueprint $table) {
            $table->unsignedBigInteger('thread_id')->nullable();
            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamp('last_reply_at')->nullable();

            $table->index(['thread_id', 'id']);
        });

        Schema::create($prefix.'thread_followers', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('thread_id')->constrained($prefix.'messages')->cascadeOnDelete();
            match (config('laravel-chat.user_key_type', 'bigint')) {
                'uuid' => $table->uuid('user_id'),
                'ulid' => $table->ulid('user_id'),
                'string' => $table->string('user_id'),
                default => $table->unsignedBigInteger('user_id'),
            };
            // Replies after this one are unread
            $table->unsignedBigInteger('last_read_reply_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        $prefix = config('laravel-chat.table_prefix', 'chat_');

        Schema::dropIfExists($prefix.'thread_followers');
        Schema::table($prefix.'messages', function (Blueprint $table) {
            $table->dropIndex(['thread_id', 'id']);
            $table->dropColumn(['thread_id', 'reply_count', 'last_reply_at']);
        });
    }
};
