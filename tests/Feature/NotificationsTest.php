<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Carbon;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

class NotificationsTest extends TestCase
{
    public function test_each_person_chooses_how_they_hear_about_a_chat(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $ann = $this->person('Ann Smith');
        $channel = Chat::channel($ann, 'general');
        $url = "/api/chat/channels/{$channel->id}/notifications";

        $this->actingAs($ann)->postJson($url, ['level' => 'mentions'])->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/channels')
            ->assertJsonPath('data.0.notifications', 'mentions')
            ->assertJsonPath('data.0.mutedUntil', null);

        // A temporary mute shows until it ends
        $this->actingAs($ann)->postJson($url, ['level' => 'all', 'mutedUntil' => '2026-03-10T10:00:00Z'])->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/channels')->assertJsonPath('data.0.mutedUntil', '2026-03-10T10:00:00Z');
        Carbon::setTestNow('2026-03-10 10:01:00');
        $this->actingAs($ann)->getJson('/api/chat/channels')->assertJsonPath('data.0.mutedUntil', null);

        $this->actingAs($ann)->postJson($url, ['level' => 'loud'])->assertJsonValidationErrors('level');
        $this->actingAs($ann)->postJson($url, ['level' => 'all', 'mutedUntil' => '2026-03-09T00:00:00Z'])
            ->assertJsonValidationErrors('mutedUntil');
        $this->actingAs($this->person('Eve'))->postJson($url, ['level' => 'none'])->assertNotFound();
    }

    public function test_recipients_follow_everyones_settings(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $ann = $this->person('Ann Smith');
        $all = $this->person('All Messages');
        $mentions = $this->person('Just Mentions');
        $mentioned = $this->person('Mentioned Mentions');
        $none = $this->person('Nothing');
        $paused = $this->person('Paused');
        $group = Chat::group($ann, [$all, $mentions, $mentioned, $none, $paused]);

        Chat::setNotifications($group, $mentions, 'mentions');
        Chat::setNotifications($group, $mentioned, 'mentions');
        Chat::setNotifications($group, $none, 'none');
        Chat::setNotifications($group, $paused, 'all', Carbon::parse('2026-03-10 10:00:00'));

        $message = Chat::send($group, $ann, "Hi all, especially <@{$mentioned->id}> and <@{$paused->id}>");
        $this->assertSame(['All Messages', 'Mentioned Mentions'], Chat::recipients($message)->pluck('name')->all());

        // The pause ends
        Carbon::setTestNow('2026-03-10 10:01:00');
        $this->assertSame(['All Messages', 'Mentioned Mentions', 'Paused'], Chat::recipients($message)->pluck('name')->all());
    }
}
