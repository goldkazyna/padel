<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubWaiverSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Отказ от ответственности в списке клиентов клуба.
 */
class ClientWaiverBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->club = Club::create([
            'name' => 'Клуб', 'address' => 'А',
            'waiver_enabled' => true, 'waiver_text' => 'Текст отказа',
        ]);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function sign(User $user): ClubWaiverSignature
    {
        Storage::disk('local')->put('waivers/x.png', 'png-bytes');

        return ClubWaiverSignature::create([
            'club_id' => $this->club->id,
            'user_id' => $user->id,
            'full_name' => 'Дудников Денис Сергеевич',
            'phone' => $user->phone,
            'waiver_text' => 'Текст отказа',
            'signature_path' => 'waivers/x.png',
            'signed_at' => now(),
        ]);
    }

    public function test_badge_shows_only_for_those_who_signed(): void
    {
        $player = User::factory()->create(['phone' => '77771234567']);
        $signed = ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Подписал',
            'phone' => '77771234567', 'user_id' => $player->id,
        ]);
        ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Не подписал', 'phone' => '77009998877',
        ]);

        $signature = $this->sign($player);

        $response = $this->actingAs($this->admin)
            ->get(route('club.clients.index'))
            ->assertOk();

        $response->assertSee(route('club.waivers.show', $signature), false);
        // Значок ровно один — у второго клиента подписи нет.
        $this->assertSame(1, substr_count($response->getContent(), 'data-waiver='));
    }

    /** Клиент может не быть привязан к аккаунту — ищем по номеру. */
    public function test_client_is_matched_by_phone_without_a_link(): void
    {
        $player = User::factory()->create(['phone' => '77771234567']);
        ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Без привязки', 'phone' => '77771234567',
        ]);
        $signature = $this->sign($player);

        $this->actingAs($this->admin)
            ->get(route('club.clients.index'))
            ->assertOk()
            ->assertSee(route('club.waivers.show', $signature), false);
    }

    public function test_signature_opens_with_text_and_image(): void
    {
        $player = User::factory()->create(['phone' => '77771234567']);
        $signature = $this->sign($player);

        $this->actingAs($this->admin)
            ->getJson(route('club.waivers.show', $signature))
            ->assertOk()
            ->assertJsonPath('full_name', 'Дудников Денис Сергеевич')
            ->assertJsonPath('text', 'Текст отказа')
            ->assertJsonPath('image_url', route('club.waivers.image', $signature));
    }

    /** Подпись — персональные данные: чужой клуб её не откроет. */
    public function test_foreign_club_admin_is_refused(): void
    {
        $player = User::factory()->create(['phone' => '77771234567']);
        $signature = $this->sign($player);

        $other = Club::create(['name' => 'Чужой', 'address' => 'Б']);
        $stranger = User::factory()->create(['role' => 'club_admin']);
        $stranger->adminClubs()->attach($other->id);

        $this->actingAs($stranger)
            ->getJson(route('club.waivers.show', $signature))
            ->assertForbidden();
    }

    /** Раздел «Отказы» убран из меню — отказы живут в карточке клиента. */
    public function test_waivers_are_gone_from_the_menu(): void
    {
        $this->actingAs($this->admin)
            ->get(route('club.clients.index'))
            ->assertOk()
            ->assertDontSee(route('club.waivers.index'), false);
    }
}
