<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Открытые пары в парном Americano Flex.
 *
 * Игрок записывается один и сразу становится половиной пары — рядом пустое
 * место, к которому подсаживается следующий. Пар ровно столько, сколько
 * помещается в турнир; когда все созданы, запись уходит в лист ожидания, и
 * оттуда игрока переставляет организатор.
 */
class OpenPairsTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);

        $this->club = Club::create(['name' => 'Davay Padel', 'address' => 'А', 'city' => 'Алматы']);

        // 6 мест = 3 пары.
        $this->tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'americano_flex',
            'is_paired' => true,
            'open_pairs' => true,
            'status' => 'open',
            'max_participants' => 6,
            'waitlist_size' => 4,
            'min_level' => 1,
            'max_level' => 7,
        ]);
    }

    private function player(): User
    {
        return User::factory()->create(['level' => 3.0, 'rating' => 3000]);
    }

    private function register(User $user, array $body = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/register", $body);
    }

    public function test_запись_в_одиночку_создаёт_открытую_пару(): void
    {
        $user = $this->player();

        $this->register($user)->assertOk();

        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->first();
        $this->assertNotNull($team, 'пара создалась');
        $this->assertSame($user->id, (int) $team->player1_id);
        $this->assertNull($team->player2_id, 'второе место свободно');
    }

    public function test_второй_садится_в_свободное_место(): void
    {
        $first = $this->player();
        $second = $this->player();
        $this->register($first)->assertOk();

        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();

        Sanctum::actingAs($second);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")
            ->assertOk();

        $team->refresh();
        $this->assertSame($second->id, (int) $team->player2_id);
        $this->assertSame(1, TournamentTeam::where('tournament_id', $this->tournament->id)->count());
        $this->assertSame(2, $this->tournament->participants()->count());
    }

    public function test_занятое_место_второй_раз_не_отдаём(): void
    {
        $first = $this->player();
        $second = $this->player();
        $third = $this->player();

        $this->register($first)->assertOk();
        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();

        Sanctum::actingAs($second);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")->assertOk();

        Sanctum::actingAs($third);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Место в паре уже заняли');
    }

    public function test_когда_все_пары_созданы_запись_идёт_в_лист_ожидания(): void
    {
        // Три пары по одному человеку — мест для новых пар нет.
        for ($i = 0; $i < 3; $i++) {
            $this->register($this->player())->assertOk();
        }

        // Переспрашивать не о чем: свободные места в парах остались, и
        // человек либо сядет сам, либо его посадит организатор.
        $seventh = $this->player();
        $this->register($seventh)
            ->assertOk()
            ->assertJsonPath('registration_status', 'waiting');

        $this->assertSame(
            'waiting',
            $this->tournament->participants()->where('user_id', $seventh->id)->first()->pivot->status
        );
        // Новой пары не появилось: их и так максимум.
        $this->assertSame(3, TournamentTeam::where('tournament_id', $this->tournament->id)->count());
    }

    public function test_из_листа_ожидания_можно_сесть_в_пару(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->register($this->player())->assertOk();
        }
        $waiting = $this->player();
        $this->register($waiting, ['confirm_waitlist' => true])->assertOk();

        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();

        Sanctum::actingAs($waiting);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")
            ->assertOk();

        $this->assertSame($waiting->id, (int) $team->fresh()->player2_id);
    }

    public function test_отмена_освобождает_место_а_партнёр_остаётся(): void
    {
        $first = $this->player();
        $second = $this->player();
        $this->register($first)->assertOk();
        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();

        Sanctum::actingAs($second);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")->assertOk();

        // Уходит первый — второй занимает его место, пара снова открыта.
        Sanctum::actingAs($first);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/cancel")->assertOk();

        $team->refresh();
        $this->assertSame($second->id, (int) $team->player1_id);
        $this->assertNull($team->player2_id);
    }

    public function test_последний_ушёл_пары_нет(): void
    {
        $user = $this->player();
        $this->register($user)->assertOk();

        Sanctum::actingAs($user);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/cancel")->assertOk();

        $this->assertSame(0, TournamentTeam::where('tournament_id', $this->tournament->id)->count());
    }

    public function test_пара_не_переживает_убранного_организатором(): void
    {
        // Организатор убирает участника не через отмену записи — пара после
        // этого не должна остаться без игрока. Ровно так в турнире 1480
        // повисли две пустые пары.
        $first = $this->player();
        $second = $this->player();
        $this->register($first)->assertOk();
        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();

        Sanctum::actingAs($second);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")->assertOk();

        // Снимаем обоих «мимо» отмены — так делает админка.
        $this->tournament->participants()->detach([$first->id, $second->id]);
        \App\Support\OpenPairs::prune($this->tournament->fresh());

        $this->assertSame(0, TournamentTeam::where('tournament_id', $this->tournament->id)->count());
    }

    public function test_убрали_одного_из_пары_второй_остаётся(): void
    {
        $first = $this->player();
        $second = $this->player();
        $this->register($first)->assertOk();
        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();

        Sanctum::actingAs($second);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")->assertOk();

        $this->tournament->participants()->detach($first->id);
        \App\Support\OpenPairs::prune($this->tournament->fresh());

        $team->refresh();
        $this->assertSame($second->id, (int) $team->player1_id);
        $this->assertNull($team->player2_id, 'место снова свободно');
    }

    public function test_можно_занять_пустую_пару_в_сетке(): void
    {
        $user = $this->player();
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs")
            ->assertOk();

        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();
        $this->assertSame($user->id, (int) $team->player1_id);
        $this->assertNull($team->player2_id);
    }

    public function test_пересадка_в_пустую_пару_освобождает_прежнюю(): void
    {
        $first = $this->player();
        $second = $this->player();
        $this->register($first)->assertOk();
        $team = TournamentTeam::where('tournament_id', $this->tournament->id)->firstOrFail();

        Sanctum::actingAs($second);
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")->assertOk();

        // Передумал играть с первым — пересел в свою пару.
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs")->assertOk();

        $this->assertNull($team->fresh()->player2_id, 'место у первого снова свободно');
        $this->assertSame(2, TournamentTeam::where('tournament_id', $this->tournament->id)->count());
    }

    public function test_когда_пар_нет_пустую_занять_нельзя(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->register($this->player())->assertOk();
        }

        Sanctum::actingAs($this->player());
        $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Свободных пар не осталось');
    }

    public function test_в_старых_турнирах_ничего_не_меняется(): void
    {
        $this->tournament->update(['open_pairs' => false]);

        $this->register($this->player())->assertOk();

        $this->assertSame(0, TournamentTeam::where('tournament_id', $this->tournament->id)->count());
        $this->assertSame(1, $this->tournament->participants()->count());
    }

    public function test_очередь_в_открытых_парах_без_лимита(): void
    {
        // Все пары созданы, но места рядом с игроками свободны: отказывать
        // человеку не за что, и переспрашивать «встать в очередь?» тоже.
        // Три пары — весь турнир, каждый записавшийся открывает свою.
        foreach (range(1, 3) as $i) {
            $this->register($this->player())->assertOk();
        }
        $this->tournament->update(['waitlist_size' => 0]);

        $late = User::factory()->create(['level' => 2.0]);
        Sanctum::actingAs($late);

        $response = $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/register");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('registration_status', 'waiting');

        $this->assertStringContainsString('«+»', $response->json('message'));
        $this->assertStringContainsString(
            'организатора',
            $response->json('message'),
            'подсказываем и второй путь — попросить организатора'
        );
    }

    public function test_когда_свободных_мест_нет_очередь_просто_ждёт(): void
    {
        // Три пары собраны целиком: просить «сядьте сами» не о чем, и
        // сообщение должно обещать место, а не давать задание.
        $pairs = [];
        foreach (range(1, 3) as $i) {
            $first = $this->player();
            $this->register($first)->assertOk();
            $team = TournamentTeam::where('tournament_id', $this->tournament->id)
                ->whereNull('player2_id')->orderBy('id')->first();
            $second = $this->player();
            Sanctum::actingAs($second);
            $this->postJson("/api/mobile/tournaments/{$this->tournament->id}/pairs/{$team->id}/join")
                ->assertOk();
        }

        $late = $this->player();
        $response = $this->register($late);

        $response->assertOk()->assertJsonPath('registration_status', 'waiting');
        $this->assertStringContainsString('Мест нет', $response->json('message'));
        $this->assertStringNotContainsString('«+»', $response->json('message'),
            'звать сесть некуда — свободных мест нет');
    }
}
