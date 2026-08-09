<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\TournamentAiAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI-разбор выступления должен собирать матчи игрока во ВСЕХ форматах.
 *
 * Текст пишет Claude, поэтому сервис подменяется моком: проверяем не текст,
 * а данные, которые мы ему отдаём. Без дельт рейтинга и рейтингов соперников
 * модель не может объяснить начисление — она для этого и вызывается.
 */
class TournamentAiAnalysisFormatsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Турнир заданного типа с зарегистрированными игроками.
     *
     * @return array{0:Tournament,1:array<int,User>}
     */
    private function makeTournament(string $type, int $players, array $extra = []): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);

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
                'rating' => 2000 - $i * 60,
            ]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $users[] = $user;
        }

        return [$tournament->fresh(), $users];
    }

    /**
     * Перехватить контекст, который уходит в Claude.
     *
     * @return array<string, mixed>|null
     */
    private function captureAiContext(Tournament $tournament, User $player): ?array
    {
        $captured = null;
        $this->mock(TournamentAiAnalysisService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')
                ->andReturnUsing(function ($context) use (&$captured) {
                    $captured = $context;

                    return ['model' => 'test', 'analysis' => ['summary' => 'ок']];
                });
        });

        Sanctum::actingAs($player);
        $this->getJson("/api/mobile/tournaments/{$tournament->id}/ai-analysis")
            ->assertOk()
            ->assertJsonPath('success', true);

        return $captured;
    }

    /** Общая проверка: матчи собраны и объяснимы. */
    private function assertContextIsUsable(?array $context, User $player, string $format): void
    {
        $this->assertNotNull($context, "{$format}: сервис не получил контекст");
        $this->assertNotEmpty($context['matches'], "{$format}: матчи игрока не собраны");

        $withDelta = array_filter(
            $context['matches'],
            fn ($m) => (int) ($m['rating_change'] ?? 0) !== 0
        );
        $this->assertNotEmpty($withDelta, "{$format}: ни у одного матча нет дельты рейтинга");

        $first = $context['matches'][0];
        $this->assertNotNull($first['my_pair_avg_rating'], "{$format}: нет рейтинга своей пары");
        $this->assertNotNull($first['opponent_pair_avg_rating'], "{$format}: нет рейтинга соперников");
        $this->assertNotNull($first['win_probability_percent'], "{$format}: нет шанса на победу");
        $this->assertNotEmpty($first['opponents'], "{$format}: не указаны соперники");

        $this->assertNotNull($context['player']['place'], "{$format}: не посчитано место");
        $this->assertSame($player->name, $context['player']['name']);
    }

    public function test_king_of_court_context_has_matches_with_deltas(): void
    {
        [$tournament, $users] = $this->makeTournament('king_of_court', 8);
        $service = app(\App\Services\KingOfCourtService::class);
        $this->assertTrue($service->startTournament($tournament));

        foreach ($tournament->fresh()->kingOfCourtRounds()->first()->matches as $i => $match) {
            $service->saveMatchResult($match, 8, 4);
        }
        $service->finishTournament($tournament->fresh());

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Король корта');
    }

    public function test_just_padel_it_context_has_matches_with_deltas(): void
    {
        [$tournament, $users] = $this->makeTournament('just_padel_it', 8, ['courts_count' => 2]);
        $service = app(\App\Services\JustPadelItService::class);
        $this->assertTrue($service->startTournament($tournament));

        foreach ($tournament->fresh()->justPadelItRounds()->first()->matches as $match) {
            $service->saveMatchResult($match, 8, 4);
        }
        $service->finishTournament($tournament->fresh());

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Just Padel It');
    }

    public function test_round_robin_context_has_matches_with_deltas(): void
    {
        [$tournament, $users] = $this->makeTournament('round_robin', 8);
        $service = app(\App\Services\RoundRobinService::class);
        $this->assertTrue($service->startTournament($tournament));

        foreach ($tournament->fresh()->roundRobinRounds()->first()->matches as $match) {
            $service->saveMatchResult($match, 6, 3);
        }
        $service->finishTournament($tournament->fresh());

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Round Robin');
    }

    public function test_americano_context_still_works(): void
    {
        // Охранник: американо работал и раньше, правки не должны его задеть.
        [$tournament, $users] = $this->makeTournament('americano', 8, ['groups_count' => 1]);
        $service = app(\App\Services\AmericanoService::class);
        $this->assertTrue($service->startTournament($tournament));

        $group = $tournament->fresh()->groups()->first();
        foreach ($group->rounds()->with('matches')->get() as $round) {
            foreach ($round->matches as $match) {
                $service->saveMatchResult($match, 8, 4);
            }
        }
        $service->finishTournament($tournament->fresh());

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Американо');
    }

    public function test_bali_koc_context_has_matches_with_deltas(): void
    {
        [$tournament, $users] = $this->makeTournament('bali_koc', 8, ['is_paired' => true]);
        $service = app(\App\Services\BaliKocService::class);

        // Пары: (P1,P2), (P3,P4), (P5,P6), (P7,P8).
        $pairs = [];
        for ($i = 0; $i < 8; $i += 2) {
            $pairs[] = [$users[$i]->id, $users[$i + 1]->id];
        }
        [$ok] = $service->createPairs($tournament, $pairs);
        $this->assertTrue($ok, 'пары созданы');

        $this->assertTrue($service->startTournament($tournament->fresh()));

        foreach ($tournament->fresh()->baliKocRounds()->first()->matches as $match) {
            $service->saveMatchResult($match, 6, 3);
        }
        $service->finishTournament($tournament->fresh());

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Bali Format');
    }

    public function test_mexicano_context_still_works(): void
    {
        [$tournament, $users] = $this->makeTournament('mexicano', 8, ['rounds_count' => 3]);
        $service = app(\App\Services\MexicanoService::class);
        $this->assertTrue($service->startTournament($tournament));

        // Мексикано завершается только когда сыграны все заявленные раунды.
        for ($round = 1; $round <= 3; $round++) {
            $current = $tournament->fresh()->mexicanoRounds()
                ->reorder('round_number', 'desc')->first();
            foreach ($current->matches as $match) {
                $service->saveMatchResult($match, 8, 4);
            }
            if ($round < 3) {
                $this->assertNotNull($service->generateNextRound($tournament->fresh()));
            }
        }
        $this->assertTrue($service->finishTournament($tournament->fresh()));

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Мексикано');
    }

    public function test_americano_flex_context_still_works(): void
    {
        [$tournament, $users] = $this->makeTournament('americano_flex', 8, ['courts_count' => 2]);
        $service = app(\App\Services\AmericanoFlexService::class);
        $this->assertTrue($service->startTournament($tournament));

        foreach ($tournament->fresh()->americanoFlexRounds()->first()->matches as $match) {
            $service->saveMatchResult($match, 8, 4);
        }
        $service->completeTournament($tournament->fresh());

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Americano Flex');
    }

    public function test_escalera_context_still_works(): void
    {
        [$tournament, $users] = $this->makeTournament('escalera', 8, [
            'courts_count' => 2,
            'escalera_standings_mode' => 'raw_points',
        ]);
        $service = app(\App\Services\EscaleraService::class);
        $this->assertTrue($service->startTournament($tournament));

        $round = $tournament->fresh()->escaleraRounds()->first();
        foreach ($round->courts as $court) {
            $scores = [[12, 0], [11, 1], [10, 2]];
            foreach ($court->matches()->orderBy('match_number')->get() as $i => $match) {
                $service->saveMatchResult($match, $scores[$i][0], $scores[$i][1]);
            }
        }
        $service->closeRound($tournament->fresh());
        $service->finishTournament($tournament->fresh());

        $context = $this->captureAiContext($tournament->fresh(), $users[0]);
        $this->assertContextIsUsable($context, $users[0], 'Эскалера');
    }
}
