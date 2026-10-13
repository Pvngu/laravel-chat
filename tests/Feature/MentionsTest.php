<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

class MentionsTest extends TestCase
{
    public function test_mentions_are_read_from_the_text_and_shown_by_name(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $outsider = $this->person('Olga', 'globex');
        $group = Chat::group($ann, [$john, $sara], 'Launch');

        $this->actingAs($ann)
            ->postJson("/api/chat/conversations/{$group->id}/messages", [
                'body' => "Ready, <@{$john->id}>? Also <@{$sara->id}>, <@{$john->id}> and <@{$outsider->id}>",
            ])
            ->assertCreated()
            ->assertJsonPath('data.text', "Ready, <@{$john->id}>? Also <@{$sara->id}>, <@{$john->id}> and <@{$outsider->id}>")
            ->assertJsonPath('data.mentions', [
                ['id' => (string) $john->id, 'name' => 'John Doe'],
                ['id' => (string) $sara->id, 'name' => 'Sara Lane'],
            ]);

        // Previews read as text; someone from another tenant isn't looked up
        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.lastMessage.text', 'Ready, @John Doe? Also @Sara Lane, @John Doe and @unknown');
    }

    public function test_unread_mentions_are_counted_until_read(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $conversation = $this->directBetween($ann, $john);

        Chat::send($conversation, $john, "Note to self, <@{$john->id}>"); // mentioning yourself doesn't count
        Chat::send($conversation, $ann, "Hi <@{$john->id}>");
        Chat::send($conversation, $ann, 'No mention here');

        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.unreadCount', 2)
            ->assertJsonPath('data.0.mentionCount', 1);

        Chat::markRead($conversation, $john);
        $this->actingAs($john)->getJson('/api/chat/conversations')->assertJsonPath('data.0.mentionCount', 0);
    }

    public function test_editing_updates_the_mentions_and_replies_quote_them_by_name(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $group = Chat::group($ann, [$john, $sara]);
        $message = Chat::send($group, $ann, "Over to <@{$john->id}>");

        $this->actingAs($ann)
            ->patchJson("/api/chat/messages/{$message->id}", ['body' => "Over to <@{$sara->id}>"])
            ->assertJsonPath('data.mentions', [['id' => (string) $sara->id, 'name' => 'Sara Lane']]);

        $this->actingAs($sara)
            ->postJson("/api/chat/conversations/{$group->id}/messages", ['body' => 'On it', 'replyToId' => (string) $message->id])
            ->assertJsonPath('data.replyTo.text', 'Over to @Sara Lane');
    }
}
