<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Reports\UsersReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Выборка игроков с выгрузкой в Excel: фильтры по уровням и по участию
 * в турнирах. Раздел только для супер-админа.
 */
class UsersExportTest extends TestCase
{
    use RefreshDatabase;

    private function service(): UsersReportService
    {
        return app(UsersReportService::class);
    }

    private function makeClub(): Club
    {
        return Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
    }

    private function makePlayer(string $name, float $level): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'player',
            'level' => $level,
            'rating' => (int) ($level * 1000 + 125),
        ]);
    }

    private function makeTournament(Club $club, string $status = 'completed'): Tournament
    {
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => $status, 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
    }

    // ===== Фильтр по участию в турнирах =====

    public function test_played_filter_keeps_only_players_with_tournaments(): void
    {
        $club = $this->makeClub();
        $played = $this->makePlayer('Игравший', 3.0);
        $this->makePlayer('Новичок', 3.0);

        $this->makeTournament($club)->participants()->attach($played->id, ['status' => 'approved']);

        $names = $this->service()->query(['played' => 'yes'])->pluck('name')->all();

        $this->assertSame(['Игравший'], $names);
    }

    public function test_not_played_filter_keeps_only_players_without_tournaments(): void
    {
        $club = $this->makeClub();
        $played = $this->makePlayer('Игравший', 3.0);
        $this->makePlayer('Новичок', 3.0);

        $this->makeTournament($club)->participants()->attach($played->id, ['status' => 'approved']);

        $names = $this->service()->query(['played' => 'no'])->pluck('name')->all();

        $this->assertSame(['Новичок'], $names);
    }

    public function test_team_tournament_counts_as_played(): void
    {
        // В командных турнирах игрока нет в tournament_participants — он
        // записан парой. Без этого половина игравших выпала бы из выборки.
        $club = $this->makeClub();
        $first = $this->makePlayer('Первый', 3.0);
        $second = $this->makePlayer('Второй', 3.0);
        $tournament = $this->makeTournament($club);

        TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $first->id,
            'player2_id' => $second->id,
            'name' => 'Пара',
        ]);

        $names = $this->service()->query(['played' => 'yes'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Второй', 'Первый'], $names);
    }

    public function test_unfinished_tournament_does_not_count_as_played(): void
    {
        // Запись на турнир — ещё не игра: турнир могут отменить или он идёт.
        $club = $this->makeClub();
        $signedUp = $this->makePlayer('Записался', 3.0);
        $this->makeTournament($club, 'open')
            ->participants()->attach($signedUp->id, ['status' => 'approved']);

        $this->assertSame([], $this->service()->query(['played' => 'yes'])->pluck('name')->all());
    }

    // ===== Фильтр по уровням =====

    public function test_levels_filter_takes_whole_quarter_ranges(): void
    {
        $this->makePlayer('Второй уровень', 2.5);
        $this->makePlayer('Третий уровень', 3.0);
        $this->makePlayer('Четвёртый уровень', 4.25);

        $names = $this->service()->query(['levels' => [2, 4]])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Второй уровень', 'Четвёртый уровень'], $names);
    }

    public function test_filters_combine(): void
    {
        $club = $this->makeClub();
        $match = $this->makePlayer('Подходит', 3.5);
        $wrongLevel = $this->makePlayer('Не тот уровень', 5.0);
        $this->makePlayer('Не играл', 3.25);

        $tournament = $this->makeTournament($club);
        $tournament->participants()->attach($match->id, ['status' => 'approved']);
        $tournament->participants()->attach($wrongLevel->id, ['status' => 'approved']);

        $names = $this->service()->query(['levels' => [3], 'played' => 'yes'])->pluck('name')->all();

        $this->assertSame(['Подходит'], $names);
    }

    // ===== Лист выгрузки =====

    public function test_sheet_has_tournament_count_and_phone(): void
    {
        $club = $this->makeClub();
        $player = $this->makePlayer('Игрок Тестовый', 3.0);
        $player->update(['phone' => '77771234567']);
        $this->makeTournament($club)->participants()->attach($player->id, ['status' => 'approved']);

        $sheet = $this->service()->sheet(['played' => 'yes']);

        $this->assertCount(1, $sheet->rows);
        $row = $sheet->rows[0];
        $this->assertContains('Игрок Тестовый', $row);
        $this->assertContains('77771234567', $row);
        $this->assertContains(1, $row, 'в строке должно быть число сыгранных турниров');
    }

    // ===== Доступ =====

    public function test_super_admin_downloads_file(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($superAdmin)
            ->get(route('club.users.export', ['played' => 'yes']));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheet',
            (string) $response->headers->get('content-type')
        );
    }

    public function test_super_admin_sees_panel_with_count(): void
    {
        $club = $this->makeClub();
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $played = $this->makePlayer('Игравший', 3.0);
        $this->makePlayer('Новичок', 3.0);
        $this->makeTournament($club)->participants()->attach($played->id, ['status' => 'approved']);

        $this->actingAs($superAdmin)
            ->get(route('club.users.index', ['played' => 'yes']))
            ->assertOk()
            ->assertSee('Выгрузка в Excel')
            ->assertSee('подходит');
    }

    public function test_club_admin_does_not_see_panel(): void
    {
        $club = $this->makeClub();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $this->actingAs($admin)
            ->get(route('club.users.index'))
            ->assertOk()
            ->assertDontSee('Выгрузка в Excel');
    }

    public function test_club_admin_cannot_export(): void
    {
        $club = $this->makeClub();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $this->actingAs($admin)->get(route('club.users.export'))->assertForbidden();
    }
}
