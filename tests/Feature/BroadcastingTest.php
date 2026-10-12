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
