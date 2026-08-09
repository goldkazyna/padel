<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Список клубов в бронировании кортов.
 *
 * Сортировки не было вовсе — база отдавала строки в произвольном порядке,
 * и список «прыгал» между открытиями экрана.
 */
class MobileCourtClubsSortTest extends TestCase
{
    use RefreshDatabase;

    private function makeClubWithCourt(string $name, string $createdAt): Club
    {
        $club = Club::create([
            'name' => $name,
            'address' => 'Адрес',
            'city' => 'Алматы',
        ]);
        // created_at выставляем вручную: иначе у всех клубов «сейчас».
        $club->forceFill(['created_at' => $createdAt])->save();

        Court::create([
            'club_id' => $club->id,
            'name' => 'Корт 1',
            'is_active' => true,
        ]);

        return $club->fresh();
    }

    public function test_clubs_are_sorted_by_creation_date(): void
    {
        // Создаём вперемешку, чтобы порядок вставки не совпал с ожидаемым.
        $this->makeClubWithCourt('Третий', '2026-06-01 10:00:00');
        $this->makeClubWithCourt('Первый', '2026-01-10 10:00:00');
        $this->makeClubWithCourt('Второй', '2026-03-15 10:00:00');

        Sanctum::actingAs(User::factory()->create());
        $names = array_column(
            $this->getJson('/api/mobile/courts/clubs')->assertOk()->json('clubs'),
            'name'
        );

        $this->assertSame(['Первый', 'Второй', 'Третий'], $names);
    }

    public function test_order_is_stable_between_requests(): void
    {
        foreach (['Альфа', 'Бета', 'Гамма', 'Дельта'] as $i => $name) {
            $this->makeClubWithCourt($name, '2026-0' . ($i + 1) . '-01 10:00:00');
        }

        Sanctum::actingAs(User::factory()->create());

        $first = array_column(
            $this->getJson('/api/mobile/courts/clubs')->assertOk()->json('clubs'), 'name');
        $second = array_column(
            $this->getJson('/api/mobile/courts/clubs')->assertOk()->json('clubs'), 'name');

        $this->assertSame($first, $second, 'порядок не должен меняться между запросами');
        $this->assertSame(['Альфа', 'Бета', 'Гамма', 'Дельта'], $first);
    }
}
