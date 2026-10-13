<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Carbon;
use Pvngu\Chat\Contracts\ChatPresence;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Mention;
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

    public function test_everyone_mentions_every_other_member_without_listing_them(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $group = Chat::group($ann, [$john, $sara], 'Launch');

        $this->actingAs($ann)
            ->postJson("/api/chat/conversations/{$group->id}/messages", ['body' => "<!everyone> standup, <@{$john->id}> first"])
            ->assertCreated()
            ->assertJsonPath('data.text', "<!everyone> standup, <@{$john->id}> first")
            ->assertJsonPath('data.mentions', [['id' => (string) $john->id, 'name' => 'John Doe']]);

        foreach ([$john, $sara] as $person) {
            $this->actingAs($person)->getJson('/api/chat/conversations')
                ->assertJsonPath('data.0.mentionCount', 1)
                ->assertJsonPath('data.0.lastMessage.text', '@everyone standup, @John Doe first');
        }
        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonPath('data.0.mentionCount', 0);
        // Named people stay named; the rest come from @everyone
        $this->assertSame(
            [(string) $john->id => 'person', (string) $sara->id => 'everyone'],
            Mention::query()->orderBy('user_id')->pluck('via', 'user_id')->mapWithKeys(fn ($via, $id) => [(string) $id => $via])->all(),
        );
    }

    public function test_here_mentions_only_the_members_online_now(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $channel = Chat::channel($ann, 'general');
        Chat::addParticipant($channel, $john);
        Chat::addParticipant($channel, $sara);

        // John has the chat open (its heartbeat); Sara doesn't
        $this->actingAs($john)->postJson('/api/chat/presence')->assertNoContent();
        Chat::send($channel, $ann, '<!here> lunch?');

        $this->actingAs($john)->getJson('/api/chat/channels')->assertJsonPath('data.0.mentionCount', 1);
        $this->actingAs($sara)->getJson('/api/chat/channels')
            ->assertJsonPath('data.0.mentionCount', 0)
            ->assertJsonPath('data.0.unreadCount', 1);
    }

    public function test_presence_lasts_a_couple_of_minutes_after_the_last_request(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $presence = app(ChatPresence::class);
        $id = (string) $john->id;

        $this->assertSame([], $presence->statuses($ann, [$id]));
        $this->actingAs($john)->getJson('/api/chat/conversations')->assertOk(); // any chat request counts
        $this->assertSame([$id => 'online'], $presence->statuses($ann, [$id]));

        Carbon::setTestNow('2026-03-10 09:02:01');
        $this->assertSame([], $presence->statuses($ann, [$id]));
    }

    public function test_people_who_follow_only_mentions_hear_about_everyone_and_here(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $group = Chat::group($ann, [$john]);
        Chat::setNotifications($group, $john, 'mentions');

        $this->assertSame([], Chat::recipients(Chat::send($group, $ann, 'Plain update'))->all());
        $this->assertSame(['John Doe'], Chat::recipients(Chat::send($group, $ann, '<!everyone> heads up'))->pluck('name')->all());
    }
}
