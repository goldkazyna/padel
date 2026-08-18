<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\JustPadelItPair;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Готовые пары в «Just Padel It» с фиксированными парами.
 *
 * Единица записи здесь — пара, а не игрок: организатор заводит сразу обоих.
 */
class JpiAdminPairsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function tournament(array $over = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'club_id' => $this->club->id,
            'type' => 'just_padel_it',
            'is_paired' => true,
            'pairing_mode' => 'admin',
            'status' => 'open',
            'max_participants' => 8,
        ], $over));
    }

    public function test_admin_adds_a_ready_pair(): void
    {
        $t = $this->tournament();
        [$a, $b] = User::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.pairs.add', $t), [
                'player1_id' => $a->id,
                'player2_id' => $b->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, JustPadelItPair::where('tournament_id', $t->id)->count());
        // Оба игрока записаны на турнир.
        $this->assertSame(2, $t->participants()->count());
    }

    /** Игрок мог записаться сам — повторно привязывать его нельзя. */
    public function test_already_registered_player_is_not_attached_twice(): void
    {
        $t = $this->tournament();
        [$a, $b] = User::factory()->count(2)->create();
        $t->participants()->attach($a->id, ['status' => 'registered']);

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.pairs.add', $t), [
                'player1_id' => $a->id,
                'player2_id' => $b->id,
            ])
            ->assertRedirect();

        $this->assertSame(2, $t->participants()->count());
        $this->assertSame(1, JustPadelItPair::where('tournament_id', $t->id)->count());
    }

    public function test_player_cannot_be_in_two_pairs(): void
    {
        $t = $this->tournament();
        [$a, $b, $c] = User::factory()->count(3)->create();

        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ])->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.pairs.add', $t), [
                'player1_id' => $a->id, 'player2_id' => $c->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, JustPadelItPair::where('tournament_id', $t->id)->count());
    }

    public function test_pair_does_not_fit_over_the_limit(): void
    {
        $t = $this->tournament(['max_participants' => 2]);
        $players = User::factory()->count(4)->create();

        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $players[0]->id, 'player2_id' => $players[1]->id,
        ])->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.pairs.add', $t), [
                'player1_id' => $players[2]->id, 'player2_id' => $players[3]->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, JustPadelItPair::where('tournament_id', $t->id)->count());
    }

    /** Разбиваем пару — игроки остаются в списке участников. */
    public function test_breaking_a_pair_keeps_the_players(): void
    {
        $t = $this->tournament();
        [$a, $b] = User::factory()->count(2)->create();
        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ]);
        $pair = JustPadelItPair::where('tournament_id', $t->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('club.tournaments.pairs.remove', [$t, $pair]))
            ->assertRedirect();

        $this->assertSame(0, JustPadelItPair::where('tournament_id', $t->id)->count());
        $this->assertSame(2, $t->participants()->count());
    }

    /**
     * Когда пары собирают сами игроки, пара ложится в команды турнира.
     *
     * Там уже есть модерация, лист ожидания и вывод в приложении — заводить
     * ей вторую жизнь в парах формата незачем.
     */
    public function test_self_pairing_pair_goes_to_teams(): void
    {
        $t = $this->tournament(['pairing_mode' => 'self']);
        [$a, $b] = User::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.pairs.add', $t), [
                'player1_id' => $a->id, 'player2_id' => $b->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, $t->teams()->count());
        $this->assertSame(0, JustPadelItPair::where('tournament_id', $t->id)->count());
    }

    /**
     * Непарный участник не должен пройти в старт.
     *
     * Посев раскладывает по кортам именно пары — лишний участник молча
     * остался бы без игры.
     */
    public function test_start_refuses_while_someone_is_without_a_pair(): void
    {
        $t = $this->tournament(['max_participants' => 12, 'courts_count' => 2]);
        $players = User::factory()->count(12)->create();

        // Четыре пары — восемь человек.
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
                'player1_id' => $players[$i * 2]->id,
                'player2_id' => $players[$i * 2 + 1]->id,
            ]);
        }
        // И ещё четверо записались сами, без пар.
        foreach ([8, 9, 10, 11] as $i) {
            $t->participants()->attach($players[$i]->id, ['status' => 'registered']);
        }

        $service = app(\App\Services\JustPadelItService::class);

        $this->assertSame(12, $t->participants()->wherePivot('status', 'registered')->count());
        $this->assertFalse(
            $service->startTournament($t->fresh()),
            'старт с непарными участниками недопустим'
        );

        // Спарили оставшихся — теперь стартует.
        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $players[8]->id, 'player2_id' => $players[9]->id,
        ]);
        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $players[10]->id, 'player2_id' => $players[11]->id,
        ]);

        $this->assertTrue($service->startTournament($t->fresh()));
    }

    public function test_form_shows_only_for_admin_paired_jpi(): void
    {
        $admin = $this->tournament();
        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $admin))
            ->assertOk()
            ->assertSee('Добавить пару')
            ->assertSee(route('club.tournaments.pairs.add', $admin), false);

        $solo = $this->tournament(['is_paired' => false]);
        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $solo))
            ->assertOk()
            ->assertDontSee('Добавить пару');
    }

    /**
     * Записавшегося в одиночку надо уметь найти для пары.
     *
     * Обычный поиск исключает уже записанных — иначе создателя турнира
     * или солиста не с кем спарить.
     */
    public function test_pair_search_finds_an_already_registered_player(): void
    {
        $t = $this->tournament();
        $solo = User::factory()->create(['name' => 'Mister Bekson']);
        $t->participants()->attach($solo->id, ['status' => 'registered']);

        // Обычный поиск его прячет.
        $this->actingAs($this->admin)
            ->getJson(route('club.tournaments.searchPlayers', $t) . '?q=Bekson')
            ->assertOk()
            ->assertJsonCount(0);

        // Поиск для пары — находит.
        $this->actingAs($this->admin)
            ->getJson(route('club.tournaments.searchPlayers', $t) . '?q=Bekson&for=pair')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Mister Bekson');
    }

    /** А вот того, кто уже в паре, показывать не надо. */
    public function test_pair_search_hides_someone_already_paired(): void
    {
        $t = $this->tournament();
        $a = User::factory()->create(['name' => 'Mister Bekson']);
        $b = User::factory()->create();

        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('club.tournaments.searchPlayers', $t) . '?q=Bekson&for=pair')
            ->assertOk()
            ->assertJsonCount(0);
    }

    /** То же самое, когда пары собирают сами игроки: пара живёт в командах. */
    public function test_pair_search_hides_someone_already_in_a_team(): void
    {
        $t = $this->tournament(['pairing_mode' => 'self']);
        $a = User::factory()->create(['name' => 'Mister Bekson']);
        $b = User::factory()->create();

        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('club.tournaments.searchPlayers', $t) . '?q=Bekson&for=pair')
            ->assertOk()
            ->assertJsonCount(0);
    }

    /**
     * Записанного в одиночку до парной записи надо уметь убрать.
     *
     * Список участников в парных турнирах не показывается, а старт с непарными
     * не пустит — без этого блока турнир было бы не запустить.
     */
    public function test_unpaired_player_is_visible_and_removable(): void
    {
        $t = $this->tournament(['pairing_mode' => 'self']);
        $solo = User::factory()->create(['name' => 'Mister Bekson']);
        $t->participants()->attach($solo->id, ['status' => 'registered']);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $t))
            ->assertOk()
            ->assertSee('Без пары')
            ->assertSee('Mister Bekson');

        $this->actingAs($this->admin)
            ->delete(route('club.tournaments.participants.remove', [$t, $solo->id]))
            ->assertRedirect();

        $this->assertSame(0, $t->participants()->count());
    }

    /** Когда все в парах, блок «Без пары» не мозолит глаза. */
    public function test_no_unpaired_block_when_everyone_is_paired(): void
    {
        $t = $this->tournament(['pairing_mode' => 'self']);
        [$a, $b] = User::factory()->count(2)->create();
        $this->actingAs($this->admin)->post(route('club.tournaments.pairs.add', $t), [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $t))
            ->assertOk()
            ->assertDontSee('Без пары');
    }

    /** Форма пары названия не шлёт — оно для JPI бессмысленно. */
    public function test_pair_is_added_without_a_name(): void
    {
        $t = $this->tournament(['pairing_mode' => 'self']);
        [$a, $b] = User::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.pairs.add', $t), [
                'player1_id' => $a->id,
                'player2_id' => $b->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, $t->teams()->count());
    }

    /**
     * Когда пары собирают сами игроки, организатор всё равно должен уметь
     * завести пару — через команды турнира, как в групповом.
     */
    public function test_self_pairing_page_offers_a_pair_form_not_a_participant_one(): void
    {
        $t = $this->tournament(['pairing_mode' => 'self']);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $t))
            ->assertOk()
            ->assertSee('Добавить пару')
            ->assertSee(route('club.tournaments.pairs.add', $t), false)
            // Поодиночке в парный турнир не записывают.
            ->assertDontSee('Добавить участника');
    }
}
