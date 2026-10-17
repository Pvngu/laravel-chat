<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Carbon;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Support\Features;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class UnreadTest extends TestCase
{
    public function test_it_counts_the_chats_with_a_badge_and_their_unread_mentions(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $this->assertSame(url('/api/chat/unread'), route('chat.unread'));
        $this->assertUnread($ann, chats: 0, mentions: 0);

        $direct = $this->directBetween($ann, $john);
        Chat::send($direct, $john, 'Hi Ann');
        Chat::send($direct, $john, 'Lunch?');
        $this->assertUnread($ann, chats: 1, mentions: 0);

        // A chat with mentions counts once, with each unread mention (@everyone too)
        $group = Chat::group($ann, [$john, $sara], 'Launch');
        Chat::send($group, $sara, "Ready, <@{$ann->id}>?");
        Chat::send($group, $john, '<!everyone> standup');
        $this->assertUnread($ann, chats: 2, mentions: 2);

        $channel = Chat::channel($ann, 'general');
        Chat::addMember($channel, $john); // "joined" isn't news
        $news = Chat::send($channel, $john, 'News');
        $this->assertUnread($ann, chats: 3, mentions: 2);

        // Your own messages, membership lines and replies only in a thread aren't unread in the chat
        Chat::send($this->directBetween($ann, $sara), $ann, 'Hello Sara');
        Chat::markRead($channel, $ann);
        Chat::addMember($channel, $sara, by: $john);
        Chat::send($channel, $john, 'Details in the thread', thread: $news);
        Chat::send($channel, $john, "<@{$ann->id}> can you check?", thread: $news); // counts in the thread
        $this->assertUnread($ann, chats: 2, mentions: 2);

        $this->actingAs($ann)->postJson("/api/chat/conversations/{$group->id}/read")->assertNoContent();
        $this->assertUnread($ann, chats: 1, mentions: 0);
        $this->assertSame(['chats' => 1, 'mentions' => 0], Chat::unread($ann));

        // Everyone has their own
        $this->assertUnread($john, chats: 0, mentions: 0);
        $this->assertUnread($sara, chats: 2, mentions: 1);
    }

    public function test_a_mention_in_a_thread_counts_in_the_chat_only_when_sent_there_too(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $channel = Chat::channel($ann, 'general');
        Chat::addMember($channel, $john);
        $news = Chat::send($channel, $john, 'News');
        Chat::markRead($channel, $ann);

        // Unread in its thread (whose follower she is: she's mentioned), not in the chat
        Chat::send($channel, $john, "<@{$ann->id}> details in the thread", thread: $news);
        $this->assertUnread($ann, chats: 0, mentions: 0);
        $this->actingAs($ann)->getJson('/api/chat/channels')
            ->assertJsonPath('data.0.unreadCount', 0)
            ->assertJsonPath('data.0.mentionCount', 0);

        Chat::send($channel, $john, "<@{$ann->id}> and here", thread: $news, alsoInChat: true);
        $this->assertUnread($ann, chats: 1, mentions: 1);
        $this->actingAs($ann)->getJson('/api/chat/channels')
            ->assertJsonPath('data.0.unreadCount', 1)
            ->assertJsonPath('data.0.mentionCount', 1);
    }

    public function test_muted_chats_have_no_badge_and_some_only_count_mentions(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');

        $mentionsOnly = Chat::group($ann, [$john], 'Mentions only');
        Chat::setNotifications($mentionsOnly, $ann, 'mentions');
        Chat::send($mentionsOnly, $john, 'Plain message');
        $this->assertUnread($ann, chats: 0, mentions: 0);
        Chat::send($mentionsOnly, $john, "Over to you, <@{$ann->id}>");
        $this->assertUnread($ann, chats: 1, mentions: 1);

        // Muted chats stay quiet, mentions too
        $muted = $this->directBetween($ann, $john);
        Chat::setNotifications($muted, $ann, 'none');
        Chat::send($muted, $john, "Hey <@{$ann->id}>");
        $paused = Chat::channel($john, 'deploys');
        Chat::addParticipant($paused, $ann);
        Chat::setNotifications($paused, $ann, 'all', Carbon::parse('2026-03-10 10:00:00'));
        Chat::send($paused, $john, "Deploying, <@{$ann->id}>");
        $this->assertUnread($ann, chats: 1, mentions: 1);

        // Until the pause ends
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->assertUnread($ann, chats: 2, mentions: 2);
    }

    public function test_only_what_the_sidebar_lists_counts(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');

        // An archived conversation, until a new message brings it back
        $direct = $this->directBetween($ann, $john);
        Chat::send($direct, $john, 'Hi Ann');
        $this->actingAs($ann)->postJson("/api/chat/conversations/{$direct->id}/archive")->assertNoContent();
        $this->assertUnread($ann, chats: 0, mentions: 0);
        Chat::send($direct, $john, 'Still there?');
        $this->assertUnread($ann, chats: 1, mentions: 0);

        // Channels Ann could join, or can't see, have nothing unread for her
        Chat::send(Chat::channel($john, 'random'), $john, "Anyone? <!everyone> <@{$ann->id}>");
        Chat::send(Chat::channel($john, 'secret', isPrivate: true), $john, 'Psst');
        // Nor does a chat from another tenant
        $olga = $this->person('Olga', 'globex');
        Chat::send(Chat::direct($olga, $ann), $olga, 'Hello from Globex');
        $this->assertUnread($ann, chats: 1, mentions: 0);

        // GET channels lists a channel even when it's archived
        $general = Chat::channel($john, 'general');
        Chat::addParticipant($general, $ann);
        Chat::send($general, $john, 'Welcome');
        Chat::setFlag($general, $ann, 'archived', true);
        $this->assertUnread($ann, chats: 2, mentions: 0);
    }

    public function test_channels_dont_count_when_that_feature_is_off(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        Chat::send($this->directBetween($ann, $john), $john, 'Hi Ann');
        $channel = Chat::channel($ann, 'general');
        Chat::addParticipant($channel, $john);
        Chat::send($channel, $john, "Welcome, <@{$ann->id}>");
        $this->assertUnread($ann, chats: 2, mentions: 1);

        config(['laravel-chat.features.channels' => false]);
        $this->assertUnread($ann, chats: 1, mentions: 0);
    }

    public function test_asking_isnt_being_around_in_the_chat(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $statuses = fn () => $this->actingAs($john)->getJson("/api/chat/presence?ids={$ann->id}")->json('data.statuses');

        // A badge elsewhere in the app asks for it, so it doesn't make Ann online
        $this->actingAs($ann)->getJson('/api/chat/unread', ['X-Socket-ID' => '1.1'])->assertOk();
        $this->assertSame([], $statuses());

        $this->actingAs($ann)->getJson('/api/chat/conversations', ['X-Socket-ID' => '1.1'])->assertOk();
        $this->assertSame([(string) $ann->id => 'online'], $statuses());
    }

    public function test_guests_are_turned_away(): void
    {
        $this->getJson('/api/chat/unread')->assertUnauthorized();
    }

    // GET unread gives these totals, and so do the badges vue-chat shows for what GET conversations and GET channels list
    private function assertUnread(User $user, int $chats, int $mentions): void
    {
        $expected = ['chats' => $chats, 'mentions' => $mentions];
        $this->actingAs($user)->getJson('/api/chat/unread')->assertOk()->assertExactJson(['data' => $expected]);
        $this->assertSame($expected, $this->sidebarTotals($user), 'The sidebar badges add up to something else');
    }

    // vue-chat's unreadBadge() (src/lib/notifications.ts) over the sidebar's chats
    private function sidebarTotals(User $user): array
    {
        $chats = $this->actingAs($user)->getJson('/api/chat/conversations')->json('data');
        if (Features::enabled('channels')) {
            $chats = [...$chats, ...$this->actingAs($user)->getJson('/api/chat/channels')->json('data')];
        }

        $totals = ['chats' => 0, 'mentions' => 0];
        foreach ($chats as $chat) {
            $muted = $chat['notifications'] === 'none' || ($chat['mutedUntil'] && Carbon::parse($chat['mutedUntil'])->isFuture());
            if ($muted) {
                continue;
            }
            if ($chat['mentionCount'] > 0) {
                $totals['chats']++;
                $totals['mentions'] += $chat['mentionCount'];
            } elseif ($chat['notifications'] === 'all' && $chat['unreadCount'] > 0) {
                $totals['chats']++;
            }
        }

        return $totals;
    }
}
