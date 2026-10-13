<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('laravel-chat.table_prefix', 'chat_');

        Schema::create($prefix.'conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // direct | group | channel
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(true);
            // '' when the app isn't multi-tenant, so the unique index below also works without tenants
            $table->string('tenant', 64)->default('');
            // "keyA:keyB" for a direct conversation, so two people only ever have one
            $table->string('direct_key')->nullable();
            $this->userColumn($table, 'created_by')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant', 'direct_key']);
            $table->index(['tenant', 'type']);
        });

        Schema::create($prefix.'participants', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('conversation_id')->constrained($prefix.'conversations')->cascadeOnDelete();
            $this->userColumn($table, 'user_id');
            $table->string('role', 16)->default('member'); // member | admin
            // Everything after this message is unread: one row per person, however many messages arrive
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('favorited_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('notifications', 16)->default('all'); // all | mentions | none
            $table->timestamp('muted_until')->nullable(); // notifications paused until then
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create($prefix.'messages', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('conversation_id')->constrained($prefix.'conversations')->cascadeOnDelete();
            $this->userColumn($table, 'sender_id')->nullable();
            $table->text('body');
            $table->json('gif')->nullable(); // provider, id, title, width, height, video, image, still
            $table->foreignId('reply_to_id')->nullable()->constrained($prefix.'messages')->nullOnDelete();
            $table->unsignedBigInteger('forwarded_from_id')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create($prefix.'attachments', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('message_id')->constrained($prefix.'messages')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create($prefix.'reactions', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('message_id')->constrained($prefix.'messages')->cascadeOnDelete();
            $this->userColumn($table, 'user_id');
            $table->string('emoji', 32);
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'user_id', 'emoji']);
        });

        // Who a message mentions: people by name (`<@id>` in its text), everyone (`<!everyone>`) or everyone online
        // (`<!here>`). For unread mention counts and notifications.
        Schema::create($prefix.'mentions', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('message_id')->constrained($prefix.'messages')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained($prefix.'conversations')->cascadeOnDelete();
            $this->userColumn($table, 'user_id');
            $table->string('via', 16)->default('person'); // person | everyone | here

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'conversation_id', 'message_id']);
        });
    }

    public function down(): void
    {
        $prefix = config('laravel-chat.table_prefix', 'chat_');

        Schema::dropIfExists($prefix.'mentions');
        Schema::dropIfExists($prefix.'reactions');
        Schema::dropIfExists($prefix.'attachments');
        Schema::dropIfExists($prefix.'messages');
        Schema::dropIfExists($prefix.'participants');
        Schema::dropIfExists($prefix.'conversations');
    }

    // A column holding a user's primary key, in the type of your users table's key
    private function userColumn(Blueprint $table, string $name): ColumnDefinition
    {
        return match (config('laravel-chat.user_key_type', 'bigint')) {
            'uuid' => $table->uuid($name),
            'ulid' => $table->ulid($name),
            'string' => $table->string($name),
            default => $table->unsignedBigInteger($name),
        };
    }
};
