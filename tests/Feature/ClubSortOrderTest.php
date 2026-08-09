<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ручной порядок клубов: супер-админ задаёт число в карточке клуба,
 * приложение показывает список по нему. Клубы без порядка идут следом,
 * по дате добавления.
 */
class ClubSortOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeClubWithCourt(string $name, ?int $sortOrder, string $createdAt): Club
    {
        $club = Club::create([
            'name' => $name,
            'address' => 'Адрес',
            'city' => 'Алматы',
            'sort_order' => $sortOrder,
        ]);
        $club->forceFill(['created_at' => $createdAt])->save();

        Court::create(['club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true]);

        return $club->fresh();
    }

    private function bookingClubNames(): array
    {
        Sanctum::actingAs(User::factory()->create());

        return array_column(
            $this->getJson('/api/mobile/courts/clubs')->assertOk()->json('clubs'),
            'name'
        );
    }

    public function test_manual_order_wins_over_creation_date(): void
    {
        // Добавлены в одном порядке, показать нужно в другом.
        $this->makeClubWithCourt('Старый', 10, '2026-01-01 10:00:00');
        $this->makeClubWithCourt('Новый', 1, '2026-06-01 10:00:00');
        $this->makeClubWithCourt('Средний', 5, '2026-03-01 10:00:00');

        $this->assertSame(['Новый', 'Средний', 'Старый'], $this->bookingClubNames());
    }

    public function test_clubs_without_order_go_after_and_by_date(): void
    {
        $this->makeClubWithCourt('С порядком', 1, '2026-06-01 10:00:00');
        $this->makeClubWithCourt('Без порядка, старый', null, '2026-01-01 10:00:00');
        $this->makeClubWithCourt('Без порядка, новый', null, '2026-03-01 10:00:00');

        $this->assertSame(
            ['С порядком', 'Без порядка, старый', 'Без порядка, новый'],
            $this->bookingClubNames()
        );
    }

    public function test_super_admin_saves_sort_order(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin)
            ->put(route('admin.clubs.update', $club), [
                'name' => 'Клуб',
                'address' => 'Адрес',
                'city' => 'Алматы',
                'sort_order' => 7,
            ])
            ->assertRedirect();

        $this->assertSame(7, (int) $club->fresh()->sort_order);
    }

    public function test_empty_sort_order_clears_position(): void
    {
        $club = Club::create([
            'name' => 'Клуб',
            'address' => 'Адрес',
            'city' => 'Алматы',
            'sort_order' => 3,
        ]);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin)
            ->put(route('admin.clubs.update', $club), [
                'name' => 'Клуб',
                'address' => 'Адрес',
                'city' => 'Алматы',
                'sort_order' => '',
            ])
            ->assertRedirect();

        $this->assertNull($club->fresh()->sort_order, 'пустое поле снимает ручной порядок');
    }
}
