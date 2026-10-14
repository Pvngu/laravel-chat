<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class SavedMessagesTest extends TestCase
{
    private User $ann;

    private User $john;

    private Conversation $conversation;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ann = $this->person('Ann Smith');
        $this->john = $this->person('John Doe');
        $this->conversation = $this->directBetween($this->ann, $this->john);
        $this->message = Chat::send($this->conversation, $this->john, 'The launch checklist');
    }

    private function savedUrl(?Message $message = null): string
    {
        return '/api/chat/messages/'.($message ?? $this->message)->id.'/saved';
    }

    public function test_you_save_list_and_unsave_messages()
    {
        $this->actingAs($this->ann)->putJson($this->savedUrl())->assertCreated();
        $this->actingAs($this->ann)->putJson($this->savedUrl())->assertNoContent(); // already saved

        $this->travel(1)->minute();
        $channel = Chat::channel($this->john, 'general');
        $later = Chat::send($channel, $this->john, 'Standup at ten');
        Chat::saveMessage($this->ann, $later);
        Chat::saveMessage($this->john, $this->message); // someone else's

        $this->actingAs($this->ann)->getJson('/api/chat/saved')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.message.text', 'Standup at ten') // newest first
            ->assertJsonPath('data.0.chat', ['kind' => 'channel', 'id' => $channel->id, 'name' => 'general', 'isDirect' => false])
            ->assertJsonPath('data.1.message.id', (string) $this->message->id)
            ->assertJsonPath('data.1.message.senderId', (string) $this->john->id)
            ->assertJsonPath('data.1.chat.isDirect', true)
            ->assertJsonPath('data.1.savedAt', fn (string $at) => $at !== '');

        $this->actingAs($this->ann)->deleteJson($this->savedUrl())->assertNoContent();
        $this->actingAs($this->ann)->getJson('/api/chat/saved')->assertJsonCount(1, 'data');
    }

    public function test_you_only_save_messages_you_can_see()
    {
        $this->actingAs($this->person('Sara Lane'))->putJson($this->savedUrl())->assertNotFound();

        $joined = Chat::send($this->conversation, $this->john, '');
        $joined->forceFill(['event' => Message::EVENT_JOINED, 'event_user_id' => $this->john->id])->save();
        $this->actingAs($this->ann)->putJson($this->savedUrl($joined))->assertJsonValidationErrors('message');
    }

    public function test_deleted_messages_and_chats_you_left_drop_out()
    {
        $group = Chat::group($this->john, [$this->ann], 'Launch plan');
        $inGroup = Chat::send($group, $this->john, 'Budget');
        $deleted = Chat::send($this->conversation, $this->john, 'Oops');
        foreach ([$this->message, $inGroup, $deleted] as $message) {
            Chat::saveMessage($this->ann, $message);
        }

        Chat::delete($deleted);
        Chat::removeMember($group, $this->ann);

        $this->actingAs($this->ann)->getJson('/api/chat/saved')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message.text', 'The launch checklist');
    }
}
