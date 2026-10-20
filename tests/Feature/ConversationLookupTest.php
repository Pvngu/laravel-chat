<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

class ConversationLookupTest extends TestCase
{
    public function test_it_finds_a_direct_message_you_archived_without_bringing_it_back(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $direct = $this->directBetween($ann, $john);
        Chat::send($direct, $john, 'Hi Ann');
        Chat::setFlag($direct, $ann, 'archived', true);

        $this->actingAs($ann)
            ->getJson("/api/chat/conversations/lookup?participantIds[]={$john->id}&isDirect=1")
            ->assertOk()
            ->assertJsonPath('data.id', $direct->id)
            ->assertJsonPath('data.lastMessage.text', 'Hi Ann')
            ->assertJsonPath('data.unreadCount', 1);

        // Still archived, and nothing new
        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('chat_conversations', 1);
    }

    public function test_nothing_is_found_or_started_without_a_direct_message(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $this->directBetween($john, $sara); // not Ann's

        $this->actingAs($ann)
            ->getJson("/api/chat/conversations/lookup?participantIds[]={$john->id}&isDirect=1")
            ->assertOk()
            ->assertExactJson(['data' => null]);
        $this->assertDatabaseCount('chat_conversations', 1);
    }

    public function test_a_group_is_new_every_time(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        Chat::group($ann, [$john, $sara], 'Ops');

        $this->actingAs($ann)
            ->getJson("/api/chat/conversations/lookup?participantIds[]={$john->id}&participantIds[]={$sara->id}&isDirect=0&name=Ops")
            ->assertOk()
            ->assertExactJson(['data' => null]);
        $this->assertDatabaseCount('chat_conversations', 1);
    }

    public function test_it_refuses_who_post_conversations_refuses(): void
    {
        $ann = $this->person('Ann Smith', 'acme');
        $john = $this->person('John Doe', 'acme');
        $sara = $this->person('Sara Lane', 'acme');
        $outsider = $this->person('Olga', 'globex');
        $lookup = fn (string $query) => $this->actingAs($ann)->getJson("/api/chat/conversations/lookup?$query");

        $lookup("participantIds[]={$outsider->id}&isDirect=1")->assertUnprocessable()->assertJsonValidationErrors('participantIds');
        $lookup('participantIds[]=not-an-id&isDirect=1')->assertUnprocessable()->assertJsonValidationErrors('participantIds');
        $lookup("participantIds[]={$john->id}&participantIds[]={$sara->id}&isDirect=0&name=".str_repeat('a', 121))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // Without groups (laravel-chat.features), only direct messages
        config(['laravel-chat.features.groups' => false]);
        $lookup("participantIds[]={$john->id}&participantIds[]={$sara->id}&isDirect=0")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['participantIds', 'isDirect']);
        $this->assertDatabaseCount('chat_conversations', 0);
    }
}
