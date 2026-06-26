<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function makeCard(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => 'T', 'kind' => 'visits', 'nominal' => 10, 'price' => 100000, 'is_active' => true,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'X', 'phone' => '77770000001']);
        $card = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'A1', 'balance' => 10, 'initial_balance' => 10, 'status' => 'active',
            'expires_at' => '2026-07-01',
        ]);
        return [$club, $admin, $card];
    }

    public function test_admin_can_set_future_expiry(): void
    {
        [$club, $admin, $card] = $this->makeCard();

        $this->actingAs($admin)
            ->put(route('club.cards.updateExpiry', $card), ['expires_at' => '2026-12-31'])
            ->assertRedirect();

        $this->assertEquals('2026-12-31', $card->fresh()->expires_at->toDateString());
    }

    public function test_admin_can_set_past_expiry(): void
    {
        [$club, $admin, $card] = $this->makeCard();

        $this->actingAs($admin)
            ->put(route('club.cards.updateExpiry', $card), ['expires_at' => '2020-01-01'])
            ->assertRedirect();

        $this->assertEquals('2020-01-01', $card->fresh()->expires_at->toDateString());
    }

    public function test_empty_date_makes_card_unlimited(): void
    {
        [$club, $admin, $card] = $this->makeCard();

        $this->actingAs($admin)
            ->put(route('club.cards.updateExpiry', $card), ['expires_at' => ''])
            ->assertRedirect();

        $this->assertNull($card->fresh()->expires_at);
    }

    public function test_admin_of_other_club_cannot_edit(): void
    {
        [$club, $admin, $card] = $this->makeCard();
        $other = User::factory()->create(['role' => 'club_admin']);
        $otherClub = Club::create(['name' => 'Other', 'address' => 'B']);
        $other->adminClubs()->attach($otherClub->id);

        $this->actingAs($other)
            ->put(route('club.cards.updateExpiry', $card), ['expires_at' => '2026-12-31'])
            ->assertForbidden();

        $this->assertEquals('2026-07-01', $card->fresh()->expires_at->toDateString());
    }
}
