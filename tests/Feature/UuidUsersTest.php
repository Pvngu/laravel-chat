<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\Fixtures\UuidUser;
use Pvngu\Chat\Tests\TestCase;

class UuidUsersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', UuidUser::class);
        $app['config']->set('laravel-chat.user_key_type', 'uuid');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures/uuid-migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_it_works_with_uuid_user_keys(): void
    {
        $ann = UuidUser::query()->create(['name' => 'Ann Smith', 'email' => 'ann@example.com', 'team' => 'acme']);
        $john = UuidUser::query()->create(['name' => 'John Doe', 'email' => 'john@example.com', 'team' => 'acme']);

        $conversationId = $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [$john->id], 'isDirect' => true])
            ->assertCreated()
            ->json('data.id');

        $message = Chat::send(Chat::direct($john, $ann), $john, 'Hi Ann');
        $this->actingAs($ann)->postJson("/api/chat/messages/{$message->id}/reactions", ['emoji' => '👋'])
            ->assertJsonPath('data.reactions.0.participantIds', [$ann->id]);

        $this->actingAs($ann)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.unreadCount', 1)
            ->assertJsonPath('data.0.lastMessage.sender.id', $john->id);
        $this->actingAs($ann)->getJson("/api/chat/conversations/$conversationId/messages")
            ->assertJsonPath('data.0.senderId', $john->id);
    }
}
