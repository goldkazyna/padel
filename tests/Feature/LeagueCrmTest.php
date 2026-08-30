<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Раздел «Лиги» в CRM: создание, этапы, состав.
 *
 * Этап лиги — обычный турнир Americano Flex, поэтому проводится и судится
 * существующими экранами; здесь проверяем только саму лигу.
 */
class LeagueCrmTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function league(array $attrs = []): League
    {
        return League::create(array_merge([
            'club_id' => $this->club->id,
            'name' => 'Сентябрь Кап',
            'status' => 'open',
            'stages_planned' => 8,
            'max_players' => 12,
        ], $attrs));
    }

    public function test_лига_создаётся_из_формы(): void
    {
        $this->actingAs($this->admin)->post(route('club.leagues.store'), [
            'name' => 'Сентябрь Кап',
            'stages_planned' => 8,
            'max_players' => 12,
            'price' => 5000,
            'is_rated' => 1,
        ])->assertRedirect();

        $league = League::first();
        $this->assertSame('Сентябрь Кап', $league->name);
        $this->assertSame(8, $league->stages_planned);
        $this->assertSame('open', $league->status, 'сразу открыта на запись');
        $this->assertSame($this->club->id, $league->club_id);
    }

    public function test_уровень_можно_не_указывать(): void
    {
        // В форме уровень выбирают из списка, и «Не важно» приходит пустым.
        $this->actingAs($this->admin)->post(route('club.leagues.store'), [
            'name' => 'Открытая лига',
            'stages_planned' => 8,
            'min_level' => '',
            'max_level' => '',
        ])->assertRedirect();

        $league = League::first();
        $this->assertNull($league->min_level);
        $this->assertNull($league->max_level);
    }

    public function test_уровень_сохраняется_из_списка(): void
    {
        $this->actingAs($this->admin)->post(route('club.leagues.store'), [
            'name' => 'Лига 3.0–4.0',
            'stages_planned' => 8,
            'min_level' => '3.00',
            'max_level' => '4.00',
        ])->assertRedirect();

        $league = League::first();
        $this->assertSame('3.00', (string) $league->min_level);
        $this->assertSame('4.00', (string) $league->max_level);
    }

    public function test_этап_создаётся_турниром_americano_flex(): void
    {
        $league = $this->league();

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
        ])->assertRedirect();

        $stage = Tournament::first();
        $this->assertSame('americano_flex', $stage->type);
        $this->assertSame($league->id, $stage->league_id);
        $this->assertSame(1, (int) $stage->league_stage);
        $this->assertSame('Сентябрь Кап — этап 1', $stage->name);
        $this->assertSame('in_progress', $league->fresh()->status, 'первый этап запускает лигу');
    }

    public function test_парность_этапа_переопределяет_лигу(): void
    {
        // Обычно вся лига играется одинаково, но конкретный вечер можно
        // собрать иначе — галочка в форме этапа решает.
        $league = $this->league(['is_paired' => false]);

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
            'is_paired' => 1,
        ])->assertRedirect();

        $this->assertTrue((bool) Tournament::first()->is_paired);
    }

    public function test_снятая_галочка_делает_этап_одиночным(): void
    {
        $league = $this->league(['is_paired' => true]);

        // В форме рядом с галочкой лежит скрытый ноль — иначе снятая галочка
        // не отличалась бы от «поле не пришло» и этап остался бы парным.
        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
            'is_paired' => 0,
        ])->assertRedirect();

        $this->assertFalse((bool) Tournament::first()->is_paired);
    }

    public function test_несыгранный_этап_удаляется_и_номера_сдвигаются(): void
    {
        $league = $this->league();

        foreach (['2026-09-05 19:00', '2026-09-12 19:00', '2026-09-19 19:00'] as $date) {
            $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
                'start_date' => $date,
                'max_participants' => 12,
            ]);
        }

        $second = Tournament::where('league_stage', 2)->first();

        $this->actingAs($this->admin)
            ->delete(route('club.leagues.stages.remove', [$league, $second]))
            ->assertRedirect();

        $this->assertNull(Tournament::find($second->id), 'этап удалён');

        // Иначе остались бы этапы 1 и 3, и «этап 3 из 8» не совпал бы со списком.
        $stages = Tournament::orderBy('league_stage')->get();
        $this->assertSame([1, 2], $stages->pluck('league_stage')->map(fn ($n) => (int) $n)->all());
        $this->assertSame('Сентябрь Кап — этап 2', $stages->last()->name, 'название переехало вместе с номером');
    }

    public function test_завершённый_этап_удалить_нельзя(): void
    {
        $league = $this->league();

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
        ]);

        $stage = Tournament::first();
        $stage->update(['status' => 'completed']);

        // Очки сыгранного этапа уже стоят в таблице лиги — удаление переписало бы историю.
        $this->actingAs($this->admin)
            ->delete(route('club.leagues.stages.remove', [$league, $stage]))
            ->assertSessionHas('error');

        $this->assertNotNull(Tournament::find($stage->id));
    }

    public function test_чужой_этап_не_удаляется_через_лигу(): void
    {
        $league = $this->league();
        $other = $this->league(['name' => 'Другая лига']);

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $other), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
        ]);

        $stage = Tournament::first();

        $this->actingAs($this->admin)
            ->delete(route('club.leagues.stages.remove', [$league, $stage]))
            ->assertNotFound();

        $this->assertNotNull(Tournament::find($stage->id));
    }

    public function test_формат_этапов_берётся_из_настроек_лиги(): void
    {
        // Все этапы играются одинаково, поэтому формат задаётся один раз.
        $league = $this->league([
            'is_paired' => true,
            'courts_count' => 3,
            'duration_hours' => 2,
            'points_to_win' => 24,
            'verified_only' => true,
            'chat_enabled' => false,
            'is_rated' => false,
        ]);

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
        ])->assertRedirect();

        $stage = Tournament::first();
        $this->assertTrue((bool) $stage->is_paired, 'парная лига — парные этапы');
        $this->assertSame(3, (int) $stage->courts_count);
        $this->assertSame(2, (int) $stage->duration_hours);
        $this->assertSame(24, (int) $stage->points_to_win);
        $this->assertTrue((bool) $stage->verified_only);
        $this->assertFalse((bool) $stage->chat_enabled);
        $this->assertFalse((bool) $stage->is_rated);
    }

    public function test_кортов_можно_переопределить_на_этапе(): void
    {
        $league = $this->league(['courts_count' => 2]);

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 16,
            'courts_count' => 4,
        ])->assertRedirect();

        $this->assertSame(4, (int) Tournament::first()->courts_count,
            'на конкретный вечер кортов может быть больше');
    }

    public function test_состав_лиги_попадает_в_новый_этап(): void
    {
        $league = $this->league();
        $players = User::factory()->count(3)->create(['role' => 'player', 'level' => 3.0]);
        foreach ($players as $player) {
            LeaguePlayer::create([
                'league_id' => $league->id, 'user_id' => $player->id,
                'status' => 'registered', 'joined_at' => now(),
            ]);
        }

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
        ])->assertRedirect();

        $stage = Tournament::first();
        $this->assertSame(3, $stage->participants()->count(),
            'записывались в лигу, а не в каждый этап отдельно');
    }

    public function test_номера_этапов_идут_по_порядку(): void
    {
        $league = $this->league();

        foreach (['2026-09-05 19:00', '2026-09-12 19:00'] as $date) {
            $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
                'start_date' => $date,
                'max_participants' => 12,
            ])->assertRedirect();
        }

        $this->assertSame([1, 2], Tournament::orderBy('id')->pluck('league_stage')->map(fn ($n) => (int) $n)->all());
    }

    public function test_игрок_добавляется_и_убирается_из_состава(): void
    {
        $league = $this->league();
        $player = User::factory()->create(['role' => 'player', 'level' => 3.0]);

        $this->actingAs($this->admin)
            ->post(route('club.leagues.players.add', $league), ['user_id' => $player->id])
            ->assertRedirect();

        $this->assertSame(1, $league->activePlayers()->count());

        $this->actingAs($this->admin)
            ->delete(route('club.leagues.players.remove', [$league, $player]))
            ->assertRedirect();

        $this->assertSame(0, $league->activePlayers()->count());
        $this->assertSame('left', LeaguePlayer::first()->status, 'запись остаётся, чтобы история не переписывалась');
    }

    public function test_поиск_игроков_умный_и_без_тех_кто_уже_в_лиге(): void
    {
        $league = $this->league();
        $inside = User::factory()->create(['role' => 'player', 'name' => 'Денис Дудников', 'level' => 3.0]);
        User::factory()->create(['role' => 'player', 'name' => 'Denis Dudnikov', 'level' => 3.0]);

        LeaguePlayer::create([
            'league_id' => $league->id, 'user_id' => $inside->id,
            'status' => 'registered', 'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('club.leagues.players.search', $league) . '?q=Дудников')
            ->assertOk();

        $names = collect($response->json('players'))->pluck('name')->all();

        $this->assertSame(['Denis Dudnikov'], $names,
            'латиница находится по кириллице, а тот, кто уже в составе, не предлагается');
    }

    public function test_состав_заполняется_тестовыми_игроками(): void
    {
        $league = $this->league(['max_players' => 4]);

        // Тестовые аккаунты — 1@gmail.com … 32@gmail.com, как в турнирах.
        foreach ([1, 2, 3, 4, 5] as $n) {
            User::factory()->create([
                'role' => 'player', 'email' => "{$n}@gmail.com", 'level' => 3.0,
            ]);
        }
        // Живой игрок с «числовым» телефоном в почте — в тестовые не попадает.
        User::factory()->create([
            'role' => 'player', 'email' => '77771112233@gmail.com', 'level' => 3.0,
        ]);

        $this->actingAs($this->admin)
            ->post(route('club.leagues.players.test', $league))
            ->assertRedirect();

        $this->assertSame(4, $league->activePlayers()->count(), 'заполнили ровно до лимита');

        $emails = User::whereIn('id', $league->activePlayers()->pluck('user_id'))
            ->pluck('email')->sort()->values()->all();
        $this->assertSame(['1@gmail.com', '2@gmail.com', '3@gmail.com', '4@gmail.com'], $emails);
    }

    public function test_повторное_заполнение_упирается_в_лимит(): void
    {
        $league = $this->league(['max_players' => 2]);
        foreach ([1, 2] as $n) {
            User::factory()->create(['role' => 'player', 'email' => "{$n}@gmail.com", 'level' => 3.0]);
        }

        $this->actingAs($this->admin)->post(route('club.leagues.players.test', $league));
        $this->actingAs($this->admin)
            ->post(route('club.leagues.players.test', $league))
            ->assertSessionHas('error');

        $this->assertSame(2, $league->activePlayers()->count());
    }

    public function test_чужая_лига_недоступна(): void
    {
        $otherClub = Club::create(['name' => 'Другой', 'address' => 'Б', 'city' => 'Астана']);
        $league = $this->league(['club_id' => $otherClub->id]);

        $this->actingAs($this->admin)->get(route('club.leagues.show', $league))->assertForbidden();
    }

    public function test_страница_лиги_показывает_этапы_и_состав(): void
    {
        $league = $this->league();
        $player = User::factory()->create(['role' => 'player', 'name' => 'Ерлан Игрок', 'level' => 3.0]);
        LeaguePlayer::create([
            'league_id' => $league->id, 'user_id' => $player->id,
            'status' => 'registered', 'joined_at' => now(),
        ]);
        Tournament::create([
            'club_id' => $this->club->id, 'league_id' => $league->id, 'league_stage' => 1,
            'name' => 'Этап 1', 'type' => 'americano_flex', 'status' => 'open',
            'start_date' => '2026-09-05 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 12,
        ]);

        $this->actingAs($this->admin)->get(route('club.leagues.show', $league))
            ->assertOk()
            ->assertSee('Сентябрь Кап')
            ->assertSee('Этап 1')
            ->assertSee('Ерлан Игрок')
            // Буквы формата — из date(): MMMM повторял бы месяц трижды, HH:mm — часы и минуты.
            ->assertSee('5 сентября, 19:00')
            ->assertSee('Она появится, когда завершится первый этап лиги.');
    }

    public function test_у_несыгранного_этапа_есть_кнопка_удаления(): void
    {
        $league = $this->league();
        $stage = Tournament::create([
            'club_id' => $this->club->id, 'league_id' => $league->id, 'league_stage' => 1,
            'name' => 'Этап 1', 'type' => 'americano_flex', 'status' => 'open',
            'start_date' => '2026-09-05 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 12,
        ]);

        $url = route('club.leagues.stages.remove', [$league, $stage], false);

        $this->actingAs($this->admin)->get(route('club.leagues.show', $league))
            ->assertOk()
            ->assertSee($url);

        $stage->update(['status' => 'completed']);

        $this->actingAs($this->admin)->get(route('club.leagues.show', $league))
            ->assertOk()
            ->assertDontSee($url, 'у сыгранного этапа удалять нечего — очки уже в таблице');
    }
}
