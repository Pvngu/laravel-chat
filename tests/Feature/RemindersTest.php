<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Pvngu\Chat\ChatManager;
use Pvngu\Chat\Events\ReminderDue;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Jobs\SendReminder;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Models\Reminder;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class RemindersTest extends TestCase
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
        $this->message = Chat::send($this->conversation, $this->john, 'Call me back');
    }

    private function reminderUrl(?Message $message = null): string
    {
        return '/api/chat/messages/'.($message ?? $this->message)->id.'/reminder';
    }

    public function test_you_set_move_list_and_remove_a_reminder()
    {
        Queue::fake();
        $at = now()->addHour()->startOfMinute();

        $this->actingAs($this->ann)->putJson($this->reminderUrl(), ['remindAt' => $at->toIso8601String()])
            ->assertCreated()
            ->assertExactJson(['data' => [
                'messageId' => (string) $this->message->id,
                'remindAt' => $at->toIso8601ZuluString(),
                'chat' => ['kind' => 'conversation', 'id' => $this->conversation->id],
            ]]);
        Queue::assertPushed(SendReminder::class, fn (SendReminder $job) => $job->delay->equalTo($at));

        // A new time replaces it: one reminder per message
        $later = $at->copy()->addDay();
        $this->actingAs($this->ann)->putJson($this->reminderUrl(), ['remindAt' => $later->toIso8601String()])->assertOk();
        $this->assertSame(1, Reminder::query()->count());

        // Yours only, the ones still to come
        Chat::remind($this->john, $this->message, $at);
        $this->actingAs($this->ann)->getJson('/api/chat/reminders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.remindAt', $later->toIso8601ZuluString());

        $this->actingAs($this->ann)->deleteJson($this->reminderUrl())->assertNoContent();
        $this->actingAs($this->ann)->getJson('/api/chat/reminders')->assertJsonCount(0, 'data');
    }

    public function test_it_needs_a_time_to_come_and_a_message_you_can_see()
    {
        $this->actingAs($this->ann)->putJson($this->reminderUrl(), ['remindAt' => now()->subMinute()->toIso8601String()])
            ->assertJsonValidationErrors(['remindAt' => 'Pick a time that hasn’t passed yet.']);
        $this->actingAs($this->ann)->putJson($this->reminderUrl(), ['remindAt' => now()->addYears(2)->toIso8601String()])
            ->assertJsonValidationErrors('remindAt');
        $this->actingAs($this->person('Sara Lane'))->putJson($this->reminderUrl(), ['remindAt' => now()->addHour()->toIso8601String()])
            ->assertNotFound();
    }

    public function test_it_fires_once_when_its_time_comes()
    {
        Event::fake([ReminderDue::class]);
        $reminder = Chat::remind($this->ann, $this->message, now()->addMinutes(30));

        // The job ran early, or the reminder was moved later: not yet
        (new SendReminder($reminder->id))->handle(app(ChatManager::class));
        Event::assertNotDispatched(ReminderDue::class);

        $this->travel(30)->minutes();
        (new SendReminder($reminder->id))->handle(app(ChatManager::class));
        Event::assertDispatched(ReminderDue::class, fn (ReminderDue $event) => $event->reminder->is($reminder)
            && $event->reminder->user->is($this->ann)
            && $event->reminder->message->is($this->message));
        $this->assertNotNull($reminder->fresh()->sent_at);

        // Sent once, whoever gets to it next
        $this->assertSame(0, Chat::sendDueReminders());
        Event::assertDispatchedTimes(ReminderDue::class, 1);
        $this->actingAs($this->ann)->getJson('/api/chat/reminders')->assertJsonCount(0, 'data');
    }

    public function test_the_command_catches_up_and_drops_reminders_that_no_longer_apply()
    {
        Event::fake([ReminderDue::class]);
        $group = Chat::group($this->john, [$this->ann], 'Launch plan');
        $deleted = Chat::send($group, $this->john, 'Gone soon');
        $left = Chat::send($group, $this->john, 'Before you left');

        Chat::remind($this->ann, $this->message, now()->addMinute());
        Chat::remind($this->ann, $deleted, now()->addMinute());
        Chat::remind($this->ann, $left, now()->addMinute());
        Chat::remind($this->john, $this->message, now()->addHour());
        Chat::delete($deleted);
        Chat::removeMember($group, $this->ann);

        $this->travel(2)->minutes();
        $this->artisan('chat:send-reminders')->expectsOutputToContain('Sent 1 reminder.')->assertSuccessful();

        Event::assertDispatchedTimes(ReminderDue::class, 1);
        // The deleted message's and the left chat's are gone; John's is still to come
        $this->assertSame(2, Reminder::query()->count());
    }
}
