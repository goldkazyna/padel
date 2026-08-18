<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отметка «пришёл» в списке участников турнира.
 */
class TournamentAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'open',
        ]);
    }

    private function participant(array $over = []): User
    {
        $user = User::factory()->create($over);
        $this->tournament->participants()->attach($user->id, ['status' => 'registered']);

        return $user;
    }

    public function test_attendance_is_saved_and_survives_reload(): void
    {
        $player = $this->participant();
        $url = route('club.tournaments.participants.attendance', [$this->tournament, $player->id]);

        $this->actingAs($this->admin)
            ->postJson($url, ['attended' => true])
            ->assertOk()
            ->assertJsonPath('attended', true);

        $this->assertNotNull(
            $this->tournament->participants()->where('user_id', $player->id)->first()->pivot->attended_at
        );

        // Перезагрузка страницы: галочка отмечена.
        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $this->tournament))
            ->assertOk()
            ->assertSee('attend-check', false)
            ->assertSee('checked', false);
    }

    public function test_attendance_can_be_taken_back(): void
    {
        $player = $this->participant();
        $url = route('club.tournaments.participants.attendance', [$this->tournament, $player->id]);

        $this->actingAs($this->admin)->postJson($url, ['attended' => true])->assertOk();
        $this->actingAs($this->admin)->postJson($url, ['attended' => false])->assertOk();

        $this->assertNull(
            $this->tournament->participants()->where('user_id', $player->id)->first()->pivot->attended_at
        );
    }

    public function test_unknown_participant_is_refused(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->admin)
            ->postJson(route('club.tournaments.participants.attendance', [$this->tournament, $stranger->id]), [
                'attended' => true,
            ])
            ->assertNotFound();
    }

    /** Чужой клуб отметки не ставит. */
    public function test_foreign_club_admin_is_refused(): void
    {
        $player = $this->participant();

        $other = Club::create(['name' => 'Чужой', 'address' => 'Б']);
        $stranger = User::factory()->create(['role' => 'club_admin']);
        $stranger->adminClubs()->attach($other->id);

        $this->actingAs($stranger)
            ->postJson(route('club.tournaments.participants.attendance', [$this->tournament, $player->id]), [
                'attended' => true,
            ])
            ->assertForbidden();
    }

    public function test_avatar_and_verified_badge_are_shown(): void
    {
        $this->participant([
            'name' => 'Денис Дудников',
            'avatar' => 'https://padel-p.kz/avatars/denis.jpg',
            'level_verified' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $this->tournament))
            ->assertOk()
            ->assertSee('https://padel-p.kz/avatars/denis.jpg', false)
            ->assertSee('bi-patch-check-fill', false);
    }

    /** Без аватара показываем инициалы, а не битую картинку. */
    public function test_initials_are_shown_without_an_avatar(): void
    {
        $this->participant([
            'first_name' => 'Денис',
            'last_name' => 'Дудников',
            'avatar' => null,
            'level_verified' => false,
        ]);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $this->tournament))
            ->assertOk()
            ->assertSee('ДД')
            ->assertDontSee('bi-patch-check-fill', false);
    }
}
