<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Pvngu\Chat\Events\MessageSent;
use Pvngu\Chat\Events\MessageUpdated;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class ThreadsTest extends TestCase
{
    private User $ann;

    private User $john;

    private User $sara;

    private Conversation $channel;

    private Message $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ann = $this->person('Ann Smith');
        $this->john = $this->person('John Doe');
        $this->sara = $this->person('Sara Lane');
        $this->channel = Chat::channel($this->ann, 'general');
        Chat::addParticipant($this->channel, $this->john, notify: false);
        Chat::addParticipant($this->channel, $this->sara, notify: false);
        $this->root = Chat::send($this->channel, $this->ann, 'Launch on Friday?');
    }

    private function repliesUrl(?Message $message = null): string
    {
        return '/api/chat/messages/'.($message ?? $this->root)->id.'/replies';
    }

    public function test_replies_go_in_the_thread_not_the_chat()
    {
        Event::fake([MessageSent::class, MessageUpdated::class]);

        $this->actingAs($this->john)->postJson($this->repliesUrl(), ['body' => 'Works for me'])
            ->assertCreated()
            ->assertJsonPath('data.threadId', (string) $this->root->id)
            ->assertJsonPath('data.text', 'Works for me');
        $this->travel(1)->minute();
        $this->actingAs($this->sara)->postJson($this->repliesUrl(), ['body' => 'Same'])->assertCreated();

        // Live: the reply, and the message it hangs off with its new count
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->thread_id === $this->root->id);
        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => $event->message->is($this->root));

        // The chat shows the message with its reply count, not the replies
        $this->actingAs($this->ann)->getJson("/api/chat/channels/{$this->channel->id}/messages")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.replyCount', 2)
            ->assertJsonPath('data.0.lastReplyAt', now()->toIso8601ZuluString())
            ->assertJsonPath('data.0.threadId', null);

        $this->actingAs($this->ann)->getJson($this->repliesUrl())
            ->assertOk()
            ->assertJsonPath('data.*.text', ['Works for me', 'Same']);

        // Replies don't count as unread in the chat
        $this->actingAs($this->ann)->getJson('/api/chat/channels')->assertJsonPath('data.0.unreadCount', 0);
    }

    public function test_replies_dont_become_a_conversations_latest_message()
    {
        $group = Chat::group($this->ann, [$this->john], 'Launch plan');
        $root = Chat::send($group, $this->ann, 'Budget?');
        Chat::send($group, $this->john, 'Approved', thread: $root);

        $this->actingAs($this->ann)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.lastMessage.text', 'Budget?')
            ->assertJsonPath('data.0.unreadCount', 0);
    }

    public function test_threads_you_follow_say_what_you_have_not_seen()
    {
        Chat::send($this->channel, $this->john, 'Works for me', thread: $this->root);
        Chat::send($this->channel, $this->sara, "<@{$this->ann->id}> and the docs?", thread: $this->root);
        $other = Chat::send($this->channel, $this->sara, 'Lunch?');

        // Ann started it: she follows it and hasn't seen either reply
        $this->actingAs($this->ann)->getJson('/api/chat/threads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message.id', (string) $this->root->id)
            ->assertJsonPath('data.0.message.replyCount', 2)
            ->assertJsonPath('data.0.chat', ['kind' => 'channel', 'id' => $this->channel->id, 'name' => 'general', 'isDirect' => false])
            ->assertJsonPath('data.0.unreadCount', 2);

        // John replied, so he follows it and has seen up to his reply
        $this->actingAs($this->john)->getJson('/api/chat/threads')->assertJsonPath('data.0.unreadCount', 1);

        $this->actingAs($this->ann)->postJson("/api/chat/messages/{$this->root->id}/thread/read")->assertNoContent();
        $this->actingAs($this->ann)->getJson('/api/chat/threads')->assertJsonPath('data.0.unreadCount', 0);

        // Only the people in a thread hear about its replies (and anyone it mentions)
        $reply = Chat::send($this->channel, $this->john, 'One more thing', thread: $this->root);
        $this->assertEqualsCanonicalizing(
            [$this->ann->id, $this->sara->id],
            Chat::recipients($reply)->map->getKey()->all(),
        );
        $this->assertCount(2, Chat::recipients($other));
    }

    public function test_a_reply_also_sent_to_the_chat_shows_in_both()
    {
        $this->actingAs($this->john)
            ->postJson($this->repliesUrl(), ['body' => 'Everyone: Friday it is', 'alsoSendToChat' => true])
            ->assertCreated()
            ->assertJsonPath('data.alsoInChat', true)
            ->assertJsonPath('data.threadRoot', [
                'messageId' => (string) $this->root->id,
                'senderName' => 'Ann Smith',
                'text' => 'Launch on Friday?',
            ]);
        Chat::send($this->channel, $this->sara, 'Only in the thread', thread: $this->root);

        $this->actingAs($this->ann)->getJson("/api/chat/channels/{$this->channel->id}/messages")
            ->assertJsonPath('data.*.text', ['Launch on Friday?', 'Everyone: Friday it is'])
            ->assertJsonPath('data.0.replyCount', 2);
        $this->actingAs($this->ann)->getJson($this->repliesUrl())->assertJsonCount(2, 'data');

        // News for the chat: unread there (the reply only in the thread isn't), and everyone hears about it as their
        // settings say
        $this->actingAs($this->ann)->getJson('/api/chat/channels')->assertJsonPath('data.0.unreadCount', 1);
        $inChat = Message::query()->where('also_in_chat', true)->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->ann->id, $this->sara->id], Chat::recipients($inChat)->map->getKey()->all());
    }

    public function test_the_threads_you_follow_come_with_their_latest_replies()
    {
        foreach (['One', 'Two', 'Three', 'Four'] as $text) {
            Chat::send($this->channel, $this->john, $text, thread: $this->root);
        }
        $this->travel(1)->minute();
        $other = Chat::send($this->channel, $this->sara, 'Another thread');
        Chat::send($this->channel, $this->ann, 'Its only reply', thread: $other);

        $this->actingAs($this->ann)->getJson('/api/chat/threads')
            ->assertJsonPath('data.0.message.text', 'Another thread')
            ->assertJsonPath('data.0.latestReplies.*.text', ['Its only reply'])
            ->assertJsonPath('data.1.latestReplies.*.text', ['Two', 'Three', 'Four']); // the latest three, oldest first
    }

    public function test_deleting_a_reply_counts_it_out()
    {
        $reply = Chat::send($this->channel, $this->john, 'Oops', thread: $this->root);
        Chat::send($this->channel, $this->sara, 'Kept', thread: $this->root);

        $this->actingAs($this->john)->deleteJson("/api/chat/messages/{$reply->id}")->assertNoContent();

        $this->assertSame(1, $this->root->fresh()->reply_count);
        $this->actingAs($this->ann)->getJson($this->repliesUrl())->assertJsonPath('data.*.text', ['Kept']);
    }

    public function test_threads_hang_off_messages_people_can_see()
    {
        $reply = Chat::send($this->channel, $this->john, 'A reply', thread: $this->root);
        $this->actingAs($this->ann)->postJson($this->repliesUrl($reply), ['body' => 'Nested?'])
            ->assertJsonValidationErrors('message');

        $outsider = $this->person('Other Team', 'globex');
        $this->actingAs($outsider)->getJson($this->repliesUrl())->assertNotFound();
        $this->actingAs($outsider)->postJson($this->repliesUrl(), ['body' => 'Hi'])->assertNotFound();
        $this->actingAs($this->ann)->postJson($this->repliesUrl(), ['body' => ''])->assertJsonValidationErrors('body');

        // A reply quotes a message of its own thread
        $this->actingAs($this->ann)->postJson($this->repliesUrl(), ['body' => 'Yes', 'replyToId' => (string) $reply->id])
            ->assertCreated()
            ->assertJsonPath('data.replyTo.messageId', (string) $reply->id);
        $elsewhere = Chat::send($this->channel, $this->john, 'Elsewhere');
        $this->actingAs($this->ann)->postJson($this->repliesUrl(), ['body' => 'No', 'replyToId' => (string) $elsewhere->id])
            ->assertJsonValidationErrors('replyToId');
    }
}
