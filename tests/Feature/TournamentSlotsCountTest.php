<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Сколько мест занято — на главной, в списке и в карточке.
 *
 * Там, где записываются парой, игроки лежат в командах турнира, а не
 * в участниках: главная показывала 0/12, пока карточка показывала 10/12.
 */
class TournamentSlotsCountTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
    }

    private function pairedJpi(): Tournament
    {
        return Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'just_padel_it',
            'is_paired' => true,
            'pairing_mode' => 'self',
            'status' => 'open',
            'max_participants' => 12,
            'start_date' => now()->addDays(3),
        ]);
    }

    private function addTeam(Tournament $t, string $status): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        TournamentTeam::create([
            'tournament_id' => $t->id,
            'player1_id' => $a->id,
            'player2_id' => $b->id,
            'status' => $status,
        ]);
    }

    public function test_pairs_count_as_two_slots_each(): void
    {
        $t = $this->pairedJpi();
        $this->addTeam($t, 'approved');
        $this->addTeam($t, 'approved');
        $this->addTeam($t, 'pending');

        // Две одобренных и одна на модерации — шесть мест.
        $this->assertSame(6, $t->fresh()->takenSlotsCount());
    }

    /** Отклонённые и лист ожидания места не занимают. */
    public function test_rejected_and_waiting_do_not_take_slots(): void
    {
        $t = $this->pairedJpi();
        $this->addTeam($t, 'approved');
        $this->addTeam($t, 'rejected');
        $this->addTeam($t, 'waiting');

        $this->assertSame(2, $t->fresh()->takenSlotsCount());
    }

    public function test_home_screen_shows_the_same_number_as_the_card(): void
    {
        $t = $this->pairedJpi();
        $this->addTeam($t, 'approved');
        $this->addTeam($t, 'approved');

        Sanctum::actingAs(User::factory()->create());

        $home = $this->getJson('/api/mobile/home')->assertOk();
        $row = collect($home->json('upcoming_tournaments') ?? [])
            ->firstWhere('id', $t->id);

        $this->assertNotNull($row, 'турнир должен быть в ближайших');
        $this->assertSame(4, $row['participants_count'], 'на главной те же 4 из 12');
    }
}
