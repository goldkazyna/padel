<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Контакты соседей по корту: позвонить и написать в WhatsApp можно прямо
 * из состава — но номера видят только свои, а не любой зритель из ленты.
 */
class GamePlayerContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $member;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $club = Club::factory()->create();
        $this->creator = User::factory()->create(['phone' => '77770001122']);
        $this->member = User::factory()->create([
            'phone' => '77773334455',
            'whatsapp' => '77779998877',
        ]);

        $this->game = Game::factory()->create([
            'creator_id' => $this->creator->id,
            'club_id' => $club->id,
            'capacity' => 4,
            'status' => 'open',
        ]);

        foreach ([$this->creator, $this->member] as $i => $user) {
            GamePlayer::factory()->create([
                'game_id' => $this->game->id,
                'user_id' => $user->id,
                'position' => $i + 1,
                'status' => GamePlayer::STATUS_ACCEPTED,
            ]);
        }
    }

    private function memberPayload(User $viewer): array
    {
        Sanctum::actingAs($viewer);
        $players = $this->getJson("/api/mobile/games/{$this->game->id}")
            ->assertOk()
            ->json('data.players');

        return collect($players)->firstWhere('id', $this->member->id);
    }

    public function test_organizer_sees_contacts(): void
    {
        $player = $this->memberPayload($this->creator);

        $this->assertSame('77773334455', $player['phone']);
        $this->assertSame('77779998877', $player['whatsapp']);
    }

    public function test_whatsapp_falls_back_to_phone(): void
    {
        $this->member->update(['whatsapp' => null]);

        $player = $this->memberPayload($this->creator);

        $this->assertSame('77773334455', $player['whatsapp'], 'пишем на телефон');
    }

    public function test_outsider_sees_no_contacts(): void
    {
        $player = $this->memberPayload(User::factory()->create());

        $this->assertNull($player['phone']);
        $this->assertNull($player['whatsapp']);
    }
}
