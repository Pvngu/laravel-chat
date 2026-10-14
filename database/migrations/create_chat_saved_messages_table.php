<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Messages people saved for later: one row per person and message. Its own migration, so apps that already have the
// chat tables only need to publish and run this one.
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('laravel-chat.table_prefix', 'chat_');

        Schema::create($prefix.'saved_messages', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('message_id')->constrained($prefix.'messages')->cascadeOnDelete();
            match (config('laravel-chat.user_key_type', 'bigint')) {
                'uuid' => $table->uuid('user_id'),
                'ulid' => $table->ulid('user_id'),
                'string' => $table->string('user_id'),
                default => $table->unsignedBigInteger('user_id'),
            };
            $table->timestamps();

            $table->unique(['user_id', 'message_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('laravel-chat.table_prefix', 'chat_').'saved_messages');
    }
};
