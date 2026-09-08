<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\PairRegistrationService;
use App\Support\OpenPairs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тасовка состава парного флекса организатором.
 *
 * Организатор двигает людей руками: в лист ожидания и обратно, из пары в
 * пару, на занятое место. Раньше перевод в лист ожидания оставлял игрока в
 * паре (человек вне состава, а место занято), а когда все пары были полными,
 * пересадить было некуда вовсе — меню оказывалось пустым.
 */
class FlexSeatShuffleTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;

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
            'max_participants' => 8,
        ]);
    }

    private function player(int $rating, string $status = 'registered'): User
    {
        $user = User::factory()->create(['rating' => $rating]);
        $this->tournament->participants()->attach($user->id, ['status' => $status]);

        return $user;
    }

    private function pair(User $first, ?User $second = null): TournamentTeam
    {
        return TournamentTeam::create([
            'tournament_id' => $this->tournament->id,
            'player1_id' => $first->id,
            'player2_id' => $second?->id,
            'status' => 'approved',
            'rating_avg' => (int) round(((int) $first->rating + (int) ($second->rating ?? $first->rating)) / 2),
        ]);
    }

    private function service(): PairRegistrationService
    {
        return app(PairRegistrationService::class);
    }

    public function test_перевод_в_лист_ожидания_освобождает_место_в_паре(): void
    {
        $first = $this->player(3000);
        $second = $this->player(2000);
        $pair = $this->pair($first, $second);

        $this->actingAs($this->clubAdmin())
            ->post(route('club.tournaments.participants.move', [$this->tournament, $second->id]), ['to' => 'waiting'])
            ->assertRedirect();

        $pair->refresh();
        $this->assertNull($pair->player2_id, 'ожидающий не должен занимать место в сетке');
        $this->assertSame($first->id, (int) $pair->player1_id);
    }

    public function test_игрока_сажают_на_занятое_место_меняя_местами(): void
    {
        $a = $this->player(3000);
        $b = $this->player(2000);
        $c = $this->player(1000);
        $d = $this->player(1500);

        $left = $this->pair($a, $b);
        $right = $this->pair($c, $d);

        // b идёт на место d — значит d занимает место b.
        [$ok, $message] = $this->service()->movePlayerToSeat($this->tournament, $b->id, $right->id, 2);

        $this->assertTrue($ok, $message);
        $this->assertSame($b->id, (int) $right->fresh()->player2_id);
        $this->assertSame($d->id, (int) $left->fresh()->player2_id);
        $this->assertSame(2250, (int) $left->fresh()->rating_avg, 'средний рейтинг пересчитан');
    }

    public function test_из_полной_сетки_игрока_пересаживают_в_пустую_пару(): void
    {
        $a = $this->player(3000);
        $b = $this->player(2000);
        $pair = $this->pair($a, $b);

        [$ok, $message] = $this->service()->movePlayerToSeat($this->tournament, $b->id, 0, 2);

        $this->assertTrue($ok, $message);
        $this->assertNull($pair->fresh()->player2_id);
        $this->assertSame(2, $this->tournament->teams()->count());
        $this->assertSame(
            $b->id,
            (int) $this->tournament->teams()->orderByDesc('id')->first()->player1_id
        );
    }

    public function test_посадка_поднимает_из_листа_ожидания(): void
    {
        $first = $this->player(3000);
        $waiting = $this->player(2000, 'waiting');
        $pair = $this->pair($first);

        [$ok, $message] = $this->service()->movePlayerToSeat($this->tournament, $waiting->id, $pair->id, 2);

        $this->assertTrue($ok, $message);
        $this->assertSame('registered', $this->tournament->participants()
            ->where('user_id', $waiting->id)->first()->pivot->status);
    }

    public function test_заявка_на_модерации_держит_место_и_не_одобряется_пересадкой(): void
    {
        $first = $this->player(3000);
        $pending = $this->player(2000, 'pending');
        $pair = $this->pair($first);

        $this->service()->movePlayerToSeat($this->tournament, $pending->id, $pair->id, 2);

        $this->assertSame('pending', $this->tournament->participants()
            ->where('user_id', $pending->id)->first()->pivot->status,
            'пересадка не заменяет одобрение заявки');
        $this->assertSame($pending->id, (int) $pair->fresh()->player2_id);
    }

    public function test_вытесненный_остаётся_в_составе_без_пары(): void
    {
        $a = $this->player(3000);
        $b = $this->player(2000);
        $loner = $this->player(1500);
        $pair = $this->pair($a, $b);

        [$ok] = $this->service()->movePlayerToSeat($this->tournament, $loner->id, $pair->id, 2);

        $this->assertTrue($ok);
        $this->assertSame($loner->id, (int) $pair->fresh()->player2_id);
        $this->assertNull(OpenPairs::teamOf($this->tournament, $b->id), 'вытесненный без пары');
        $this->assertSame('registered', $this->tournament->participants()
            ->where('user_id', $b->id)->first()->pivot->status, 'но из турнира не выпал');
    }

    public function test_на_своё_же_место_не_пересаживаем(): void
    {
        $first = $this->player(3000);
        $pair = $this->pair($first);

        [$ok, $message] = $this->service()->movePlayerToSeat($this->tournament, $first->id, $pair->id, 1);

        $this->assertFalse($ok);
        $this->assertSame('Игрок уже на этом месте', $message);
    }

    /**
     * Маршруты бьют по настоящему контроллеру.
     *
     * Сервис вызывали напрямую, а маршруты указывали не на тот класс —
     * страница отдавала 500, хотя все тесты были зелёными.
     */
    public function test_кнопки_состава_работают_через_маршруты(): void
    {
        $first = $this->player(3000);
        $second = $this->player(2000);
        $loner = $this->player(1500);
        $pair = $this->pair($first);
        $admin = $this->clubAdmin();

        // Досбор пары.
        $this->actingAs($admin)
            ->post(route('club.tournaments.pairs.fill', [$this->tournament, $pair]), ['player_id' => $second->id])
            ->assertRedirect();
        $this->assertSame($second->id, (int) $pair->fresh()->player2_id);

        // Пересадка на занятое место — меняются местами.
        $this->actingAs($admin)
            ->post(route('club.tournaments.pairs.move', [$this->tournament, $loner->id, $pair->id, 2]))
            ->assertRedirect();
        $this->assertSame($loner->id, (int) $pair->fresh()->player2_id);

        // Посадка в первое свободное место.
        $this->actingAs($admin)
            ->post(route('club.tournaments.pairs.seat', [$this->tournament, $second->id]))
            ->assertRedirect();
        $this->assertNotNull(OpenPairs::teamOf($this->tournament, $second->id));
    }

    private function clubAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->tournament->club_id);

        return $admin;
    }
}
