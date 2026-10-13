<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Pvngu\Chat\Events\PresenceChanged;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Mention;
use Pvngu\Chat\Tests\TestCase;

class PresenceTest extends TestCase
{
    public function test_heartbeats_say_whether_someone_is_online_or_away(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $id = (string) $john->id;
        $statuses = fn () => $this->actingAs($ann)->getJson("/api/chat/presence?ids=$id,999")->json('data.statuses');

        $this->assertSame([], $statuses());

        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'away'], ['X-Socket-ID' => '1.1'])->assertNoContent();
        $this->assertSame([$id => 'away'], $statuses());

        // Online in another tab makes them online; away in all of them makes them away again
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'online'], ['X-Socket-ID' => '2.2']);
        $this->assertSame([$id => 'online'], $statuses());
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'away'], ['X-Socket-ID' => '2.2']);
        $this->assertSame([$id => 'away'], $statuses());

        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'busy'])->assertJsonValidationErrors('status');
    }

    public function test_a_page_reporting_before_its_socket_connects_doesnt_keep_someone_online(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $id = (string) $john->id;

        // The page loads (no socket yet), then connects and goes to the background
        $this->actingAs($john)->getJson('/api/chat/conversations');
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'away'], ['X-Socket-ID' => '1.1']);

        $this->actingAs($ann)->getJson("/api/chat/presence?ids=$id")->assertJsonPath("data.statuses.$id", 'away');
    }

    public function test_others_hear_when_someone_goes_away_or_comes_back(): void
    {
        Event::fake([PresenceChanged::class]);
        $john = $this->person('John Doe');
        $channel = Chat::presenceChannel($john);

        // Coming online is joining the presence channel, so only changes after that are sent
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'online']);
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'online']);
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'away']);
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'online']);

        $dispatched = Event::dispatched(PresenceChanged::class)->map(fn (array $args) => $args[0]);
        $this->assertSame(['away', 'online'], $dispatched->pluck('status')->all());
        $this->assertSame("presence-$channel", $dispatched->first()->broadcastOn()[0]->name);
        $this->assertSame(['userId' => (string) $john->id, 'status' => 'away'], $dispatched->first()->broadcastWith());
    }

    public function test_each_tenant_has_its_own_presence(): void
    {
        $ann = $this->person('Ann Smith');
        $olga = $this->person('Olga', 'globex');
        $this->actingAs($olga)->postJson('/api/chat/presence', ['status' => 'online']);

        $this->assertMatchesRegularExpression('/^chat\.presence\.[0-9a-f]{16}$/', Chat::presenceChannel($ann));
        $this->assertNotSame(Chat::presenceChannel($ann), Chat::presenceChannel($olga));
        $this->actingAs($ann)->getJson("/api/chat/presence?ids={$olga->id}")
            ->assertJsonPath('data.channel', Chat::presenceChannel($ann))
            ->assertJsonPath('data.statuses', []);

        // Only the tenant's people may join its channel, and they're shown by name
        $authorize = app(BroadcastManager::class)->driver()->getChannels()['chat.presence.{key}'];
        $key = substr(Chat::presenceChannel($ann), strlen('chat.presence.'));
        $this->assertSame(['id' => (string) $ann->id, 'name' => 'Ann Smith'], $authorize($ann, $key));
        $this->assertFalse($authorize($olga, $key));
    }

    public function test_here_reaches_people_who_are_online_not_away(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $group = Chat::group($ann, [$john, $sara]);
        $this->actingAs($john)->postJson('/api/chat/presence', ['status' => 'online']);
        $this->actingAs($sara)->postJson('/api/chat/presence', ['status' => 'away']);

        Chat::send($group, $ann, '<!here> standup');
        $this->assertSame([(string) $john->id], Mention::query()->pluck('user_id')->map(fn ($id) => (string) $id)->all());
    }
}
