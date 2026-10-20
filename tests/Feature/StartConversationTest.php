<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Events\ConversationJoined;
use Pvngu\Chat\Events\MessageSent;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Tests\TestCase;

class StartConversationTest extends TestCase
{
    public function test_a_direct_message_starts_with_its_first_message(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');

        $response = $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true, 'body' => 'Hi John'])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('firstMessage.text', 'Hi John')
            ->assertJsonPath('data.isDirect', true)
            ->assertJsonPath('data.unreadCount', 0); // her own message
        $this->assertSame($response->json('firstMessage.id'), $response->json('data.lastMessage.id'));

        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.id', $response->json('data.id'))
            ->assertJsonPath('data.0.unreadCount', 1)
            ->assertJsonPath('data.0.lastMessage.text', 'Hi John');
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_the_others_hear_about_it_once_with_the_message_in_it(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        Event::fake([ConversationJoined::class, MessageSent::class]);

        $response = $this->actingAs($ann)
            ->postJson(
                '/api/chat/conversations',
                ['participantIds' => [(string) $john->id], 'isDirect' => true, 'body' => 'Hi John'],
                ['X-Socket-ID' => '1234.5678'],
            )
            ->assertCreated();

        // John's sidebar gets it with its message already counted
        Event::assertDispatched(ConversationJoined::class, function (ConversationJoined $event) use ($john, $response) {
            $conversation = $event->broadcastWith()['conversation'];

            return $event->user->is($john)
                && $conversation['unreadCount'] === 1
                && $conversation['lastMessage']['id'] === $response->json('firstMessage.id');
        });
        // Ann's other tabs and devices, not the one that sent it
        Event::assertDispatched(ConversationJoined::class, fn (ConversationJoined $event) => $event->user->is($ann) && $event->socket === '1234.5678');
        Event::assertDispatchedTimes(ConversationJoined::class, 2);
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }

    public function test_a_group_starts_with_a_mention(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', [
                'participantIds' => [(string) $john->id, (string) $sara->id],
                'isDirect' => false,
                'name' => 'Launch',
                'body' => "Hi <@{$john->id}>",
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Launch')
            ->assertJsonPath('data.isDirect', false)
            ->assertJsonCount(3, 'data.participants');

        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.mentionCount', 1)
            ->assertJsonPath('data.0.unreadCount', 1);
        $this->actingAs($sara)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.mentionCount', 0)
            ->assertJsonPath('data.0.unreadCount', 1);
    }

    public function test_it_starts_with_files(): void
    {
        Storage::fake();
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');

        $response = $this->actingAs($ann)
            ->post('/api/chat/conversations', [
                'participantIds' => [(string) $john->id],
                'isDirect' => '1',
                'body' => '',
                'attachments' => [UploadedFile::fake()->image('a.png')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('firstMessage.text', '')
            ->assertJsonCount(1, 'firstMessage.attachments');

        $this->assertCount(1, Storage::allFiles('chat-attachments/'.$response->json('data.id')));
    }

    public function test_it_starts_with_a_gif_alone_unless_gifs_are_off(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true, 'body' => '', 'gif' => $this->gif()])
            ->assertCreated()
            ->assertJsonPath('firstMessage.gif.id', 'hello-hi-662')
            ->assertJsonPath('data.lastMessage.text', 'GIF');

        config(['laravel-chat.features.gifs' => false]);
        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $sara->id], 'isDirect' => true, 'body' => '', 'gif' => $this->gif()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gif');
        $this->assertDatabaseCount('chat_conversations', 1);
    }

    public function test_a_direct_message_both_had_archived_gets_the_message_and_comes_back(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $direct = $this->directBetween($ann, $john);
        Chat::setFlag($direct, $ann, 'archived', true);
        Chat::setFlag($direct, $john, 'archived', true);

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true, 'body' => 'Back again'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $direct->id)
            ->assertJsonPath('firstMessage.text', 'Back again');

        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonPath('data.0.id', $direct->id);
        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.id', $direct->id)
            ->assertJsonPath('data.0.unreadCount', 1);
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_nothing_is_saved_when_the_message_is_refused(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $start = fn (array $message) => $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true, ...$message]);

        $start(['body' => str_repeat('a', 10001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        // A first message replies to nothing and forwards nothing
        $start(['body' => 'Hi', 'replyToId' => '1'])->assertUnprocessable()->assertJsonValidationErrors('replyToId');
        // An empty message doesn't start an empty conversation instead
        $start(['body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->assertNothingStarted();
    }

    public function test_nothing_is_saved_or_heard_when_sending_is_denied(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        Gate::before(fn ($user, string $ability) => $ability === 'send' ? false : null);
        Event::fake([ConversationJoined::class, MessageSent::class]);

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true, 'body' => 'Hi John'])
            ->assertForbidden();

        $this->assertNothingStarted();
        Event::assertNotDispatched(ConversationJoined::class);
        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_without_groups_a_direct_message_still_starts_with_its_message(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        config(['laravel-chat.features.groups' => false]);

        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true, 'name' => 'Launch', 'body' => 'Hi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true, 'body' => 'Hi'])
            ->assertCreated()
            ->assertJsonPath('created', true);
    }

    public function test_it_starts_from_code(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');

        [$conversation, $message] = Chat::start($ann, [$john], true, null, 'Hi');
        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertTrue($conversation->wasRecentlyCreated);
        $this->assertSame('Hi', $message->body);

        // The same direct message gets the next one
        [$again, $second] = Chat::start($ann, [$john], true, null, 'Again');
        $this->assertSame($conversation->id, $again->id);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame($conversation->id, $second->conversation_id);
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame($conversation->id, Chat::findDirect($john, $ann)?->id);
    }

    private function assertNothingStarted(): void
    {
        $this->assertDatabaseCount('chat_conversations', 0);
        $this->assertDatabaseCount('chat_participants', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    /** @return array<string, mixed> */
    private function gif(): array
    {
        return [
            'provider' => 'klipy',
            'id' => 'hello-hi-662',
            'title' => 'Hello',
            'width' => 498,
            'height' => 280,
            'video' => 'https://static.klipy.com/ii/935d7ab9/md.mp4',
            'image' => 'https://static.klipy.com/ii/935d7ab9/md.webp',
            'still' => 'https://static1.klipy.com/ii/935d7ab9/md.jpg',
        ];
    }
}
