<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Номер WhatsApp клуба: задаётся в супер-админке, в приложении становится
 * кнопкой «написать» рядом со звонком.
 *
 * Номер отдельный от телефона намеренно: на общий номер клуба звонят, а
 * переписываются часто с другого, и кнопка «написать» на городской номер
 * ведёт в пустоту.
 */
class ClubWhatsappContactTest extends TestCase
{
    use RefreshDatabase;

    private function club(array $extra = []): Club
    {
        return Club::create(array_merge([
            'name' => 'Padel Sai',
            'address' => 'Алматы',
            'city' => 'Алматы',
            'phone' => '+7 707 323 20 30',
        ], $extra));
    }

    public function test_супер_админ_сохраняет_номер_whatsapp(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->put(route('admin.clubs.update', $club), [
                'name' => $club->name,
                'address' => $club->address,
                'city' => $club->city,
                'phone' => $club->phone,
                'whatsapp_phone' => '+7 700 111 22 33',
            ])
            ->assertRedirect();

        $this->assertSame('+7 700 111 22 33', $club->fresh()->whatsapp_phone);
    }

    public function test_поле_видно_в_форме_клуба(): void
    {
        $club = $this->club(['whatsapp_phone' => '+7 700 111 22 33']);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get(route('admin.clubs.edit', $club))
            ->assertOk()
            ->assertSee('WhatsApp')
            ->assertSee('+7 700 111 22 33');
    }

    public function test_ссылка_на_переписку_собирается_из_номера(): void
    {
        $club = $this->club(['whatsapp_phone' => '+7 (700) 111-22-33']);

        $this->assertSame('https://wa.me/77001112233', $club->whatsappUrl());
    }

    public function test_без_номера_ссылки_нет(): void
    {
        $this->assertNull($this->club()->whatsappUrl(), 'телефон клуба за WhatsApp не считаем');
        $this->assertNull($this->club(['whatsapp_phone' => '123'])->whatsappUrl(), 'обрывок номера — не номер');
    }

    public function test_приложение_получает_ссылку_в_карточке_организатора(): void
    {
        $club = $this->club(['whatsapp_phone' => '+7 700 111 22 33']);
        $tournament = Tournament::factory()->create(['club_id' => $club->id, 'status' => 'open']);
        $user = User::factory()->create(['level' => 3.0]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('tournament.club.whatsapp_url', 'https://wa.me/77001112233')
            ->assertJsonPath('tournament.club.phone', '+7 707 323 20 30');
    }

    public function test_у_клуба_без_whatsapp_поле_пустое(): void
    {
        $club = $this->club();
        $tournament = Tournament::factory()->create(['club_id' => $club->id, 'status' => 'open']);

        $this->actingAs(User::factory()->create(['level' => 3.0]), 'sanctum')
            ->getJson("/api/mobile/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('tournament.club.whatsapp_url', null);
    }
}
