<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

class ChannelsTest extends TestCase
{
    public function test_it_lists_your_channels_and_the_public_ones_in_your_tenant(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $general = Chat::channel($john, 'general');                    // public, Ann isn't in it
        $design = Chat::channel($ann, 'design', isPrivate: true);      // private, Ann's
        Chat::channel($john, 'secret', isPrivate: true);               // private, not Ann's
        Chat::channel($this->person('Olga', 'globex'), 'globex-news'); // another tenant
        $welcome = Chat::send($general, $john, 'Welcome');

        $response = $this->actingAs($ann)->getJson('/api/chat/channels')->assertOk();

        $this->assertSame(['design', 'general'], array_column($response->json('data'), 'name'));
        $response->assertJsonPath('data.0', [
            'id' => $design->id,
            'name' => 'design',
            'isPrivate' => true,
            'description' => null,
            'members' => [['id' => (string) $ann->id, 'name' => 'Ann Smith', 'email' => 'ann.smith@example.com', 'avatarUrl' => null, 'role' => 'admin']],
            'unreadCount' => 0,
            'lastMessageId' => null,
            'isMember' => true,
            'isFavorite' => false,
            'notifications' => 'all',
            'mutedUntil' => null,
            'mentionCount' => 0,
            'isAdmin' => true,
            'createdAt' => $design->created_at->toIso8601ZuluString(),
            'createdBy' => ['id' => (string) $ann->id, 'name' => 'Ann Smith', 'email' => 'ann.smith@example.com', 'avatarUrl' => null],
        ]);
        // Not a member yet: nothing counts as unread
        $response->assertJsonPath('data.1.isMember', false)->assertJsonPath('data.1.unreadCount', 0);
        // The latest message a client may find counted already
        $response->assertJsonPath('data.1.lastMessageId', (string) $welcome->id);
    }

    public function test_it_creates_channels_with_names_unique_per_tenant(): void
    {
        $ann = $this->person('Ann Smith');

        $this->actingAs($ann)->postJson('/api/chat/channels', ['name' => 'design', 'isPrivate' => true])
            ->assertCreated()
            ->assertJsonPath('data.isPrivate', true)
            ->assertJsonPath('data.isMember', true);
        $this->actingAs($ann)->postJson('/api/chat/channels', ['name' => 'design'])
            ->assertJsonValidationErrors('name');
        $this->actingAs($this->person('Olga', 'globex'))->postJson('/api/chat/channels', ['name' => 'design'])
            ->assertCreated();
    }

    public function test_public_channels_can_be_read_and_joined_by_posting(): void
    {
        $ann = $this->person('Ann Smith');
        $general = Chat::channel($this->person('John Doe'), 'general');
        Chat::send($general, $general->participants()->first()->user, 'Welcome');

        $this->actingAs($ann)->getJson("/api/chat/channels/{$general->id}/messages")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($ann)->postJson("/api/chat/channels/{$general->id}/messages", ['body' => 'Hi all'])->assertCreated();

        $this->actingAs($ann)->getJson('/api/chat/channels')->assertJsonPath('data.0.isMember', true);
    }

    public function test_private_channels_and_other_tenants_are_not_found(): void
    {
        $secret = Chat::channel($this->person('John Doe'), 'secret', isPrivate: true);
        $foreign = Chat::channel($this->person('Olga', 'globex'), 'news');
        $ann = $this->person('Ann Smith');

        $this->actingAs($ann)->getJson("/api/chat/channels/{$secret->id}/messages")->assertNotFound();
        $this->actingAs($ann)->getJson("/api/chat/channels/{$foreign->id}/messages")->assertNotFound();
        $this->actingAs($ann)->postJson("/api/chat/channels/{$foreign->id}/messages", ['body' => 'Hi'])->assertNotFound();
    }

    public function test_members_add_people_admins_remove_them_and_anyone_can_leave(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $channel = Chat::channel($ann, 'design', isPrivate: true); // Ann is the admin
        Chat::addParticipant($channel, $john);

        // In a private channel only admins add people, from the same tenant
        $this->actingAs($john)->postJson("/api/chat/channels/{$channel->id}/members", ['userId' => (string) $sara->id])->assertForbidden();
        $this->actingAs($ann)->postJson("/api/chat/channels/{$channel->id}/members", ['userId' => (string) $sara->id])->assertNoContent();
        $this->actingAs($ann)->postJson("/api/chat/channels/{$channel->id}/members", ['userId' => (string) $this->person('Olga', 'globex')->id])
            ->assertJsonValidationErrors('userId');

        // Only admins remove others
        $this->actingAs($john)->deleteJson("/api/chat/channels/{$channel->id}/members/{$sara->id}")->assertForbidden();
        $this->actingAs($ann)->deleteJson("/api/chat/channels/{$channel->id}/members/{$sara->id}")->assertNoContent();

        $this->actingAs($john)->deleteJson("/api/chat/channels/{$channel->id}/members/me")->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/channels')->assertJsonCount(1, 'data.0.members');
        $this->actingAs($john)->getJson("/api/chat/channels/{$channel->id}/messages")->assertNotFound();
    }

    public function test_direct_messages_keep_their_two_people(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $direct = $this->directBetween($ann, $john);

        $this->actingAs($ann)->postJson("/api/chat/conversations/{$direct->id}/members", ['userId' => (string) $this->person('Sara Lane')->id])
            ->assertForbidden();
        $this->actingAs($ann)->deleteJson("/api/chat/conversations/{$direct->id}/members/me")->assertForbidden();
    }

    public function test_channels_can_be_favorited(): void
    {
        $ann = $this->person('Ann Smith');
        $channel = Chat::channel($ann, 'design');

        $this->actingAs($ann)->postJson("/api/chat/channels/{$channel->id}/favorite", ['favorite' => true])->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/channels')->assertJsonPath('data.0.isFavorite', true);
    }
}
