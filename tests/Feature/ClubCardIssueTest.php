<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\User;
use App\Services\ClubCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardIssueTest extends TestCase
{
    use RefreshDatabase;

    private function setup_club(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван', 'phone' => '+770000001']);
        return [$club, $admin, $client];
    }

    public function test_issue_counter_from_nominal(): void
    {
        [$club, , $client] = $this->setup_club();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 пос.', 'kind' => 'visits', 'nominal' => 10]);

        $card = (new ClubCardService())->issue($client, $type);

        $this->assertSame(10, $card->balance);
        $this->assertSame(10, $card->initial_balance);
        $this->assertSame(8, strlen($card->code));
        $this->assertSame('active', $card->status);
        $this->assertSame($client->id, $card->club_client_id);
        $this->assertNull($card->expires_at, 'бессрочно без срока в типе');
    }

    public function test_issue_with_balance_override(): void
    {
        [$club, , $client] = $this->setup_club();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'T', 'kind' => 'trainer', 'nominal' => 8]);

        $card = (new ClubCardService())->issue($client, $type, 3);

        $this->assertSame(3, $card->balance, 'override остатка');
        $this->assertSame(3, $card->initial_balance);
    }

    public function test_issue_discount_has_no_balance(): void
    {
        [$club, , $client] = $this->setup_club();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'VIP', 'kind' => 'discount_court', 'discount_percent' => 20]);

        $card = (new ClubCardService())->issue($client, $type);

        $this->assertNull($card->balance);
        $this->assertNull($card->initial_balance);
    }

    public function test_issue_expiry_from_validity_days(): void
    {
        [$club, , $client] = $this->setup_club();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'M', 'kind' => 'visits', 'nominal' => 4, 'default_validity_days' => 30]);

        $card = (new ClubCardService())->issue($client, $type);

        $this->assertNotNull($card->expires_at);
        $this->assertSame(now()->addDays(30)->toDateString(), $card->expires_at->toDateString());
    }

    public function test_admin_issues_card_via_route(): void
    {
        [$club, $admin, $client] = $this->setup_club();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'T', 'kind' => 'visits', 'nominal' => 5]);

        $this->actingAs($admin)->post(route('club.cards.issue'), [
            'club_client_id' => $client->id,
            'club_card_type_id' => $type->id,
        ])->assertRedirect();

        $card = ClubCard::where('club_client_id', $client->id)->first();
        $this->assertNotNull($card);
        $this->assertSame(5, $card->balance);
    }

    public function test_admin_unlinks_card(): void
    {
        [$club, $admin, $client] = $this->setup_club();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'T', 'kind' => 'visits', 'nominal' => 5]);
        $card = (new ClubCardService())->issue($client, $type);

        $this->actingAs($admin)->delete(route('club.cards.destroy', $card))->assertRedirect();

        $this->assertNull(ClubCard::find($card->id));
    }
}
