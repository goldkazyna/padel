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

    public function test_серия_из_трёх_не_обгоняет_семь_побед_из_восьми(): void
    {
        // Голый процент врал: 3 из 3 казались лучше, чем 7 из 8, хотя с
        // восьмиматчевым партнёром сыграно втрое больше и он весомее.
        $me = User::factory()->create();
        $short = User::factory()->create(['name' => 'Три матча']);
        $long = User::factory()->create(['name' => 'Восемь матчей']);

        foreach ([true, true, true] as $won) {
            $this->play($me, $short, $won);
        }
        foreach ([true, true, true, true, true, true, true, false] as $won) {
            $this->play($me, $long, $won);
        }

        $rows = PlayerPartners::all($me);

        $this->assertSame($long->id, $rows[0]['user_id']);
        $this->assertSame(8, $rows[0]['games']);
        $this->assertSame(88, $rows[0]['winrate']);
        $this->assertSame($short->id, $rows[1]['user_id']);
        $this->assertGreaterThan($rows[1]['score'], $rows[0]['score']);
    }

    public function test_короткая_серия_не_обгоняет_длинную_историю(): void
    {
        // Три победы из трёх стояли выше восемнадцати матчей с 63% — а такой
        // партнёр проверен куда лучше.
        $me = User::factory()->create();
        $short = User::factory()->create(['name' => 'Три из трёх']);
        $long = User::factory()->create(['name' => 'Шестнадцать матчей']);

        foreach (range(1, 3) as $_) {
            $this->play($me, $short, true);
        }
        foreach (range(1, 10) as $_) {
            $this->play($me, $long, true);
        }
        foreach (range(1, 6) as $_) {
            $this->play($me, $long, false);
        }

        $rows = PlayerPartners::all($me);

        $this->assertSame($long->id, $rows[0]['user_id']);
        $this->assertSame($short->id, $rows[1]['user_id']);
    }

    public function test_галочка_верификации_приходит_с_партнёром(): void
    {
        $me = User::factory()->create();
        $verified = User::factory()->create(['level_verified' => true]);
        $plain = User::factory()->create(['level_verified' => false]);

        foreach (range(1, 3) as $_) {
            $this->play($me, $verified, true);
            $this->play($me, $plain, true);
        }

        $rows = collect(PlayerPartners::all($me))->keyBy('user_id');

        $this->assertTrue($rows[$verified->id]['verified']);
        $this->assertFalse($rows[$plain->id]['verified']);
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
