<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileCertificatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_client_certificates_across_clubs_with_status_and_design(): void
    {
        $user = User::factory()->create(['phone' => '+7 777 433 38 22']);
        Sanctum::actingAs($user);

        // Два клуба, клиент с тем же телефоном в каждом.
        $clubA = Club::create(['name' => 'Padel Hills', 'address' => 'A', 'city' => 'Алматы']);
        $clubB = Club::create(['name' => 'DAVAY PADEL', 'address' => 'B', 'city' => 'Астана']);
        $clientA = ClubClient::create(['club_id' => $clubA->id, 'name' => 'Денис', 'phone' => '77774333822']);
        $clientB = ClubClient::create(['club_id' => $clubB->id, 'name' => 'Денис', 'phone' => '+77774333822']);

        // Активный (часы) в A, использованный (сумма) в A, активный (турнир) в B.
        Certificate::create([
            'club_id' => $clubA->id, 'client_id' => $clientA->id, 'type' => 'named',
            'recipient_name' => 'Денис', 'value_type' => 'hours', 'hours' => 2,
            'number' => Certificate::generateNumber($clubA->id),
        ]);
        Certificate::create([
            'club_id' => $clubA->id, 'client_id' => $clientA->id, 'type' => 'generic',
            'value_type' => 'amount', 'amount' => 5000, 'used_at' => now(),
            'number' => Certificate::generateNumber($clubA->id),
        ]);
        Certificate::create([
            'club_id' => $clubB->id, 'client_id' => $clientB->id, 'type' => 'named',
            'recipient_name' => 'Денис', 'value_type' => 'tournament', 'tournaments' => 1,
            'number' => Certificate::generateNumber($clubB->id),
        ]);

        $resp = $this->getJson('/api/mobile/certificates');
        $resp->assertOk()
            ->assertJsonPath('active_count', 2)
            ->assertJsonPath('used_count', 1)
            ->assertJsonCount(3, 'certificates');

        // Есть значение-лейбл, статус, клуб и дизайн у каждого.
        $first = $resp->json('certificates.0');
        $this->assertArrayHasKey('value_label', $first);
        $this->assertArrayHasKey('used', $first);
        $this->assertArrayHasKey('name', $first['club']);
        $this->assertArrayHasKey('background_color', $first['design']);
        $this->assertArrayHasKey('accent_color', $first['design']);

        // Часовой сертификат отдаёт человекочитаемый номинал.
        $hours = collect($resp->json('certificates'))->firstWhere('value_type', 'hours');
        $this->assertSame('2 часа', $hours['value_label']);
    }

    public function test_empty_when_no_matching_client(): void
    {
        $user = User::factory()->create(['phone' => '+7 700 000 00 00']);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/certificates')
            ->assertOk()
            ->assertJsonPath('active_count', 0)
            ->assertJsonCount(0, 'certificates');
    }
}
