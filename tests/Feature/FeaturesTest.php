<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Support\Features;
use Pvngu\Chat\Tests\TestCase;

class FeaturesTest extends TestCase
{
    public function test_every_feature_is_on_unless_turned_off(): void
    {
        $ann = $this->person('Ann Smith');
        $response = $this->actingAs($ann)->getJson('/api/chat/features')->assertOk();
        $this->assertSame(array_fill_keys(Features::ALL, true), $response->json('data'));

        config(['laravel-chat.features.threads' => false, 'laravel-chat.features.reminders' => false]);
        $this->actingAs($ann)->getJson('/api/chat/features')
            ->assertJsonPath('data.threads', false)
            ->assertJsonPath('data.reminders', false)
            ->assertJsonPath('data.channels', true);
    }

    public function test_the_routes_of_a_feature_that_is_off_are_a_404(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $chat = $this->directBetween($ann, $john);
        $channel = Chat::channel($ann, 'general');
        $message = Chat::send($chat, $ann, 'Hello');

        config(['laravel-chat.features' => array_fill_keys(Features::ALL, false)]);
        $this->actingAs($ann);
        $routes = [
            ['getJson', '/api/chat/channels'],
            ['getJson', "/api/chat/channels/{$channel->id}/messages"],
            ['getJson', '/api/chat/threads'],
            ['getJson', "/api/chat/messages/{$message->id}/replies"],
            ['getJson', '/api/chat/saved'],
            ['getJson', '/api/chat/reminders'],
            ['postJson', "/api/chat/messages/{$message->id}/reactions"],
            ['postJson', "/api/chat/messages/{$message->id}/pin"],
            ['patchJson', "/api/chat/messages/{$message->id}"],
            ['deleteJson', "/api/chat/messages/{$message->id}"],
            ['getJson', "/api/chat/conversations/{$chat->id}/files"],
            ['postJson', "/api/chat/conversations/{$chat->id}/notifications"],
            ['getJson', '/api/chat/presence'],
        ];
        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertNotFound();
        }

        // What isn't a feature still works
        $this->getJson("/api/chat/conversations/{$chat->id}/messages")->assertOk();
        $this->postJson("/api/chat/conversations/{$chat->id}/messages", ['body' => 'Still here'])->assertCreated();
    }

    public function test_the_fields_of_a_feature_that_is_off_are_refused(): void
    {
        $ann = $this->person('Ann Smith');
        $chat = $this->directBetween($ann, $this->person('John Doe'));
        $message = Chat::send($chat, $ann, 'Hello');
        config(['laravel-chat.features' => [
            'attachments' => false, 'gifs' => false, 'replies' => false, 'forwarding' => false,
        ]]);

        $this->actingAs($ann)
            ->post("/api/chat/conversations/{$chat->id}/messages", [
                'body' => 'Look',
                'attachments' => [UploadedFile::fake()->create('notes.txt', 1)],
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments');
        $this->postJson("/api/chat/conversations/{$chat->id}/messages", [
            'body' => 'Hi',
            'replyToId' => (string) $message->id,
            'forwardedFromId' => (string) $message->id,
            'gif' => ['provider' => 'klipy', 'id' => '1', 'width' => 1, 'height' => 1, 'image' => 'x', 'still' => 'x'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['replyToId', 'forwardedFromId', 'gif']);
    }

    public function test_without_groups_only_direct_messages_start(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        config(['laravel-chat.features.groups' => false]);

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', [
                'participantIds' => [(string) $john->id, (string) $sara->id],
                'name' => 'Launch',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['participantIds', 'isDirect', 'name']);
        $this->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true])
            ->assertCreated();
    }

    public function test_without_mentions_none_are_recorded(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $chat = $this->directBetween($ann, $john);
        config(['laravel-chat.features.mentions' => false]);

        $this->actingAs($ann)
            ->postJson("/api/chat/conversations/{$chat->id}/messages", ['body' => "Hi <@{$john->id}>"])
            ->assertCreated()
            ->assertJsonPath('data.mentions', []);
        $this->actingAs($john)->getJson('/api/chat/conversations')->assertJsonPath('data.0.mentionCount', 0);
    }
}
