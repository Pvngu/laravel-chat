<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Carbon;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

class ConversationsTest extends TestCase
{
    public function test_it_lists_your_conversations_latest_first_with_unread_counts(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');

        Carbon::setTestNow('2026-03-10 09:00:00');
        $withJohn = $this->directBetween($ann, $john);
        Chat::send($withJohn, $john, 'Hi Ann');
        Chat::send($withJohn, $john, 'Lunch?');

        Carbon::setTestNow('2026-03-10 10:00:00');
        $group = Chat::group($ann, [$john, $sara], 'Design Team');
        Chat::send($group, $ann, 'Kickoff at 11');

        // Not Ann's: two other people, and a channel
        $this->directBetween($john, $sara);
        Chat::channel($ann, 'general');

        $response = $this->actingAs($ann)->getJson('/api/chat/conversations')->assertOk();

        $this->assertSame([$group->id, $withJohn->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.1', [
            'id' => $withJohn->id,
            'isDirect' => true,
            'isPrivate' => true,
            'title' => null,
            'description' => null,
            'participants' => [
                ['id' => (string) $ann->id, 'name' => 'Ann Smith', 'email' => 'ann.smith@example.com', 'avatarUrl' => null],
                ['id' => (string) $john->id, 'name' => 'John Doe', 'email' => 'john.doe@example.com', 'avatarUrl' => null],
            ],
            'lastMessage' => [
                'text' => 'Lunch?',
                'sentAt' => '2026-03-10T09:00:00Z',
                'sender' => ['id' => (string) $john->id, 'name' => 'John Doe', 'email' => 'john.doe@example.com', 'avatarUrl' => null],
            ],
            'unreadCount' => 2,
            'isPinned' => false,
            'isFavorite' => false,
            'notifications' => 'all',
            'mutedUntil' => null,
            'mentionCount' => 0,
            'isAdmin' => true,
            'createdAt' => '2026-03-10T09:00:00Z',
            'createdBy' => ['id' => (string) $ann->id, 'name' => 'Ann Smith', 'email' => 'ann.smith@example.com', 'avatarUrl' => null],
        ]);
        $response->assertJsonPath('data.0.title', 'Design Team');
        $response->assertJsonPath('data.0.unreadCount', 0); // her own message
        $this->assertNull($response->json('links.next'));
    }

    public function test_it_pages_through_conversations(): void
    {
        config(['laravel-chat.pagination.conversations' => 2]);
        $ann = $this->person('Ann Smith');
        foreach (['B', 'C', 'D'] as $name) {
            $this->directBetween($ann, $this->person($name));
        }

        $first = $this->actingAs($ann)->getJson('/api/chat/conversations')->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertNotNull($first->json('links.next'));

        $second = $this->actingAs($ann)->getJson('/api/chat/conversations?page=2')->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('links.next'));
    }

    public function test_starting_a_direct_message_reuses_the_existing_one(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');

        $created = $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true])
            ->assertCreated();

        // John starting it from his side gets the same conversation
        $this->actingAs($john)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $ann->id], 'isDirect' => true])
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));
    }

    public function test_it_starts_a_group(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', [
                'participantIds' => [(string) $john->id, (string) $sara->id],
                'isDirect' => false,
                'name' => 'Launch',
            ])
            ->assertCreated()
            ->assertJsonPath('data.isDirect', false)
            ->assertJsonPath('data.title', 'Launch')
            ->assertJsonCount(3, 'data.participants');
    }

    public function test_nobody_outside_your_tenant_can_be_added(): void
    {
        $ann = $this->person('Ann Smith', 'acme');
        $outsider = $this->person('Olga', 'globex');

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $outsider->id], 'isDirect' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('participantIds');

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => ['not-an-id'], 'isDirect' => true])
            ->assertUnprocessable();
    }

    public function test_pinned_favorite_and_archived_are_per_person(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $conversation = $this->directBetween($ann, $john);

        $this->actingAs($ann)->postJson("/api/chat/conversations/{$conversation->id}/pin", ['pinned' => true])->assertNoContent();
        $this->actingAs($ann)->postJson("/api/chat/conversations/{$conversation->id}/favorite", ['favorite' => true])->assertNoContent();

        $this->actingAs($ann)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.isPinned', true)
            ->assertJsonPath('data.0.isFavorite', true);
        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.isPinned', false)
            ->assertJsonPath('data.0.isFavorite', false);

        // Archived conversations leave the list until a new message arrives
        $this->actingAs($ann)->postJson("/api/chat/conversations/{$conversation->id}/archive")->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonCount(0, 'data');

        Chat::send($conversation, $john, 'Still there?');
        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonCount(1, 'data');
    }

    public function test_reading_clears_the_unread_count(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $conversation = $this->directBetween($ann, $john);
        Chat::send($conversation, $john, 'One');
        Chat::send($conversation, $john, 'Two');

        $this->actingAs($ann)->postJson("/api/chat/conversations/{$conversation->id}/read")->assertNoContent();

        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonPath('data.0.unreadCount', 0);
    }

    public function test_other_peoples_conversations_are_not_found(): void
    {
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $eve = $this->person('Eve');
        $conversation = $this->directBetween($john, $sara);

        $this->actingAs($eve)->getJson("/api/chat/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($eve)->getJson("/api/chat/conversations/{$conversation->id}/messages")->assertNotFound();
        $this->actingAs($eve)->postJson("/api/chat/conversations/{$conversation->id}/messages", ['body' => 'hi'])->assertNotFound();
        $this->actingAs($eve)->postJson("/api/chat/conversations/{$conversation->id}/read")->assertNotFound();
        $this->actingAs($eve)->postJson("/api/chat/conversations/{$conversation->id}/members", ['userId' => (string) $eve->id])->assertNotFound();
        $this->actingAs($eve)->getJson('/api/chat/conversations/abc')->assertNotFound();
    }

    public function test_guests_are_turned_away(): void
    {
        $this->getJson('/api/chat/conversations')->assertUnauthorized();
    }
}
