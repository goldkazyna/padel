<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\PairRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Досбор пары в карточке турнира.
 *
 * Половина пары — обычное дело: человек записался один. Раньше добить такую
 * пару было нечем — только разбить и собрать заново.
 */
class FillPairTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;
    private User $first;

    protected function setUp(): void
    {
        parent::setUp();

        $club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $this->tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano_flex',
            'is_paired' => true,
            'open_pairs' => true,
            'status' => 'open',
            'max_participants' => 12,
        ]);

        $this->first = User::factory()->create(['rating' => 3000]);
        $this->tournament->participants()->attach($this->first->id, ['status' => 'registered']);
    }

    private function halfPair(): TournamentTeam
    {
        return TournamentTeam::create([
            'tournament_id' => $this->tournament->id,
            'player1_id' => $this->first->id,
            'player2_id' => null,
            'status' => 'approved',
            'rating_avg' => 3000,
        ]);
    }

    public function test_второй_садится_в_пару_и_попадает_в_состав(): void
    {
        $pair = $this->halfPair();
        $second = User::factory()->create(['rating' => 2000]);

        [$ok, $message] = app(PairRegistrationService::class)
            ->fillPair($this->tournament, $pair->id, $second->id);

        $this->assertTrue($ok, $message);

        $pair->refresh();
        $this->assertSame($second->id, (int) $pair->player2_id);
        $this->assertSame(2500, (int) $pair->rating_avg, 'средний рейтинг пары пересчитан');
        // Не был записан — добавили в состав, иначе пара «из воздуха».
        $this->assertTrue(
            $this->tournament->participants()->where('user_id', $second->id)->exists()
        );
    }

    public function test_в_полную_пару_не_сажаем(): void
    {
        $pair = $this->halfPair();
        $second = User::factory()->create();
        app(PairRegistrationService::class)->fillPair($this->tournament, $pair->id, $second->id);

        $third = User::factory()->create();
        [$ok, $message] = app(PairRegistrationService::class)
            ->fillPair($this->tournament, $pair->id, $third->id);

        $this->assertFalse($ok);
        $this->assertSame('В этой паре уже двое', $message);
    }

    public function test_того_кто_уже_в_паре_не_пересаживаем(): void
    {
        $pair = $this->halfPair();
        $other = User::factory()->create();
        TournamentTeam::create([
            'tournament_id' => $this->tournament->id,
            'player1_id' => $other->id,
            'player2_id' => null,
            'status' => 'approved',
            'rating_avg' => 2000,
        ]);

        [$ok, $message] = app(PairRegistrationService::class)
            ->fillPair($this->tournament, $pair->id, $other->id);

        $this->assertFalse($ok);
        $this->assertSame('Игрок уже состоит в паре', $message);
    }

    public function test_сам_с_собой_в_пару_не_встаёт(): void
    {
        $pair = $this->halfPair();

        [$ok, $message] = app(PairRegistrationService::class)
            ->fillPair($this->tournament, $pair->id, $this->first->id);

        $this->assertFalse($ok);
        $this->assertSame('Игрок не может быть в паре с самим собой', $message);
    }

    public function test_посадка_переводит_из_модерации_в_состав(): void
    {
        $pair = $this->halfPair();
        $second = User::factory()->create(['rating' => 2000]);
        $this->tournament->participants()->attach($second->id, ['status' => 'pending']);

        [$ok, $message] = app(PairRegistrationService::class)
            ->fillPair($this->tournament, $pair->id, $second->id);

        $this->assertTrue($ok, $message);
        // Место в паре — это и есть одобрение: иначе играет, а в составе нет.
        $this->assertSame('registered', $this->tournament->participants()
            ->where('user_id', $second->id)->first()->pivot->status);
    }

    public function test_посадка_поднимает_из_листа_ожидания(): void
    {
        $pair = $this->halfPair();
        $second = User::factory()->create(['rating' => 2000]);
        $this->tournament->participants()->attach($second->id, ['status' => 'waiting']);

        app(PairRegistrationService::class)->fillPair($this->tournament, $pair->id, $second->id);

        $this->assertSame('registered', $this->tournament->participants()
            ->where('user_id', $second->id)->first()->pivot->status);
    }

    public function test_игрока_пересаживают_в_другую_пару(): void
    {
        $pair = $this->halfPair();
        $second = User::factory()->create(['rating' => 2000]);
        app(PairRegistrationService::class)->fillPair($this->tournament, $pair->id, $second->id);

        $other = User::factory()->create(['rating' => 1000]);
        $this->tournament->participants()->attach($other->id, ['status' => 'registered']);
        $target = TournamentTeam::create([
            'tournament_id' => $this->tournament->id,
            'player1_id' => $other->id,
            'player2_id' => null,
            'status' => 'approved',
            'rating_avg' => 1000,
        ]);

        [$ok, $message] = app(PairRegistrationService::class)
            ->movePlayerToPair($this->tournament, $second->id, $target->id);

        $this->assertTrue($ok, $message);
        $this->assertSame($second->id, (int) $target->fresh()->player2_id);
        // Из прежней пары ушёл, но она осталась с первым игроком.
        $this->assertNull($pair->fresh()->player2_id);
        $this->assertSame($this->first->id, (int) $pair->fresh()->player1_id);
    }

    public function test_в_свою_же_пару_не_пересаживаем(): void
    {
        $pair = $this->halfPair();

        [$ok, $message] = app(PairRegistrationService::class)
            ->movePlayerToPair($this->tournament, $this->first->id, $pair->id);

        $this->assertFalse($ok);
        $this->assertSame('Игрок уже в этой паре', $message);
    }

    public function test_после_старта_пары_не_трогаем(): void
    {
        $pair = $this->halfPair();
        $this->tournament->update(['status' => 'in_progress']);

        [$ok, $message] = app(PairRegistrationService::class)
            ->fillPair($this->tournament->fresh(), $pair->id, User::factory()->create()->id);

        $this->assertFalse($ok);
        $this->assertSame('Турнир уже запущен или завершён', $message);
    }
}
