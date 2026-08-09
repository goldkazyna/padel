<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Кнопка «Выгрузить картинкой» в админке показывается там, где пришла
 * таблица лидеров. Значит таблица должна приходить у КАЖДОГО формата —
 * иначе у части турниров выгрузки просто нет.
 */
class AdminLeaderboardAllFormatsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tournament,1:User,2:array<int,User>} */
    private function makeTournament(string $type, int $players, array $extra = []): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $tournament = Tournament::factory()->create(array_merge([
            'club_id' => $club->id,
            'type' => $type,
            'status' => 'open',
            'max_participants' => $players,
            'is_rated' => true,
            'start_date' => now()->addDay(),
        ], $extra));

        $users = [];
        for ($i = 1; $i <= $players; $i++) {
            $user = User::factory()->create([
                'name' => "P{$i}",
                'rating' => 2000 - $i * 50,
                // Каждый второй с подтверждённым уровнем: проверяем, что
                // галочка доезжает до таблицы, а не гасится по дороге.
                'level_verified' => $i % 2 === 0,
                'avatar' => "https://cdn.example.com/p{$i}.jpg",
            ]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $users[] = $user;
        }

        return [$tournament->fresh(), $admin, $users];
    }

    private function assertLeaderboardPresent(Tournament $tournament, User $admin, string $format): void
    {
        Sanctum::actingAs($admin);
        $res = $this->getJson("/api/mobile/admin/tournaments/{$tournament->id}/matches")->assertOk();

        $groups = $res->json('groups');
        $this->assertNotEmpty($groups, "{$format}: групп в ответе нет");

        $rows = collect($groups)->flatMap(fn ($g) => $g['leaderboard'] ?? [])->all();
        $this->assertNotEmpty($rows, "{$format}: таблица пустая — кнопки выгрузки не будет");

        // Карточка выгрузки рисует имя, место и очки: без них картинка пустая.
        $this->assertArrayHasKey('position', $rows[0]);
        $this->assertNotEmpty($rows[0]['name'], "{$format}: у строки нет имени");
        $this->assertArrayHasKey('total_points', $rows[0]);

        // Правило для всех форматов: строка несёт аватар и синюю галочку.
        // Приложение читает ровно эти ключи — расхождение имён молча
        // оставляет таблицу без аватарок.
        foreach ($rows as $i => $row) {
            $this->assertArrayHasKey('avatar', $row, "{$format}: строка {$i} без ключа avatar");
            $this->assertArrayHasKey('verified', $row, "{$format}: строка {$i} без ключа verified");

            // Парные форматы дают ещё и обоих игроков — у них те же ключи.
            foreach ($row['players'] ?? [] as $j => $player) {
                $this->assertArrayHasKey('avatar', $player, "{$format}: игрок {$j} пары без avatar");
                $this->assertArrayHasKey('verified', $player, "{$format}: игрок {$j} пары без verified");
            }
        }
    }

    public function test_king_of_court_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('king_of_court', 8);
        app(\App\Services\KingOfCourtService::class)->startTournament($t);

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Король корта');
    }

    public function test_americano_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('americano', 8, ['groups_count' => 1]);
        app(\App\Services\AmericanoService::class)->startTournament($t);

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Американо');
    }

    public function test_mexicano_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('mexicano', 8, ['rounds_count' => 3]);
        app(\App\Services\MexicanoService::class)->startTournament($t);

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Мексикано');
    }

    public function test_round_robin_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('round_robin', 8);
        app(\App\Services\RoundRobinService::class)->startTournament($t);

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Round Robin');
    }

    public function test_just_padel_it_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('just_padel_it', 8, ['courts_count' => 2]);
        app(\App\Services\JustPadelItService::class)->startTournament($t);

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Just Padel It');
    }

    public function test_americano_flex_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('americano_flex', 8, ['courts_count' => 2]);
        app(\App\Services\AmericanoFlexService::class)->startTournament($t);

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Americano Flex');
    }

    public function test_bali_koc_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('bali_koc', 8, ['is_paired' => true]);
        $service = app(\App\Services\BaliKocService::class);
        $pairs = [];
        for ($i = 0; $i < 8; $i += 2) {
            $pairs[] = [$users[$i]->id, $users[$i + 1]->id];
        }
        $service->createPairs($t, $pairs);
        $service->startTournament($t->fresh());

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Bali Format');
    }

    public function test_escalera_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('escalera', 8, [
            'courts_count' => 2,
            'escalera_standings_mode' => 'raw_points',
        ]);
        app(\App\Services\EscaleraService::class)->startTournament($t);

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Эскалера');
    }

    public function test_verified_flag_reaches_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('king_of_court', 8);
        app(\App\Services\KingOfCourtService::class)->startTournament($t);

        Sanctum::actingAs($admin);
        $rows = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->json('groups.0.leaderboard');

        $verified = array_filter($rows, fn ($r) => $r['verified'] === true);
        $this->assertNotEmpty($verified, 'галочка не доезжает до таблицы');

        $withAvatar = array_filter($rows, fn ($r) => !empty($r['avatar']));
        $this->assertNotEmpty($withAvatar, 'аватар не доезжает до таблицы');
    }

    public function test_team_has_leaderboard(): void
    {
        [$t, $admin, $users] = $this->makeTournament('team', 8, [
            'groups_count' => 1,
            'has_playoff' => false,
        ]);

        // Командный формат играют парами — собираем их из участников.
        for ($i = 0; $i < 8; $i += 2) {
            \App\Models\TournamentTeam::create([
                'tournament_id' => $t->id,
                'player1_id' => $users[$i]->id,
                'player2_id' => $users[$i + 1]->id,
                'status' => 'approved',
            ]);
        }
        app(\App\Services\TeamTournamentService::class)->startTournament($t->fresh());

        $this->assertLeaderboardPresent($t->fresh(), $admin, 'Групповой + Плей-офф');
    }
}
