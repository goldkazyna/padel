<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TournamentRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function makeT(int $hoursUntil): Tournament
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addHours($hoursUntil),
            'registration_deadline' => now()->addHour(),
        ]);
    }

    public function test_sends_1d_reminder_within_24h(): void
    {
        $t = $this->makeT(23);
        $u = User::factory()->create(['notify_tournament_reminders' => true]);
        $t->participants()->attach($u->id, ['status' => 'registered']);

        $this->artisan('tournaments:send-reminders')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $u->id, 'type' => 'tournament_reminder',
        ]);
        $row = $t->participants()->where('user_id', $u->id)->first();
        $this->assertNotNull($row->pivot->reminded_1d_at);
        $this->assertNull($row->pivot->reminded_2h_at); // ещё не 2 часа
    }

    public function test_sends_both_when_within_2h(): void
    {
        $t = $this->makeT(1);
        $u = User::factory()->create(['notify_tournament_reminders' => true]);
        $t->participants()->attach($u->id, ['status' => 'registered']);

        $this->artisan('tournaments:send-reminders')->assertExitCode(0);

        $row = $t->participants()->where('user_id', $u->id)->first();
        $this->assertNotNull($row->pivot->reminded_1d_at);
        $this->assertNotNull($row->pivot->reminded_2h_at);
        $this->assertSame(2, Notification::where('user_id', $u->id)->where('type', 'tournament_reminder')->count());
    }

    public function test_not_sent_when_setting_off(): void
    {
        $t = $this->makeT(5);
        $u = User::factory()->create(['notify_tournament_reminders' => false]);
        $t->participants()->attach($u->id, ['status' => 'registered']);

        $this->artisan('tournaments:send-reminders')->assertExitCode(0);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }

    public function test_not_sent_for_pending(): void
    {
        $t = $this->makeT(5);
        $u = User::factory()->create(['notify_tournament_reminders' => true]);
        $t->participants()->attach($u->id, ['status' => 'pending']);

        $this->artisan('tournaments:send-reminders')->assertExitCode(0);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }

    public function test_no_duplicate_on_second_run(): void
    {
        $t = $this->makeT(5);
        $u = User::factory()->create(['notify_tournament_reminders' => true]);
        $t->participants()->attach($u->id, ['status' => 'registered']);

        $this->artisan('tournaments:send-reminders')->assertExitCode(0);
        $this->artisan('tournaments:send-reminders')->assertExitCode(0);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('type', 'tournament_reminder')->count());
    }

    public function test_team_tournament_skipped(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Team', 'type' => 'team',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addHours(5), 'registration_deadline' => now()->addHour(),
        ]);
        $u = User::factory()->create(['notify_tournament_reminders' => true]);
        $t->participants()->attach($u->id, ['status' => 'registered']);

        $this->artisan('tournaments:send-reminders')->assertExitCode(0);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }
}
