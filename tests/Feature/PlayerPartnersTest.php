<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Support\PlayerPartners;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Лучший партнёр» в профиле.
 *
 * Считается по id партнёра, а не по имени: тёзки склеивались бы в одного
 * человека, и открыть его профиль по строке было нельзя.
 */
class PlayerPartnersTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Tournament $tournament;
    private AmericanoFlexRound $round;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->tournament = Tournament::create([
            'club_id' => $this->club->id, 'name' => 'Flex', 'type' => 'americano_flex',
            'status' => 'completed', 'is_rated' => true, 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
        $this->round = AmericanoFlexRound::create([
            'tournament_id' => $this->tournament->id, 'round_number' => 1, 'status' => 'completed',
        ]);
    }

    private function play(User $me, User $partner, bool $won): void
    {
        $rivalA = User::factory()->create();
        $rivalB = User::factory()->create();

        foreach ([$me, $partner, $rivalA, $rivalB] as $u) {
            AmericanoFlexPlayer::firstOrCreate([
                'tournament_id' => $this->tournament->id, 'user_id' => $u->id,
            ]);
        }

        AmericanoFlexMatch::create([
            'americano_flex_round_id' => $this->round->id,
            'court_number' => 1,
            'team1_player1_id' => $me->id, 'team1_player2_id' => $partner->id,
            'team2_player1_id' => $rivalA->id, 'team2_player2_id' => $rivalB->id,
            'team1_score' => $won ? 16 : 8,
            'team2_score' => $won ? 8 : 16,
            'status' => 'completed',
        ]);
    }

    public function test_лучший_партнёр_тот_с_кем_чаще_выигрываешь(): void
    {
        $me = User::factory()->create();
        $lucky = User::factory()->create(['name' => 'Удачный']);
        $other = User::factory()->create(['name' => 'Обычный']);

        // С «Удачным» 3 матча и 3 победы, с «Обычным» 4 матча и 2 победы.
        foreach ([true, true, true] as $won) {
            $this->play($me, $lucky, $won);
        }
        foreach ([true, true, false, false] as $won) {
            $this->play($me, $other, $won);
        }

        $best = PlayerPartners::best($me);

        $this->assertSame($lucky->id, $best['user_id']);
        $this->assertSame(3, $best['games']);
        $this->assertSame(3, $best['wins']);
        $this->assertSame(100, $best['winrate']);
    }

    public function test_случайный_партнёр_не_вытесняет_проверенного(): void
    {
        $me = User::factory()->create();
        $random = User::factory()->create(['name' => 'Разовый']);
        $steady = User::factory()->create(['name' => 'Постоянный']);

        // Один общий матч и победа — 100%, но это ещё не лучший партнёр.
        $this->play($me, $random, true);
        foreach ([true, true, true, false] as $won) {
            $this->play($me, $steady, $won);
        }

        $best = PlayerPartners::best($me);

        $this->assertSame($steady->id, $best['user_id'], 'нужно минимум 3 матча');
        $this->assertSame(75, $best['winrate']);
    }

    public function test_партнёры_считаются_по_id_а_не_по_имени(): void
    {
        $me = User::factory()->create();
        $denis1 = User::factory()->create(['name' => 'Денис']);
        $denis2 = User::factory()->create(['name' => 'Денис']);

        foreach ([true, true, true] as $won) {
            $this->play($me, $denis1, $won);
        }
        $this->play($me, $denis2, false);

        $rows = PlayerPartners::all($me);

        $this->assertCount(2, $rows, 'тёзки — разные люди');
        $this->assertSame($denis1->id, $rows[0]['user_id']);
    }

    public function test_ручка_отдаёт_лучшего_и_топ(): void
    {
        $me = User::factory()->create();
        $partner = User::factory()->create(['name' => 'Партнёр', 'avatar' => 'https://x/a.png']);
        foreach ([true, true, false] as $won) {
            $this->play($me, $partner, $won);
        }

        $response = $this->actingAs($me, 'sanctum')
            ->getJson('/api/mobile/profile/partners')->assertOk();

        $this->assertSame($partner->id, $response->json('best.user_id'));
        $this->assertSame('Партнёр', $response->json('best.name'));
        $this->assertSame('https://x/a.png', $response->json('best.avatar'));
        $this->assertSame(3, $response->json('best.games'));
        $this->assertSame(2, $response->json('best.wins'));
        $this->assertSame(1, $response->json('partners_count'));
        $this->assertCount(1, $response->json('top'));
    }

    public function test_без_матчей_партнёра_нет(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/mobile/profile/partners')
            ->assertOk()
            ->assertJsonPath('best', null)
            ->assertJsonPath('partners_count', 0);
    }
}
