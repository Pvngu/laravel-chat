<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Pvngu\Chat\Events\ReminderDue;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Jobs\SendReminder;
use Pvngu\Chat\Models\Participant;
use Pvngu\Chat\Models\Reminder;
use Pvngu\Chat\Tests\TestCase;

// In an app whose clock isn't UTC, the times the browser sends ("…Z") still mean the instant chosen: they're kept as UTC
class TimezoneTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();

        // As in an app with 'timezone' => 'America/Los_Angeles' (Laravel sets PHP's default from it at boot)
        $this->timezone = date_default_timezone_get();
        config(['app.timezone' => 'America/Los_Angeles']);
        date_default_timezone_set('America/Los_Angeles');
        $this->travelTo(Carbon::parse('2026-03-10T16:00:00Z'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    public function test_a_reminder_comes_at_the_time_chosen(): void
    {
        Queue::fake();
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $message = Chat::send($this->directBetween($ann, $john), $john, 'Call me back');
        $at = '2026-03-10T17:00:00Z';

        $this->actingAs($ann)->putJson("/api/chat/messages/{$message->id}/reminder", ['remindAt' => $at])
            ->assertCreated()
            ->assertJsonPath('data.remindAt', $at);
        $this->assertSame($at, Reminder::query()->sole()->remind_at->toIso8601ZuluString());
        Queue::assertPushed(SendReminder::class, fn (SendReminder $job) => $job->delay->equalTo(Carbon::parse($at)));
        $this->actingAs($ann)->getJson('/api/chat/reminders')->assertJsonPath('data.0.remindAt', $at);

        Event::fake([ReminderDue::class]);
        $this->travelTo(Carbon::parse('2026-03-10T16:59:00Z'));
        $this->assertSame(0, Chat::sendDueReminders());
        $this->travelTo(Carbon::parse('2026-03-10T17:01:00Z'));
        $this->assertSame(1, Chat::sendDueReminders());
        Event::assertDispatched(ReminderDue::class);
    }

    public function test_a_mute_ends_at_the_time_chosen(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $direct = $this->directBetween($ann, $john);
        $until = '2026-03-10T17:00:00Z';

        $this->actingAs($ann)->postJson("/api/chat/conversations/{$direct->id}/notifications", ['level' => 'all', 'mutedUntil' => $until])
            ->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonPath('data.0.mutedUntil', $until);
        Chat::send($direct, $john, 'Still there?');
        $participant = fn () => Participant::query()->where('conversation_id', $direct->id)->where('user_id', $ann->id)->sole();

        $this->travelTo(Carbon::parse('2026-03-10T16:59:00Z'));
        $this->assertTrue($participant()->isMuted());
        $this->assertSame(['chats' => 0, 'mentions' => 0], Chat::unread($ann));
        $this->assertCount(0, Chat::recipients($direct->messages()->latest('id')->first()));

        $this->travelTo(Carbon::parse('2026-03-10T17:01:00Z'));
        $this->assertFalse($participant()->isMuted());
        $this->assertSame(['chats' => 1, 'mentions' => 0], Chat::unread($ann));
        $this->assertCount(1, Chat::recipients($direct->messages()->latest('id')->first()));
    }

    // The night the clocks go back, 1:00 to 2:00 comes twice: the second 1:30 is still the moment chosen
    public function test_a_reminder_in_the_hour_that_repeats_comes_at_the_time_chosen(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-11-01T07:30:00Z')); // 00:30 PDT
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $message = Chat::send($this->directBetween($ann, $john), $john, 'Call me back');
        $at = '2026-11-01T09:30:00Z'; // 01:30 PST, the second time round

        $this->actingAs($ann)->putJson("/api/chat/messages/{$message->id}/reminder", ['remindAt' => $at])
            ->assertCreated()
            ->assertJsonPath('data.remindAt', $at);
        $reminder = Reminder::query()->sole();
        $this->assertSame($at, $reminder->remind_at->toIso8601ZuluString());
        $this->assertTrue($reminder->remind_at->isFuture());
        $this->assertSame('2026-11-01 09:30:00', $reminder->getRawOriginal('remind_at'));
        $this->actingAs($ann)->getJson('/api/chat/reminders')->assertJsonPath('data.0.remindAt', $at);
        $this->assertSame(0, Chat::sendDueReminders());
    }

    public function test_a_mute_ending_in_the_hour_that_repeats_ends_at_the_time_chosen(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01T00:30:00Z')); // 17:30 PDT the evening before
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $direct = $this->directBetween($ann, $john);
        $until = '2026-11-01T09:30:00Z'; // 01:30 PST

        $this->actingAs($ann)->postJson("/api/chat/conversations/{$direct->id}/notifications", ['level' => 'all', 'mutedUntil' => $until])
            ->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/conversations')->assertJsonPath('data.0.mutedUntil', $until);
        $participant = fn () => Participant::query()->where('conversation_id', $direct->id)->where('user_id', $ann->id)->sole();
        $this->assertSame($until, $participant()->muted_until->toIso8601ZuluString());

        // (Not checked within the repeated hour itself: Carbon's test clock reads it as the first time round)
        $this->travelTo(Carbon::parse('2026-11-01T07:59:00Z'));
        $this->assertTrue($participant()->isMuted());
        $this->travelTo(Carbon::parse('2026-11-01T10:00:00Z'));
        $this->assertFalse($participant()->isMuted());
    }
}
