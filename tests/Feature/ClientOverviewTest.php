<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupEnrollment;
use App\Models\ClubGroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'C', 'address' => 'A']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function client(string $name): ClubClient
    {
        return ClubClient::create([
            'club_id' => $this->club->id, 'name' => $name, 'phone' => (string) random_int(70000000000, 79999999999),
        ]);
    }

    private function card(ClubClient $c, int $balance, ?string $expires): ClubCard
    {
        $type = ClubCardType::create([
            'club_id' => $this->club->id, 'name' => 'Визиты', 'kind' => 'visits',
            'nominal' => 10, 'is_active' => true,
        ]);
        return ClubCard::create([
            'club_id' => $this->club->id, 'club_card_type_id' => $type->id,
            'club_client_id' => $c->id, 'code' => 'C' . random_int(1000, 999999),
            'balance' => $balance, 'initial_balance' => 10, 'expires_at' => $expires,
            'status' => 'active',
        ]);
    }

    private function names(array $rows): array
    {
        return collect($rows)->pluck('client_name')->all();
    }

    public function test_cards_overview_ending_and_ended_buckets(): void
    {
        $a = $this->client('Алиса');   // low balance 2 → ending
        $b = $this->client('Борис');   // balance 0 → ended (used)
        $cc = $this->client('Виктор'); // soon expiry → ending
        $d = $this->client('Галина');  // healthy → neither
        $e = $this->client('Дамир');   // expired → ended

        $this->card($a, 2, now()->addDays(30)->toDateString());
        $this->card($b, 0, null);
        $this->card($cc, 8, now()->addDays(3)->toDateString());
        $this->card($d, 8, now()->addDays(30)->toDateString());
        $this->card($e, 5, now()->subDay()->toDateString());

        $this->actingAs($this->admin);

        $ending = $this->get('/club/clients/cards?f=ending')->assertOk()->viewData('rows');
        $this->assertEqualsCanonicalizing(['Алиса', 'Виктор'], $this->names($ending));

        $ended = $this->get('/club/clients/cards?f=ended')->assertOk()->viewData('rows');
        $this->assertEqualsCanonicalizing(['Борис', 'Дамир'], $this->names($ended));
    }

    public function test_archive_card_removes_from_ended_and_shows_in_archive(): void
    {
        $client = $this->client('Ерлан');
        $card = $this->card($client, 0, null); // used → ended

        $this->actingAs($this->admin);

        // Изначально — в «Закончились».
        $ended = $this->get('/club/clients/cards?f=ended')->assertOk()->viewData('rows');
        $this->assertContains('Ерлан', $this->names($ended));

        // Архивируем.
        $this->post("/club/clients/cards/{$card->id}/archive")->assertRedirect();
        $this->assertSame('archived', $card->fresh()->status);

        // Ушла из «Закончились», появилась в «Архиве».
        $ended2 = $this->get('/club/clients/cards?f=ended')->assertOk()->viewData('rows');
        $this->assertNotContains('Ерлан', $this->names($ended2));
        $archive = $this->get('/club/clients/cards?f=archive')->assertOk()->viewData('rows');
        $this->assertContains('Ерлан', $this->names($archive));

        // Возврат из архива.
        $this->post("/club/clients/cards/{$card->id}/archive")->assertRedirect();
        $this->assertSame('active', $card->fresh()->status);
    }

    public function test_groups_overview_ending_and_ended_buckets(): void
    {
        $group = ClubGroup::create([
            'club_id' => $this->club->id, 'name' => 'Понедельник', 'price_per_session' => 5000,
            'status' => 'active',
        ]);

        $mk = function (string $name, int $sessions) use ($group) {
            $client = $this->client($name);
            $m = ClubGroupMember::create([
                'group_id' => $group->id, 'client_id' => $client->id, 'status' => 'active',
            ]);
            ClubGroupEnrollment::create([
                'group_member_id' => $m->id, 'sessions' => $sessions, 'amount' => 0,
                'is_paid' => true, 'created_by' => $this->admin->id,
            ]);
            return $client;
        };

        $mk('Ольга', 2);   // remaining 2 → ending
        $mk('Пётр', 0);    // remaining 0 → ended
        $mk('Рита', 5);    // remaining 5 → neither

        $this->actingAs($this->admin);

        $ending = $this->get('/club/clients/groups?f=ending')->assertOk()->viewData('rows');
        $this->assertEqualsCanonicalizing(['Ольга'], $this->names($ending));

        $ended = $this->get('/club/clients/groups?f=ended')->assertOk()->viewData('rows');
        $this->assertEqualsCanonicalizing(['Пётр'], $this->names($ended));
    }
}
