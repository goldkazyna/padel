<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ModerationMinutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_minutes_take_priority_over_hours_for_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'X', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 48, 'moderation_minutes' => 5,
        ]);
        $this->assertSame(5, $t->moderationWindowMinutes());
        $deadline = $t->moderationDeadline();
        // ~5 минут от сейчас (с запасом на выполнение)
        $this->assertTrue($deadline->lessThanOrEqualTo(now()->addMinutes(6)));
        $this->assertTrue($deadline->greaterThan(now()->addMinutes(4)));
    }

    public function test_command_processes_minutes_only_tournament(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'X', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 0,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_minutes' => 2, // только минуты, hours null
        ]);
        $late = User::factory()->create();
        $t->participants()->attach($late->id, [
            'status' => 'pending', 'moderation_deadline' => now()->subMinute(),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $row = $t->participants()->where('user_id', $late->id)->first();
        $this->assertSame('cancelled', $row->pivot->status); // нет листа ожидания → удаление
    }
}
