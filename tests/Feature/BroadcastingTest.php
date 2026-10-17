<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Pvngu\Chat\Events\ConversationJoined;
use Pvngu\Chat\Events\MessageDeleted;
use Pvngu\Chat\Events\MessageSent;
use Pvngu\Chat\Events\MessageUpdated;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

class BroadcastingTest extends TestCase
{
    public function test_message_events_go_to_the_conversation_channel(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $conversation = $this->directBetween($ann, $john);
        Event::fake([MessageSent::class, MessageUpdated::class, MessageDeleted::class]);

        $message = Chat::send($conversation, $ann, 'Hello');
        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($conversation, $message) {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()[0]->name === "private-chat.conversation.{$conversation->id}"
                && $event->broadcastAs() === 'message.sent'
                && $payload['kind'] === 'conversation'
                && $payload['conversationId'] === $conversation->id
                && $payload['message']['id'] === (string) $message->id
                && $payload['message']['text'] === 'Hello';
        });

        Chat::edit($message, 'Hello!');
        Chat::react($message, $john, '👍');
        Chat::pin($message, true);
        Event::assertDispatchedTimes(MessageUpdated::class, 3);

        Chat::delete($message);
        Event::assertDispatched(MessageDeleted::class, fn (MessageDeleted $event) => $event->broadcastWith() === [
            'kind' => 'conversation',
            'conversationId' => $conversation->id,
            'messageId' => (string) $message->id,
        ]);
    }

    public function test_people_hear_about_conversations_they_join_on_their_own_channel(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        Event::fake([ConversationJoined::class]);

        $conversation = $this->directBetween($ann, $john);

        // Only John is told: Ann started it
        Event::assertDispatchedTimes(ConversationJoined::class, 1);
        Event::assertDispatched(ConversationJoined::class, function (ConversationJoined $event) use ($john, $conversation) {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()[0]->name === "private-chat.user.{$john->id}"
                && $payload['kind'] === 'conversation'
                && $payload['conversation']['id'] === $conversation->id;
        });
    }

    public function test_a_new_message_brings_an_archived_conversation_back_to_whoever_archived_it(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $direct = $this->directBetween($ann, $john);
        Chat::setFlag($direct, $ann, 'archived', true);
        Event::fake([ConversationJoined::class]);

        // Only Ann had archived it; the chat comes back to her as she now sees it
        Chat::send($direct, $john, 'Still there?');
        Event::assertDispatchedTimes(ConversationJoined::class, 1);
        Event::assertDispatched(ConversationJoined::class, fn (ConversationJoined $event) => $event->user->is($ann)
            && $event->broadcastWith()['conversation']['lastMessage']['text'] === 'Still there?'
            && $event->broadcastWith()['conversation']['unreadCount'] === 1);

        // Nobody has it archived any more
        Chat::send($direct, $john, 'Hello?');
        Event::assertDispatchedTimes(ConversationJoined::class, 1);

        // A reply only in a thread doesn't bring it back
        Chat::setFlag($direct, $ann, 'archived', true);
        Chat::send($direct, $john, 'In the thread', thread: $direct->messages()->latest('id')->first());
        Event::assertDispatchedTimes(ConversationJoined::class, 1);
    }

    public function test_a_chat_you_join_says_which_message_its_counts_already_include(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        Event::fake([ConversationJoined::class]);

        // John starts a direct message and sends its first message before the queued event is rendered
        $direct = $this->directBetween($john, $ann);
        $first = Chat::send($direct, $john, 'Hi Ann');
        Event::assertDispatched(ConversationJoined::class, function (ConversationJoined $event) use ($ann, $first) {
            $conversation = $event->broadcastWith()['conversation'];

            return $event->user->is($ann)
                && $conversation['unreadCount'] === 1
                && $conversation['lastMessage']['id'] === (string) $first->id;
        });

        // A channel says it as lastMessageId
        $channel = Chat::channel($john, 'general');
        Chat::addMember($channel, $ann, by: $john);
        $news = Chat::send($channel, $john, 'News');
        Event::assertDispatched(ConversationJoined::class, fn (ConversationJoined $event) => $event->user->is($ann)
            && $event->broadcastWith()['kind'] === 'channel'
            && $event->broadcastWith()['conversation']['lastMessageId'] === (string) $news->id
            && $event->broadcastWith()['conversation']['unreadCount'] === 1);
    }

    public function test_the_sender_also_hears_that_their_archived_chat_is_back(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $direct = $this->directBetween($ann, $john);
        Chat::setFlag($direct, $ann, 'archived', true);
        Event::fake([ConversationJoined::class, MessageSent::class]);

        // Replying from a tab that had the archived chat open without listing it
        $this->actingAs($ann)
            ->postJson("/api/chat/conversations/{$direct->id}/messages", ['body' => 'Back again'], ['X-Socket-ID' => '1234.5678'])
            ->assertCreated();

        Event::assertDispatched(ConversationJoined::class, fn (ConversationJoined $event) => $event->user->is($ann) && $event->socket === null);
        // The message itself still skips the browser that sent it
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->socket === '1234.5678');
    }

    public function test_channel_authorization(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $eve = $this->person('Eve');
        $conversation = $this->directBetween($ann, $john);
        $public = Chat::channel($john, 'general');

        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $conversationChannel = $channels['chat.conversation.{conversationId}'];
        $userChannel = $channels['chat.user.{publicId}'];

        $this->assertTrue($conversationChannel($ann, (string) $conversation->id));
        $this->assertFalse($conversationChannel($eve, (string) $conversation->id));
        $this->assertTrue($conversationChannel($eve, (string) $public->id));
        $this->assertFalse($conversationChannel($ann, 'nope'));
        $this->assertTrue($userChannel($ann, (string) $ann->id));
        $this->assertFalse($userChannel($ann, (string) $john->id));
    }

    public function test_broadcasting_can_be_turned_off(): void
    {
        config(['laravel-chat.broadcasting.enabled' => false]);
        $conversation = $this->directBetween($this->person('A'), $this->person('B'));
        $message = Chat::send($conversation, $conversation->participants()->first()->user, 'Hi');

        $this->assertFalse((new MessageSent($message))->broadcastWhen());
    }
}
