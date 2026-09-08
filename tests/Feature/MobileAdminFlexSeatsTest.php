<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Support\OpenPairs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Мобильная админка парного флекса: та же работа с местами, что в веб-CRM.
 *
 * Организатор чаще всего с телефоном у корта, а не за компьютером: пары надо
 * собирать и тасовать прямо там. Раньше в приложении был плоский список
 * участников, и посадить человека в конкретную пару было нечем.
 */
class MobileAdminFlexSeatsTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($club->id);

        $this->tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano_flex',
            'is_paired' => true,
            'open_pairs' => true,
            'status' => 'open',
            'max_participants' => 8,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function player(string $status = 'registered', int $rating = 2000): User
    {
        $user = User::factory()->create(['rating' => $rating, 'level' => 3.0]);
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
            'rating_avg' => (int) $first->rating,
        ]);
    }

    public function test_состав_отдаёт_сетку_пар(): void
    {
        $first = $this->player();
        $second = $this->player();
        $this->pair($first, $second);
        $this->pair($this->player());

        $response = $this->getJson("/api/mobile/admin/tournaments/{$this->tournament->id}/participants");

        $response->assertOk()
            ->assertJsonPath('flex_pairs.max_pairs', 4)
            ->assertJsonPath('flex_pairs.pairs.0.position', 1)
            ->assertJsonPath('flex_pairs.pairs.0.player1.id', $first->id)
            ->assertJsonPath('flex_pairs.pairs.0.player2.id', $second->id)
            // Неполная пара приходит как есть: экран рисует на месте второго
            // кнопку «посадить», а не прячет строку.
            ->assertJsonPath('flex_pairs.pairs.1.player2', null);

        // Экран рисует аватар и рейтинг прямо в сетке — поля должны быть.
        $this->assertArrayHasKey('avatar_url', $response->json('flex_pairs.pairs.0.player1'));
        $this->assertSame(2000, $response->json('flex_pairs.pairs.0.player1.rating'));
    }

    public function test_обычный_турнир_сетку_не_отдаёт(): void
    {
        $this->tournament->update(['is_paired' => false]);
        $this->player();

        $this->getJson("/api/mobile/admin/tournaments/{$this->tournament->id}/participants")
            ->assertOk()
            ->assertJsonMissingPath('flex_pairs');
    }

    public function test_админ_сажает_игрока_в_пару(): void
    {
        $pair = $this->pair($this->player());
        $second = $this->player();

        $this->postJson(
            "/api/mobile/admin/tournaments/{$this->tournament->id}/pairs/{$pair->id}/fill",
            ['user_id' => $second->id]
        )->assertOk()->assertJsonPath('success', true);

        $this->assertSame($second->id, (int) $pair->fresh()->player2_id);
    }

    public function test_админ_сажает_в_первое_свободное_место(): void
    {
        $pair = $this->pair($this->player());
        $loner = $this->player();

        $this->postJson(
            "/api/mobile/admin/tournaments/{$this->tournament->id}/pairs/seat",
            ['user_id' => $loner->id]
        )->assertOk();

        $this->assertSame($loner->id, (int) $pair->fresh()->player2_id);
    }

    public function test_админ_пересаживает_на_занятое_место(): void
    {
        $a = $this->player(rating: 3000);
        $b = $this->player(rating: 2000);
        $c = $this->player(rating: 1000);
        $d = $this->player(rating: 1500);
        $left = $this->pair($a, $b);
        $right = $this->pair($c, $d);

        $this->postJson("/api/mobile/admin/tournaments/{$this->tournament->id}/pairs/move", [
            'user_id' => $b->id,
            'team_id' => $right->id,
            'seat' => 2,
        ])->assertOk();

        // Меняются местами: у каждого остаётся пара, никто не выпадает.
        $this->assertSame($b->id, (int) $right->fresh()->player2_id);
        $this->assertSame($d->id, (int) $left->fresh()->player2_id);
    }

    public function test_админ_открывает_новую_пару(): void
    {
        $a = $this->player();
        $b = $this->player();
        $pair = $this->pair($a, $b);

        $this->postJson("/api/mobile/admin/tournaments/{$this->tournament->id}/pairs/move", [
            'user_id' => $b->id,
            'team_id' => 0,
            'seat' => 2,
        ])->assertOk();

        $this->assertNull($pair->fresh()->player2_id);
        $this->assertNotNull(OpenPairs::teamOf($this->tournament, $b->id));
        $this->assertSame(2, $this->tournament->teams()->count());
    }

    public function test_чужой_клуб_местами_не_двигает(): void
    {
        $pair = $this->pair($this->player());
        $stranger = User::factory()->create(['role' => 'club_admin']);
        Sanctum::actingAs($stranger);

        $this->postJson(
            "/api/mobile/admin/tournaments/{$this->tournament->id}/pairs/{$pair->id}/fill",
            ['user_id' => $this->player()->id]
        )->assertForbidden();
    }

    public function test_с_недособранной_парой_запуск_запрещён(): void
    {
        // Запуск выбрасывает неполные пары: игроки из них остались бы вне
        // турнира, поэтому и кнопка, и сам старт должны быть закрыты.
        $this->tournament->update(['courts_count' => 1]);
        $this->pair($this->player(), $this->player());
        $this->pair($this->player(), $this->player());
        $this->pair($this->player());

        $this->getJson("/api/mobile/admin/tournaments/{$this->tournament->id}")
            ->assertOk()
            ->assertJsonPath('tournament.can_start', false);

        $this->postJson("/api/mobile/admin/tournaments/{$this->tournament->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message',
                'Одна пара не собрана — посадите второго игрока или распустите её');

        $this->assertSame('open', $this->tournament->fresh()->status);
    }

    public function test_когда_все_пары_собраны_запуск_открыт(): void
    {
        $this->tournament->update(['courts_count' => 1]);
        $this->pair($this->player(), $this->player());
        $this->pair($this->player(), $this->player());

        $this->getJson("/api/mobile/admin/tournaments/{$this->tournament->id}")
            ->assertOk()
            ->assertJsonPath('tournament.can_start', true);
    }

    public function test_на_месте_в_паре_видно_статус_игрока(): void
    {
        // Человек может сидеть в сетке и висеть на модерации: без пометки
        // организатор этого не увидит и не поймёт, кого ещё одобрять.
        $first = $this->player();
        $pending = $this->player('pending');
        $this->pair($first, $pending);

        $this->getJson("/api/mobile/admin/tournaments/{$this->tournament->id}/participants")
            ->assertOk()
            ->assertJsonPath('flex_pairs.pairs.0.player1.status', 'registered')
            ->assertJsonPath('flex_pairs.pairs.0.player2.status', 'pending');
    }

    public function test_в_матчах_приходит_готовая_ссылка_на_аватар(): void
    {
        // В базе лежит готовый URL: дописанное storage/ ломало адрес, и
        // картинка в раунде не грузилась.
        $first = $this->player();
        $first->update(['avatar' => 'https://padel-p.kz/storage/avatars/a.webp']);
        $second = $this->player();
        $this->pair($first, $second);
        $this->tournament->update(['status' => 'in_progress']);

        $round = \App\Models\AmericanoFlexRound::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        \App\Models\AmericanoFlexMatch::create([
            'americano_flex_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $first->id,
            'team1_player2_id' => $second->id,
            'team2_player1_id' => $second->id,
            'team2_player2_id' => $first->id,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/mobile/admin/tournaments/{$this->tournament->id}/matches");

        $response->assertOk()->assertJsonPath(
            'groups.0.rounds.0.matches.0.team1.players.0.avatar_url',
            'https://padel-p.kz/storage/avatars/a.webp'
        );
    }
}
