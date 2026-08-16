<?php

namespace Tests\Feature;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Общая таблица в живом экране турнира.
 *
 * При трёх группах плей-офф строится по ней, а не по местам в группах —
 * значит игрок должен видеть тот же ряд, по которому его посеют.
 */
class AmericanoOverallTableApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: array<int, User>} */
    private function tournament(int $groupsCount): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => $groupsCount,
            'max_participants' => $groupsCount * 4,
            'rounds_count' => 1,
        ]);

        $players = [];
        for ($i = 0; $i < $groupsCount * 4; $i++) {
            $players[] = User::factory()->create(['name' => 'И' . ($i + 1), 'rating' => 1500]);
        }

        // Очки убывают по индексу: общий ряд известен заранее.
        for ($g = 0; $g < $groupsCount; $g++) {
            $group = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $g),
            ]);
            for ($p = 0; $p < 4; $p++) {
                $index = $g * 4 + $p;
                $group->players()->attach($players[$index]->id, [
                    'total_points' => 100 - $index,
                    'rating_before' => 1500,
                    'rating_after' => null,
                ]);
            }
            $round = AmericanoRound::create([
                'tournament_group_id' => $group->id,
                'round_number' => 1,
                'status' => 'completed',
            ]);
            AmericanoMatch::create([
                'americano_round_id' => $round->id,
                'court_number' => $g + 1,
                'team1_player1_id' => $players[$g * 4]->id,
                'team1_player2_id' => $players[$g * 4 + 1]->id,
                'team2_player1_id' => $players[$g * 4 + 2]->id,
                'team2_player2_id' => $players[$g * 4 + 3]->id,
                'team1_score' => 21,
                'team2_score' => 15,
                'status' => 'completed',
            ]);
        }

        return [$tournament->fresh(), $players];
    }

    public function test_three_groups_get_an_overall_table(): void
    {
        [$t, $players] = $this->tournament(3);
        Sanctum::actingAs($players[0]);

        $overall = $this->getJson("/api/mobile/tournaments/{$t->id}/live")
            ->assertOk()
            ->json('overall');

        $this->assertCount(12, $overall, 'в общем ряду все игроки всех групп');
        $this->assertSame(1, $overall[0]['position']);
        $this->assertSame($players[0]->id, $overall[0]['id'], 'первым идёт игрок с наибольшими очками');
        $this->assertSame('Группа A', $overall[0]['group_name'], 'видно, из какой он группы');
    }

    public function test_overall_marks_where_each_place_leads(): void
    {
        [$t, $players] = $this->tournament(3);
        Sanctum::actingAs($players[0]);

        $overall = collect($this->getJson("/api/mobile/tournaments/{$t->id}/live")->json('overall'))
            ->keyBy('position');

        $this->assertSame('semifinal', $overall[1]['playoff_slot'], 'топ-4 ждут в полуфинале');
        $this->assertSame('semifinal', $overall[4]['playoff_slot']);
        $this->assertSame('quarterfinal', $overall[5]['playoff_slot'], 'с пятого — четвертьфинал');
        $this->assertSame('quarterfinal', $overall[12]['playoff_slot']);
    }

    /** Админский экран приложения ходит другим запросом — там таблица тоже нужна. */
    public function test_admin_matches_endpoint_returns_overall(): void
    {
        [$t, $players] = $this->tournament(3);

        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($t->club_id);
        Sanctum::actingAs($admin);

        $overall = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->json('overall');

        $this->assertCount(12, $overall);
        $this->assertSame(1, $overall[0]['position']);
        $this->assertSame('semifinal', $overall[0]['playoff_slot']);
        $this->assertSame('Группа A', $overall[0]['group_name']);
        $this->assertSame('quarterfinal', $overall[4]['playoff_slot'], 'пятое место — четвертьфинал');
    }

    /**
     * Полное равенство между группами: личной встречи там нет, а если совпали
     * очки и разница, то совпали и пропущенные — арифметика не оставляет
     * вариантов. Выше должен встать игрок с бо́льшим рейтингом, а не тот,
     * чья группа просто идёт первой в списке.
     */
    public function test_equal_players_from_different_groups_are_split_by_rating(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $t = Tournament::factory()->create([
            'club_id' => $club->id, 'type' => 'americano', 'status' => 'in_progress',
            'groups_count' => 3, 'max_participants' => 12, 'rounds_count' => 1,
        ]);

        // Слабый в группе A, сильный в группе C — при равных цифрах выше
        // должен оказаться сильный, хотя его группа идёт последней.
        $weakInA = User::factory()->create(['name' => 'Слабый', 'rating' => 1200]);
        $strongInC = User::factory()->create(['name' => 'Сильный', 'rating' => 1900]);

        $groups = [];
        foreach (['A', 'B', 'C'] as $i => $letter) {
            $groups[$letter] = TournamentGroup::create([
                'tournament_id' => $t->id, 'name' => 'Группа ' . $letter,
            ]);
        }

        foreach ([['A', $weakInA], ['C', $strongInC]] as [$letter, $user]) {
            $groups[$letter]->players()->attach($user->id, [
                'total_points' => 50, 'rating_before' => $user->rating, 'rating_after' => null,
            ]);
        }
        // Добиваем группы до четвёрок, чтобы таблица была настоящей.
        foreach ($groups as $letter => $group) {
            $need = 4 - $group->players()->count();
            for ($i = 0; $i < $need; $i++) {
                $filler = User::factory()->create(['rating' => 1500]);
                $group->players()->attach($filler->id, [
                    'total_points' => 10, 'rating_before' => 1500, 'rating_after' => null,
                ]);
            }
        }

        Sanctum::actingAs($weakInA);
        $overall = $this->getJson("/api/mobile/tournaments/{$t->id}/live")->assertOk()->json('overall');

        $this->assertSame($strongInC->id, $overall[0]['id'], 'выше тот, у кого рейтинг больше');
        $this->assertSame($weakInA->id, $overall[1]['id']);
    }

    public function test_two_groups_have_no_overall_table(): void
    {
        [$t, $players] = $this->tournament(2);
        Sanctum::actingAs($players[0]);

        $this->assertSame(
            [],
            $this->getJson("/api/mobile/tournaments/{$t->id}/live")->assertOk()->json('overall'),
            'при двух группах плей-офф идёт по местам в группах, общий ряд не нужен'
        );
    }
}
