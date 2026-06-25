<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardTransaction;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private function setup_(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => 'T', 'kind' => 'visits', 'nominal' => 10, 'price' => 100000, 'is_active' => true,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'X', 'phone' => '77770000001']);
        return [$club, $admin, $type, $client];
    }

    public function test_fresh_card_without_history_can_be_deleted(): void
    {
        [$club, $admin, $type, $client] = $this->setup_();
        $card = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'A1', 'balance' => 10, 'initial_balance' => 10, 'status' => 'active',
        ]);

        $this->actingAs($admin)->delete(route('club.cards.destroy', $card))->assertRedirect();
        $this->assertDatabaseMissing('club_cards', ['id' => $card->id]);
    }

    public function test_card_with_transactions_cannot_be_deleted(): void
    {
        [$club, $admin, $type, $client] = $this->setup_();
        $card = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'A2', 'balance' => 8, 'initial_balance' => 10, 'status' => 'active',
        ]);
        ClubCardTransaction::create([
            'club_id' => $club->id, 'club_card_id' => $card->id, 'amount' => -2, 'balance_after' => 8,
        ]);

        $this->actingAs($admin)->delete(route('club.cards.destroy', $card))->assertSessionHas('error');
        $this->assertDatabaseHas('club_cards', ['id' => $card->id]);
    }

    public function test_used_up_card_cannot_be_deleted(): void
    {
        [$club, $admin, $type, $client] = $this->setup_();
        $card = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'A3', 'balance' => 0, 'initial_balance' => 10, 'status' => 'active',
        ]);

        $this->actingAs($admin)->delete(route('club.cards.destroy', $card))->assertSessionHas('error');
        $this->assertDatabaseHas('club_cards', ['id' => $card->id]);
    }
}
