<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Порядок клубов в списке.
 *
 * По умолчанию — по активности (сколько турниров провели). Для выбора клуба
 * в бронировании нужен предсказуемый порядок: по дате добавления.
 */
class MobileClubsSortTest extends TestCase
{
    use RefreshDatabase;

    private function makeClub(string $name, string $createdAt): Club
    {
        $club = Club::create([
            'name' => $name,
            'address' => 'Адрес',
            'city' => 'Алматы',
        ]);
        // created_at выставляем вручную: фабрика ставит «сейчас» всем сразу.
        $club->forceFill(['created_at' => $createdAt])->save();

        return $club->fresh();
    }

    public function test_sort_by_creation_date_ignores_activity(): void
    {
        // Клуб добавлен первым, но турниров не проводил: по дате он должен
        // остаться первым, хотя по активности ушёл бы вниз.
        $this->makeClub('Первый', '2026-01-10 10:00:00');
        $active = $this->makeClub('Второй', '2026-03-15 10:00:00');
        $this->makeClub('Третий', '2026-06-01 10:00:00');

        foreach (range(1, 3) as $_) {
            \App\Models\Tournament::factory()->create([
                'club_id' => $active->id,
                'status' => 'completed',
                'type' => 'americano',
            ]);
        }

        Sanctum::actingAs(User::factory()->create());
        $names = array_column(
            $this->getJson('/api/mobile/clubs?sort=created')->assertOk()->json('clubs'),
            'name'
        );

        $this->assertSame(['Первый', 'Второй', 'Третий'], $names);
    }

    public function test_coming_soon_clubs_stay_last(): void
    {
        $this->makeClub('Обычный', '2026-05-01 10:00:00');
        $soon = $this->makeClub('Скоро открытие', '2026-01-01 10:00:00');
        $soon->forceFill(['coming_soon' => true])->save();

        Sanctum::actingAs(User::factory()->create());
        $names = array_column(
            $this->getJson('/api/mobile/clubs?sort=created')->assertOk()->json('clubs'),
            'name'
        );

        // Клуб добавлен раньше, но открывается позже — держим его внизу.
        $this->assertSame(['Обычный', 'Скоро открытие'], $names);
    }

    public function test_default_sort_is_unchanged(): void
    {
        // Без параметра порядок прежний — по активности, а не по дате.
        $quiet = $this->makeClub('Тихий', '2026-01-01 10:00:00');
        $active = $this->makeClub('Активный', '2026-06-01 10:00:00');

        \App\Models\Tournament::factory()->create([
            'club_id' => $active->id,
            'status' => 'completed',
            'type' => 'americano',
        ]);

        Sanctum::actingAs(User::factory()->create());
        $names = array_column(
            $this->getJson('/api/mobile/clubs')->assertOk()->json('clubs'),
            'name'
        );

        $this->assertSame('Активный', $names[0], 'по умолчанию выше тот, кто провёл турниры');
        $this->assertContains('Тихий', $names);
        $this->assertNotNull($quiet->id);
    }
}
