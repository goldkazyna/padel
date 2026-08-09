<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\EscaleraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Экраны игрока для «Эскалеры»: live-турнир, место в истории,
 * матчи и статистика в профиле.
 */
class EscaleraPlayerScreensTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EscaleraService
    {
        return app(EscaleraService::class);
    }

    /**
     * Стартовавший турнир на заданное число кортов.
     * Игроки названы P1..PN по убыванию рейтинга, поэтому P1..P4 сидят на
     * первом корте, P5..P8 — на втором и так далее.
     *
     * @return array{0:Tournament,1:array<string,User>}
     */
    private function startedTournament(int $courts = 3, string $mode = 'points'): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'escalera',
            'status' => 'open',
            'courts_count' => $courts,
            'max_participants' => $courts * 4,
            'escalera_standings_mode' => $mode,
            'start_date' => now()->addDay(),
            'is_rated' => true,
        ]);

        $users = [];
        $total = $courts * 4;
        for ($i = 1; $i <= $total; $i++) {
            $user = User::factory()->create([
                'name' => "P{$i}",
                'rating' => 3000 - $i * 100,
            ]);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $users["P{$i}"] = $user;
        }

        $this->service()->startTournament($t);

        return [$t->fresh(), $users];
    }

    /** Внести счёт во все матчи текущего раунда: 12:0, 11:1, 10:2 на каждом корте. */
    private function playCurrentRound(Tournament $tournament): void
    {
        $round = $tournament->fresh()->escaleraRounds()->reorder('round_number', 'desc')->first();
        $scores = [[12, 0], [11, 1], [10, 2]];

        foreach ($round->courts as $court) {
            foreach ($court->matches()->orderBy('match_number')->get() as $i => $match) {
                $this->service()->saveMatchResult($match, $scores[$i][0], $scores[$i][1]);
            }
        }
    }

    // ===== Live =====

    public function test_live_returns_rounds_with_court_tiers(): void
    {
        [$t, $users] = $this->startedTournament(3);
        Sanctum::actingAs($users['P1']);

        $res = $this->getJson("/api/mobile/tournaments/{$t->id}/live")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('tournament.format', 'escalera')
            ->assertJsonPath('tournament.is_paired', false);

        $rounds = $res->json('rounds');
        $this->assertCount(1, $rounds);
        $this->assertCount(9, $rounds[0]['matches'], 'три корта по три матча');

        // Ярус корта — суть формата: наверху сильнейшие, внизу слабейшие.
        $tiers = [];
        foreach ($rounds[0]['matches'] as $m) {
            $tiers[$m['court_number']] = $m['court_tier'];
        }
        $this->assertSame('top', $tiers[1]);
        $this->assertSame('middle', $tiers[2]);
        $this->assertSame('bottom', $tiers[3]);

        // Подпись крайних кортов называет ярус словом.
        $labels = array_column($rounds[0]['matches'], 'court_label', 'court_number');
        $this->assertStringContainsString('верхний', $labels[1]);
        $this->assertStringContainsString('нижний', $labels[3]);
    }

    public function test_live_marks_matches_of_current_player(): void
    {
        [$t, $users] = $this->startedTournament(3);
        // P1 сильнейший — он на первом корте и играет все три матча корта.
        Sanctum::actingAs($users['P1']);

        $res = $this->getJson("/api/mobile/tournaments/{$t->id}/live")->assertOk();
        $matches = $res->json('rounds.0.matches');

        $mine = array_values(array_filter($matches, fn ($m) => $m['has_me'] === true));
        $this->assertCount(3, $mine, 'игрок играет три матча своего корта');
        foreach ($mine as $m) {
            $this->assertSame(1, $m['court_number'], 'все матчи на его корте');
        }

        // Матчи чужих кортов не подсвечены.
        $foreign = array_values(array_filter($matches, fn ($m) => $m['court_number'] !== 1));
        foreach ($foreign as $m) {
            $this->assertFalse($m['has_me']);
        }

        // В таблице подсвечена своя строка, и ровно одна.
        $me = array_values(array_filter($res->json('leaderboard'), fn ($r) => $r['is_me'] === true));
        $this->assertCount(1, $me);
        $this->assertSame($users['P1']->id, $me[0]['id']);
    }

    public function test_live_highlights_requested_player_instead_of_viewer(): void
    {
        [$t, $users] = $this->startedTournament(3);
        // Смотрит P1, но открывает карточку P12 из чужого профиля.
        Sanctum::actingAs($users['P1']);

        $res = $this->getJson(
            "/api/mobile/tournaments/{$t->id}/live?player_id={$users['P12']->id}"
        )->assertOk();

        $me = array_values(array_filter($res->json('leaderboard'), fn ($r) => $r['is_me'] === true));
        $this->assertCount(1, $me);
        $this->assertSame($users['P12']->id, $me[0]['id'], 'подсвечен запрошенный игрок');

        $mine = array_values(array_filter($res->json('rounds.0.matches'), fn ($m) => $m['has_me']));
        $this->assertCount(3, $mine);
        $this->assertSame(3, $mine[0]['court_number'], 'P12 слабейший — нижний корт');
    }

    public function test_live_leaderboard_carries_scored_and_conceded(): void
    {
        [$t, $users] = $this->startedTournament(3);
        $this->playCurrentRound($t);
        Sanctum::actingAs($users['P1']);

        $res = $this->getJson("/api/mobile/tournaments/{$t->id}/live")->assertOk();
        $row = collect($res->json('leaderboard'))->firstWhere('id', $users['P1']->id);

        // P1 сидит первым на первом корте: 12+11+10 забито, 0+1+2 пропущено.
        $this->assertSame(33, $row['points_for']);
        $this->assertSame(3, $row['points_against']);
        $this->assertSame(3, $row['wins']);
        $this->assertSame(0, $row['losses']);
        $this->assertSame(1, $row['position'], 'лидер таблицы');
    }

    public function test_live_total_points_follows_standings_mode(): void
    {
        // Режим «по баллам»: в зачёт идут баллы за позицию в общем строю.
        [$t, $users] = $this->startedTournament(3, 'points');
        $this->playCurrentRound($t);
        $this->service()->closeRound($t);
        Sanctum::actingAs($users['P1']);

        $byPoints = collect($this->getJson("/api/mobile/tournaments/{$t->id}/live")->json('leaderboard'))
            ->firstWhere('id', $users['P1']->id);
        // Двенадцать игроков, первая позиция — 12 баллов.
        $this->assertSame(12, $byPoints['total_points']);

        // Режим «по очкам»: в зачёт идёт сумма забитых.
        [$t2, $users2] = $this->startedTournament(3, 'raw_points');
        $this->playCurrentRound($t2);
        $this->service()->closeRound($t2);
        Sanctum::actingAs($users2['P1']);

        $byRaw = collect($this->getJson("/api/mobile/tournaments/{$t2->id}/live")->json('leaderboard'))
            ->firstWhere('id', $users2['P1']->id);
        $this->assertSame(33, $byRaw['total_points']);
    }

    public function test_live_reports_rating_change_per_round(): void
    {
        [$t, $users] = $this->startedTournament(3);
        $this->playCurrentRound($t);
        Sanctum::actingAs($users['P1']);

        $rounds = $this->getJson("/api/mobile/tournaments/{$t->id}/live")->json('rounds');

        $this->assertNotNull($rounds[0]['my_rating_change'], 'дельта за раунд посчитана');
        $this->assertIsInt($rounds[0]['my_rating_change']);
    }

    public function test_live_hides_rating_change_for_unrated_tournament(): void
    {
        [$t, $users] = $this->startedTournament(3);
        $t->update(['is_rated' => false]);
        $this->playCurrentRound($t);
        Sanctum::actingAs($users['P1']);

        $rounds = $this->getJson("/api/mobile/tournaments/{$t->id}/live")->json('rounds');
        $this->assertNull($rounds[0]['my_rating_change']);
    }

    // ===== Место в истории и профиле =====

    public function test_archive_shows_place_matching_standings(): void
    {
        [$t, $users] = $this->startedTournament(3);
        $this->playCurrentRound($t);
        $this->service()->closeRound($t);
        $this->service()->finishTournament($t->fresh());

        $standings = $this->service()->standings($t->fresh());
        $leader = $standings[0];
        $last = $standings[count($standings) - 1];

        // Лидер таблицы видит первое место в своём архиве.
        Sanctum::actingAs(User::find($leader['user_id']));
        $rows = $this->getJson('/api/mobile/tournaments/archive')->assertOk()->json('tournaments');
        $row = collect($rows)->firstWhere('id', $t->id);
        $this->assertNotNull($row, 'турнир виден в архиве');
        $this->assertSame(1, $row['my_result']['place']);

        // Последний в таблице — последнее место, а не пустое значение.
        Sanctum::actingAs(User::find($last['user_id']));
        $rows = $this->getJson('/api/mobile/tournaments/archive')->assertOk()->json('tournaments');
        $row = collect($rows)->firstWhere('id', $t->id);
        $this->assertSame(count($standings), $row['my_result']['place']);
    }

    // ===== Матчи и статистика профиля =====

    public function test_history_includes_escalera_matches(): void
    {
        [$t, $users] = $this->startedTournament(3);
        $this->playCurrentRound($t);
        Sanctum::actingAs($users['P1']);

        $matches = $this->getJson('/api/mobile/matches/history')->assertOk()->json('matches');

        $mine = array_values(array_filter($matches, fn ($m) => $m['format'] === 'escalera'));
        $this->assertCount(3, $mine, 'три коротких матча своего корта');

        // P1 выиграл все три: 12:0, 11:1, 10:2.
        foreach ($mine as $m) {
            $this->assertSame('win', $m['result']);
            $this->assertSame($t->name, $m['tournament_name']);
        }
    }

    public function test_profile_stats_count_escalera(): void
    {
        [$t, $users] = $this->startedTournament(3);
        $this->playCurrentRound($t);
        $this->service()->closeRound($t);
        $this->service()->finishTournament($t->fresh());

        $player = $users['P1']->fresh();

        $matchStats = $player->getAllMatchesStats();
        $this->assertSame(3, $matchStats['total'], 'три коротких матча');
        $this->assertSame(3, $matchStats['won']);
        $this->assertSame(0, $matchStats['lost']);

        $tournamentStats = $player->getTournamentStats();
        $this->assertSame(1, $tournamentStats['by_type']['escalera'] ?? 0);
    }

    public function test_profile_stats_count_draw_in_escalera(): void
    {
        [$t, $users] = $this->startedTournament(3);
        // Первый матч первого корта — ничья: счёт в формате свободный.
        $court = $t->escaleraRounds()->first()->courts()->orderBy('court_number')->first();
        $first = $court->matches()->orderBy('match_number')->first();
        $this->service()->saveMatchResult($first, 5, 5);

        $stats = $users['P1']->fresh()->getAllMatchesStats();
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['draw'], 'ничья не победа и не поражение');
        $this->assertSame(0, $stats['won']);
        $this->assertSame(0, $stats['lost']);
    }

    // ===== AI-разбор =====

    public function test_ai_analysis_collects_escalera_matches(): void
    {
        [$t, $users] = $this->startedTournament(3);
        $this->playCurrentRound($t);
        $this->service()->closeRound($t);
        $this->service()->finishTournament($t->fresh());

        Sanctum::actingAs($users['P1']);

        // Текст разбора пишет Claude, ключа в тестах нет — подменяем сервис.
        // Проверяем не текст, а данные, которые мы ему собираем.
        $captured = null;
        $this->mock(
            \App\Services\TournamentAiAnalysisService::class,
            function ($mock) use (&$captured) {
                $mock->shouldReceive('generate')
                    ->once()
                    ->andReturnUsing(function ($context) use (&$captured) {
                        $captured = $context;

                        return ['model' => 'test', 'analysis' => ['summary' => 'ок']];
                    });
            }
        );

        $res = $this->getJson("/api/mobile/tournaments/{$t->id}/ai-analysis")
            ->assertOk()
            ->assertJsonPath('success', true);

        // В контекст для AI попали матчи игрока с соперниками и дельтами.
        $this->assertNotNull($captured, 'сервис вызван');
        $this->assertCount(3, $captured['matches'], 'три коротких матча своего корта');
        $this->assertSame(1, $captured['player']['place'], 'место в турнире');
        $this->assertNotEmpty($captured['matches'][0]['opponents']);
        $this->assertNotEmpty($captured['matches'][0]['partners']);

        $matches = $res->json('matches');
        $this->assertIsArray($matches, 'разбивка по матчам собрана');
        $this->assertCount(3, $matches, 'три коротких матча своего корта');

        // P1 выиграл все три: 12:0, 11:1, 10:2.
        $this->assertSame(12, $matches[0]['score_my']);
        $this->assertSame(0, $matches[0]['score_opponent']);
        $this->assertSame('win', $matches[0]['result']);

        // Рейтинговые данные посчитаны, а не проставлены нулями.
        $this->assertNotNull($matches[0]['my_avg'], 'средний рейтинг своей пары');
        $this->assertNotNull($matches[0]['opp_avg'], 'средний рейтинг соперников');
        $this->assertNotNull($matches[0]['win_prob'], 'шанс на победу');
        $this->assertNotSame(0, $matches[0]['delta'], 'дельта рейтинга за матч');
    }
}
