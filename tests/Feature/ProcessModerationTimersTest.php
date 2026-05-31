<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessModerationTimersTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 2, 'waitlist_size' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 48,
        ]);
    }

    public function test_expired_demoted_to_waitlist_and_front_promoted(): void
    {
        $t = $this->tournament(); // waitlist_size=4
        $late = User::factory()->create();
        $waiter = User::factory()->create();
        $t->participants()->attach($late->id, [
            'status' => 'pending', 'moderation_deadline' => now()->subMinute(),
        ]);
        $t->participants()->attach($waiter->id, ['status' => 'waiting', 'created_at' => now()->subHour()]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $lateRow = $t->participants()->where('user_id', $late->id)->first();
        $this->assertSame('waiting', $lateRow->pivot->status);
        $this->assertNull($lateRow->pivot->moderation_deadline);

        $waiterRow = $t->participants()->where('user_id', $waiter->id)->first();
        $this->assertSame('pending', $waiterRow->pivot->status);
        $this->assertNotNull($waiterRow->pivot->moderation_deadline);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $late->id, 'type' => 'tournament_moderation_demoted',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $waiter->id, 'type' => 'tournament_moderation_pending',
        ]);
    }

    public function test_expired_with_empty_waitlist_goes_to_waitlist_no_promotion(): void
    {
        $t = $this->tournament(); // waitlist_size=4, no waiters
        $late = User::factory()->create();
        $t->participants()->attach($late->id, [
            'status' => 'pending', 'moderation_deadline' => now()->subMinute(),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $lateRow = $t->participants()->where('user_id', $late->id)->first();
        $this->assertSame('waiting', $lateRow->pivot->status);
        // никого не продвинули обратно в pending
        $this->assertSame(0, $t->participants()->wherePivot('status', 'pending')->count());
    }

    public function test_expired_without_waitlist_is_cancelled(): void
    {
        $club = \App\Models\Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 0,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 48,
        ]);
        $late = User::factory()->create();
        $t->participants()->attach($late->id, [
            'status' => 'pending', 'moderation_deadline' => now()->subMinute(),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $lateRow = $t->participants()->where('user_id', $late->id)->first();
        $this->assertSame('cancelled', $lateRow->pivot->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $late->id, 'type' => 'tournament_moderation_expired',
        ]);
    }

    public function test_future_deadline_untouched(): void
    {
        $t = $this->tournament();
        $u = User::factory()->create();
        $t->participants()->attach($u->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHours(10),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $row = $t->participants()->where('user_id', $u->id)->first();
        $this->assertSame('pending', $row->pivot->status);
    }

    public function test_reminder_sent_once_when_near_deadline(): void
    {
        $t = $this->tournament(); // окно 48ч → 20% = 9.6ч
        $u = User::factory()->create();
        $t->participants()->attach($u->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHour(),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $u->id, 'type' => 'tournament_moderation_reminder',
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);
        $this->assertSame(1, \App\Models\Notification::where('user_id', $u->id)
            ->where('type', 'tournament_moderation_reminder')->count());
    }
}
