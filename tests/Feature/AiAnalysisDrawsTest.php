<?php

namespace Tests\Feature;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ничья в разборе турнира.
 *
 * Раньше поражениями считалось всё, что не победа, — и разбор писал про
 * поражение там, где счёт был равным.
 */
class AiAnalysisDrawsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ничья_не_идёт_в_поражения(): void
    {
        $club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $me = User::factory()->create(['rating' => 2000]);
        $partner = User::factory()->create(['rating' => 2000]);
        $rivalA = User::factory()->create(['rating' => 2000]);
        $rivalB = User::factory()->create(['rating' => 2000]);

        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'status' => 'completed',
            'type' => 'americano',
            'is_rated' => true,
        ]);
        foreach ([$me, $partner, $rivalA, $rivalB] as $p) {
            $tournament->participants()->attach($p->id, ['status' => 'registered']);
        }

        $group = TournamentGroup::create(['tournament_id' => $tournament->id, 'name' => 'A']);
        // Разбор ищет матчи по составу группы, а не по записи на турнир.
        $group->players()->attach([$me->id, $partner->id, $rivalA->id, $rivalB->id]);

        // Три матча: победа, ничья, поражение.
        $scores = [[21, 15], [18, 18], [12, 21]];
        foreach ($scores as $i => [$mine, $theirs]) {
            $round = AmericanoRound::create([
                'tournament_group_id' => $group->id,
                'round_number' => $i + 1,
                'status' => 'completed',
            ]);
            AmericanoMatch::create([
                'americano_round_id' => $round->id,
                'court_number' => 1,
                'team1_player1_id' => $me->id,
                'team1_player2_id' => $partner->id,
                'team2_player1_id' => $rivalA->id,
                'team2_player2_id' => $rivalB->id,
                'team1_score' => $mine,
                'team2_score' => $theirs,
                'status' => 'completed',
            ]);
        }

        // Ответ модели подменяем: проверяем данные, которые ей уходят.
        $sent = null;
        Http::fake(function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response([
                'content' => [[
                    'text' => '"headline":"Ровный вечер","summary":"Одна победа, одна ничья",'
                        . '"factors":[{"title":"Ничья","detail":"Счёт равный"}],"tips":["Играть"]}',
                ]],
            ]);
        });
        config(['services.anthropic.key' => 'test-key']);

        Sanctum::actingAs($me);

        $this->getJson("/api/mobile/tournaments/{$tournament->id}/ai-analysis")
            ->assertOk();

        $context = json_decode($sent['messages'][0]['content'], true)
            ?? $this->parseContext($sent['messages'][0]['content']);

        $this->assertSame(1, $context['player']['wins']);
        $this->assertSame(1, $context['player']['draws'], 'ничья считается отдельно');
        $this->assertSame(1, $context['player']['losses'], 'в поражения ничья не идёт');
    }

    /** Контекст уходит после строки-заголовка — вырезаем JSON. */
    private function parseContext(string $content): array
    {
        $start = strpos($content, '{');

        return json_decode(substr($content, $start), true) ?? [];
    }
}
