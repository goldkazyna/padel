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

class ClubCardTypeTest extends TestCase
{
    use RefreshDatabase;

    private function adminClub(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        return [$club, $admin];
    }

    public function test_generate_code_is_unique_8_chars(): void
    {
        $svc = new ClubCardService();
        $code = $svc->generateCode();
        $this->assertSame(8, strlen($code));
    }

    public function test_admin_creates_card_type(): void
    {
        [$club, $admin] = $this->adminClub();

        $this->actingAs($admin)->post(route('club.cardTypes.store'), [
            'name' => '10 посещений',
            'kind' => 'visits',
            'nominal' => 10,
        ])->assertRedirect();

        $t = ClubCardType::where('club_id', $club->id)->first();
        $this->assertNotNull($t);
        $this->assertSame('visits', $t->kind);
        $this->assertSame(10, (int) $t->nominal);
        $this->assertNull($t->discount_percent); // очищается для счётчика
    }

    public function test_discount_type_clears_nominal(): void
    {
        [$club, $admin] = $this->adminClub();

        $this->actingAs($admin)->post(route('club.cardTypes.store'), [
            'name' => 'VIP −15%',
            'kind' => 'discount_court',
            'discount_percent' => 15,
        ])->assertRedirect();

        $t = ClubCardType::where('club_id', $club->id)->first();
        $this->assertSame(15, (int) $t->discount_percent);
        $this->assertNull($t->nominal);
    }

    public function test_price_and_date_validity_stored(): void
    {
        [$club, $admin] = $this->adminClub();

        $this->actingAs($admin)->post(route('club.cardTypes.store'), [
            'name' => 'Сезонная',
            'kind' => 'visits',
            'nominal' => 10,
            'price' => 50000,
            'validity_mode' => 'date',
            'default_expires_at' => now()->addMonths(3)->toDateString(),
            'default_validity_days' => 30, // должно очиститься режимом date
        ])->assertRedirect();

        $t = ClubCardType::where('club_id', $club->id)->first();
        $this->assertSame(50000, (int) $t->price);
        $this->assertNotNull($t->default_expires_at);
        $this->assertNull($t->default_validity_days, 'режим date очищает дни');
    }

    public function test_days_validity_clears_date(): void
    {
        [$club, $admin] = $this->adminClub();

        $this->actingAs($admin)->post(route('club.cardTypes.store'), [
            'name' => 'Месячная',
            'kind' => 'trainer',
            'nominal' => 8,
            'validity_mode' => 'days',
            'default_validity_days' => 30,
            'default_expires_at' => now()->addMonths(3)->toDateString(), // должно очиститься
        ])->assertRedirect();

        $t = ClubCardType::where('club_id', $club->id)->first();
        $this->assertSame(30, (int) $t->default_validity_days);
        $this->assertNull($t->default_expires_at, 'режим days очищает дату');
    }

    public function test_cannot_delete_type_with_active_card(): void
    {
        [$club, $admin] = $this->adminClub();
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => 'T', 'kind' => 'visits', 'nominal' => 10,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван', 'phone' => '+770000000']);
        ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'TESTCODE', 'balance' => 10, 'initial_balance' => 10, 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('club.cardTypes.destroy', $type))
            ->assertRedirect();

        // Тип не удалён — есть активная карта.
        $this->assertNotNull(ClubCardType::find($type->id));
    }

    public function test_card_is_actual_logic(): void
    {
        [$club] = $this->adminClub();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'T', 'kind' => 'visits', 'nominal' => 10]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'И', 'phone' => '+770000001']);

        $active = ClubCard::create(['club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id, 'code' => 'A1', 'balance' => 5, 'initial_balance' => 10, 'status' => 'active']);
        $zero = ClubCard::create(['club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id, 'code' => 'A2', 'balance' => 0, 'initial_balance' => 10, 'status' => 'active']);
        $expired = ClubCard::create(['club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id, 'code' => 'A3', 'balance' => 5, 'initial_balance' => 10, 'status' => 'active', 'expires_at' => now()->subDay()->toDateString()]);

        $this->assertTrue($active->fresh()->isActual());
        $this->assertFalse($zero->fresh()->isActual(), 'нулевой баланс — не актуальна');
        $this->assertFalse($expired->fresh()->isActual(), 'истёкшая — не актуальна');
    }
}
