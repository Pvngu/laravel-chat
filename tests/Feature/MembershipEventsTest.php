<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Pvngu\Chat\Events\MessageSent;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class MembershipEventsTest extends TestCase
{
    public function test_adding_removing_joining_and_leaving_show_in_the_chat(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $luis = $this->person('Luis Ortega');
        $channel = Chat::channel($ann, 'general');
        $url = "/api/chat/channels/{$channel->id}";

        $this->actingAs($ann)->postJson("$url/members", ['userId' => (string) $john->id])->assertNoContent();
        $this->actingAs($sara)->postJson("$url/messages", ['body' => 'Hi all'])->assertCreated(); // posting joins it
        Chat::addMember($channel, $luis); // joining from code
        $this->actingAs($luis)->postJson("$url/messages", ['body' => 'Hello'])->assertCreated();
        $this->actingAs($ann)->deleteJson("$url/members/{$john->id}")->assertNoContent();
        $this->actingAs($sara)->deleteJson("$url/members/me")->assertNoContent();

        $messages = $this->actingAs($ann)->getJson("$url/messages")->assertOk()->json('data');
        $this->assertSame(
            [
                ['Ann Smith', 'added', 'John Doe', ''],
                ['Sara Lane', 'joined', 'Sara Lane', ''],
                ['Sara Lane', null, null, 'Hi all'],
                ['Luis Ortega', 'joined', 'Luis Ortega', ''],
                ['Luis Ortega', null, null, 'Hello'],
                ['Ann Smith', 'removed', 'John Doe', ''],
                ['Sara Lane', 'left', 'Sara Lane', ''],
            ],
            array_map(fn (array $message) => [
                User::query()->findOrFail($message['senderId'])->name,
                $message['event']['type'] ?? null,
                $message['event']['user']['name'] ?? null,
                $message['text'],
            ], $messages),
        );
    }

    public function test_membership_lines_stay_quiet(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $group = Chat::group($ann, [$john], 'Launch');
        Chat::send($group, $ann, 'Kick-off at 10');
        Chat::markRead($group, $john);

        Chat::addMember($group, $sara, by: $ann);
        $event = Message::query()->latest('id')->first();

        // Not unread, not the sidebar's preview, no notification
        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.unreadCount', 0)
            ->assertJsonPath('data.0.lastMessage.text', 'Kick-off at 10');
        $this->assertSame([], Chat::recipients($event)->all());

        // Left as it is
        $this->actingAs($ann)->patchJson("/api/chat/messages/{$event->id}", ['body' => 'edited'])->assertForbidden();
        $this->actingAs($ann)->deleteJson("/api/chat/messages/{$event->id}")->assertForbidden();
        $this->actingAs($john)->postJson("/api/chat/messages/{$event->id}/reactions", ['emoji' => '👍'])->assertForbidden();
        $this->actingAs($john)->postJson("/api/chat/conversations/{$group->id}/messages", ['body' => 'Hi', 'replyToId' => (string) $event->id])
            ->assertJsonValidationErrors('replyToId');
    }

    public function test_they_arrive_in_real_time_and_only_for_real_changes(): void
    {
        Event::fake([MessageSent::class]);
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $group = Chat::group($ann, [$john]);

        Chat::addMember($group, $john, by: $ann); // already in
        Chat::removeMember($group, $this->person('Eve'), by: $ann); // never in
        Event::assertNotDispatched(MessageSent::class);

        Chat::removeMember($group, $john);
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->event === 'left');
    }

    public function test_whoever_made_the_change_gets_the_line_too(): void
    {
        Event::fake([MessageSent::class]);
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $group = Chat::group($ann, [$john]);
        $headers = ['X-Socket-ID' => '1234.5678'];

        // A message is already on the sender's screen, so their browser is skipped; a membership line isn't
        $this->actingAs($ann)->postJson("/api/chat/conversations/{$group->id}/messages", ['body' => 'Hi'], $headers);
        $this->actingAs($ann)->postJson("/api/chat/conversations/{$group->id}/members", ['userId' => (string) $this->person('Sara')->id], $headers);
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->event === null && $event->socket === '1234.5678');
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->event === 'added' && $event->socket === null);
    }
}
