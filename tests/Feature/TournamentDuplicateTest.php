<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Дублирование турнира: форма создания открывается уже заполненной.
 */
class TournamentDuplicateTest extends TestCase
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

    private function source(array $over = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'club_id' => $this->club->id,
            'name' => 'Субботний Американо',
            'type' => 'americano_flex',
            'status' => 'completed',
            'start_date' => '2026-08-15 19:00:00',
            'max_participants' => 12,
            'price' => 19000,
            'courts_count' => 2,
            'min_level' => 3.0,
            'max_level' => 5.75,
        ], $over));
    }

    public function test_form_opens_prefilled(): void
    {
        $source = $this->source();

        $response = $this->actingAs($this->admin)
            ->get(route('club.tournaments.create', ['from' => $source->id]))
            ->assertOk();

        // Значения подставляются через old-input — форма читает их сама.
        $response->assertSee('Субботний Американо');
        $response->assertSee('value="19000"', false);
        $response->assertSee('value="12"', false);
        $response->assertSee('Поля заполнены из турнира');
    }

    /** Дату переносить нельзя — ради её выбора всё и затевалось. */
    public function test_date_is_not_carried_over(): void
    {
        $source = $this->source();

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.create', ['from' => $source->id]))
            ->assertOk()
            ->assertDontSee('2026-08-15', false);
    }

    /** Статус тоже не переносится: копия начинается с чистого листа. */
    public function test_status_is_not_carried_over(): void
    {
        $source = $this->source(['status' => 'completed']);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.create', ['from' => $source->id]))
            ->assertOk();

        $this->assertNull(session('_old_input.status'));
    }

    /** Чужой турнир не подставляется — иначе через адрес видно чужие настройки. */
    public function test_foreign_tournament_is_ignored(): void
    {
        $other = Club::create(['name' => 'Чужой', 'address' => 'Б']);
        $foreign = Tournament::factory()->create([
            'club_id' => $other->id,
            'name' => 'Чужой турнир',
        ]);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.create', ['from' => $foreign->id]))
            ->assertOk()
            ->assertDontSee('Чужой турнир')
            ->assertDontSee('Поля заполнены из турнира');
    }

    public function test_plain_create_form_still_opens(): void
    {
        $this->actingAs($this->admin)
            ->get(route('club.tournaments.create'))
            ->assertOk()
            ->assertDontSee('Поля заполнены из турнира');
    }

    public function test_list_shows_the_duplicate_button(): void
    {
        $source = $this->source(['status' => 'open']);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.index'))
            ->assertOk()
            ->assertSee(route('club.tournaments.create', ['from' => $source->id]), false)
            ->assertSee('Дублировать турнир');
    }
}
