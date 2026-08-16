<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Настройка отказа от ответственности в супер-админке.
 */
class ClubWaiverAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function payload(Club $club, array $over = []): array
    {
        return array_merge(['name' => $club->name, 'address' => $club->address], $over);
    }

    public function test_admin_turns_the_waiver_on_with_text(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.clubs.update', $club), $this->payload($club, [
                'waiver_enabled' => 1,
                'waiver_text' => 'За травму отвечаю сам.',
            ]))
            ->assertRedirect();

        $club = $club->fresh();
        $this->assertTrue($club->collectsWaiver());
        $this->assertSame('За травму отвечаю сам.', $club->waiver_text);
    }

    /** Выключение сохраняет текст: клуб может приостановить сбор и вернуть его. */
    public function test_turning_off_keeps_the_text(): void
    {
        $club = Club::create([
            'name' => 'Клуб', 'address' => 'Адрес',
            'waiver_enabled' => true, 'waiver_text' => 'Текст',
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.clubs.update', $club), $this->payload($club, ['waiver_enabled' => 0]))
            ->assertRedirect();

        $club = $club->fresh();
        $this->assertFalse($club->collectsWaiver());
        $this->assertSame('Текст', $club->waiver_text);
    }

    public function test_qr_appears_only_when_the_waiver_is_collected(): void
    {
        $off = Club::create(['name' => 'Без отказа', 'address' => 'А']);
        $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.edit', $off))
            ->assertOk()
            ->assertSee('Текст отказа')
            ->assertDontSee('waiver-qr', false);

        $on = Club::create([
            'name' => 'С отказом', 'address' => 'Б',
            'waiver_enabled' => true, 'waiver_text' => 'Текст',
        ]);
        $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.edit', $on))
            ->assertOk()
            ->assertSee('waiver-qr', false)
            ->assertSee(url('/w/' . $on->id), false);
    }
}
