<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сертификат в брони корта.
 *
 * Две дыры, из-за которых сертификат «пропадал»:
 * при редактировании брони поле вообще не принималось, а сертификат,
 * выпущенный вводом ФИО без привязки к клиенту, не находился никогда.
 */
class BookingCertificateTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private Court $court;
    private ClubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->court = Court::create([
            'club_id' => $this->club->id,
            'name' => 'Корт 2',
            'price_per_hour' => 10000,
        ]);

        $this->client = ClubClient::create([
            'club_id' => $this->club->id,
            'name' => 'Плющев Алексей Викторович',
            'phone' => '+7 977 908 50 28',
        ]);
    }

    private function certificate(array $over = []): Certificate
    {
        return Certificate::create(array_merge([
            'club_id' => $this->club->id,
            'type' => Certificate::TYPE_NAMED,
            'recipient_name' => 'Плющев Алексей Викторович',
            'client_id' => $this->client->id,
            'value_type' => Certificate::VALUE_HOURS,
            'hours' => 1,
            'number' => 'PHILLS-1708-2026-AI18ZV',
        ], $over));
    }

    private function booking(array $over = []): CourtBooking
    {
        return CourtBooking::create(array_merge([
            'court_id' => $this->court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '19:00',
            'end_time' => '20:00',
            'status' => 'confirmed',
            'client_name' => $this->client->name,
            'client_phone' => '79779085028',
            'booked_by' => $this->admin->id,
            'price' => 10000,
            'payment_method' => 'certificate',
        ], $over));
    }

    /** @return array<string, mixed> */
    private function editPayload(CourtBooking $booking, array $over = []): array
    {
        return array_merge([
            'date' => $booking->date->toDateString(),
            'start_time' => '19:00',
            'duration' => 1,
            'client_name' => $booking->client_name,
            'client_phone' => $booking->client_phone,
            'payment_method' => 'certificate',
            'price' => 0,
            'discount' => 0,
            'is_paid' => 1,
        ], $over);
    }

    public function test_certificate_can_be_attached_while_editing(): void
    {
        $cert = $this->certificate();
        $booking = $this->booking();

        $this->actingAs($this->admin)
            ->put(route('club.courts.updateBooking', $booking), $this->editPayload($booking, [
                'certificate_id' => $cert->id,
            ]))
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame($cert->id, $booking->certificate_id, 'сертификат должен сохраниться');
        $this->assertNotNull($cert->fresh()->used_at, 'и погаситься');
    }

    /** Снятый сертификат возвращается в оборот. */
    public function test_removing_a_certificate_frees_it(): void
    {
        $cert = $this->certificate(['used_at' => now()]);
        $booking = $this->booking(['certificate_id' => $cert->id]);

        $this->actingAs($this->admin)
            ->put(route('club.courts.updateBooking', $booking), $this->editPayload($booking, [
                'payment_method' => 'cash',
                'certificate_id' => null,
            ]))
            ->assertRedirect();

        $this->assertNull($booking->fresh()->certificate_id);
        $this->assertNull($cert->fresh()->used_at, 'сертификат снова активен');
    }

    public function test_used_certificate_is_refused(): void
    {
        $mine = $this->certificate();
        $used = $this->certificate([
            'number' => 'PHILLS-OTHER',
            'used_at' => now(),
        ]);
        $booking = $this->booking();

        $this->actingAs($this->admin)
            ->put(route('club.courts.updateBooking', $booking), $this->editPayload($booking, [
                'certificate_id' => $used->id,
            ]))
            ->assertSessionHas('error');

        $this->assertNull($booking->fresh()->certificate_id);
        $this->assertNotNull($mine->fresh());
    }

    public function test_client_certificate_is_listed(): void
    {
        $cert = $this->certificate();

        $this->actingAs($this->admin)
            ->getJson(route('club.certificates.forClient', ['phone' => '79779085028']))
            ->assertOk()
            ->assertJsonCount(1, 'certificates')
            ->assertJsonPath('certificates.0.number', $cert->number);
    }

    /**
     * Сертификат, выпущенный вводом ФИО без привязки к клиенту.
     *
     * Форма прямо пишет «сертификат создастся на введённое ФИО», так что
     * таких на проде хватает — раньше они не находились никогда.
     */
    public function test_unlinked_certificate_is_found_by_name(): void
    {
        $cert = $this->certificate([
            'client_id' => null,
            'recipient_name' => '  плющев   алексей викторович ',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('club.certificates.forClient', ['phone' => '79779085028']))
            ->assertOk()
            ->assertJsonCount(1, 'certificates')
            ->assertJsonPath('certificates.0.number', $cert->number);
    }

    /** Чужой человек с другим именем сертификат не подхватит. */
    public function test_someone_elses_certificate_is_not_listed(): void
    {
        $this->certificate([
            'client_id' => null,
            'recipient_name' => 'Совершенно другой человек',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('club.certificates.forClient', ['phone' => '79779085028']))
            ->assertOk()
            ->assertJsonCount(0, 'certificates');
    }
}
