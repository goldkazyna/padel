<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubInventoryIssue;
use App\Models\ClubInventoryIssueItem;
use App\Models\ClubInventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Выдача инвентаря на руки: выдали — висит, вернули — снялось.
 * Денег не касается: в кассу и отчёты выдача не идёт.
 */
class ClubInventoryIssueTest extends TestCase
{
    use RefreshDatabase;

    /** Клуб с включённым модулем инвентаря и его администратор. */
    private function setupClub(): array
    {
        $club = Club::create([
            'name' => 'Padel Almaty',
            'address' => 'Алматы',
            'features' => ['inventory' => true],
        ]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    private function makeItem(Club $club, string $name, int $price = 3000, bool $active = true): ClubInventoryItem
    {
        return ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => $name, 'price' => $price, 'is_active' => $active,
        ]);
    }

    private function makeClient(Club $club, string $name = 'Айдос Жумабеков'): ClubClient
    {
        return ClubClient::create([
            'club_id' => $club->id, 'name' => $name, 'phone' => '+7 701 000 00 01',
        ]);
    }

    // ===== Выдача =====

    public function test_issue_creates_record_with_lines(): void
    {
        [$club, $admin] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 5500);
        $client = $this->makeClient($club);

        $this->actingAs($admin)
            ->post(route('club.inventory.issue'), [
                'club_client_id' => $client->id,
                'comment' => 'оставил документ',
                'items' => [
                    ['id' => $racket->id, 'quantity' => 2],
                    ['id' => $balls->id, 'quantity' => 1],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $issue = ClubInventoryIssue::where('club_id', $club->id)->first();
        $this->assertNotNull($issue);
        $this->assertSame($client->id, $issue->club_client_id);
        $this->assertSame($admin->id, $issue->issued_by);
        $this->assertSame('оставил документ', $issue->comment);
        $this->assertSame(2, $issue->items()->count());

        // Название и цена сохранены снимком — позицию могут переименовать.
        $line = $issue->items()->where('club_inventory_item_id', $racket->id)->first();
        $this->assertSame('Аренда ракетки', $line->name);
        $this->assertSame(3000, $line->price);
        $this->assertSame(2, $line->quantity);
        $this->assertNull($line->returned_at);
    }

    public function test_same_item_twice_is_merged_into_one_line(): void
    {
        [$club, $admin] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки');
        $client = $this->makeClient($club);

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [
                ['id' => $racket->id, 'quantity' => 2],
                ['id' => $racket->id, 'quantity' => 3],
            ],
        ])->assertRedirect();

        $lines = ClubInventoryIssueItem::all();
        $this->assertCount(1, $lines, 'одинаковые позиции складываются в одну строку');
        $this->assertSame(5, $lines->first()->quantity);
    }

    public function test_issue_requires_client_and_items(): void
    {
        [$club, $admin] = $this->setupClub();
        $this->makeItem($club, 'Аренда ракетки');

        $this->actingAs($admin)
            ->post(route('club.inventory.issue'), ['items' => []])
            ->assertSessionHasErrors(['club_client_id', 'items']);

        $this->assertSame(0, ClubInventoryIssue::count());
    }

    public function test_cannot_issue_item_of_another_club(): void
    {
        [$club, $admin] = $this->setupClub();
        $client = $this->makeClient($club);

        $other = Club::create(['name' => 'Чужой', 'address' => 'A', 'features' => ['inventory' => true]]);
        $foreign = $this->makeItem($other, 'Чужая ракетка');

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [['id' => $foreign->id, 'quantity' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame(0, ClubInventoryIssue::count());
    }

    public function test_cannot_issue_to_client_of_another_club(): void
    {
        [$club, $admin] = $this->setupClub();
        $item = $this->makeItem($club, 'Аренда ракетки');

        $other = Club::create(['name' => 'Чужой', 'address' => 'A']);
        $foreignClient = $this->makeClient($other, 'Чужой клиент');

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $foreignClient->id,
            'items' => [['id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame(0, ClubInventoryIssue::count());
    }

    public function test_disabled_item_cannot_be_issued(): void
    {
        [$club, $admin] = $this->setupClub();
        $off = $this->makeItem($club, 'Выключенная', 1000, active: false);
        $client = $this->makeClient($club);

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [['id' => $off->id, 'quantity' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame(0, ClubInventoryIssue::count());
    }

    // ===== Возврат =====

    /** @return array{0: Club, 1: User, 2: ClubClient, 3: ClubInventoryIssue} */
    private function issued(): array
    {
        [$club, $admin] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки');
        $balls = $this->makeItem($club, 'Мячи', 5500);
        $client = $this->makeClient($club);

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [
                ['id' => $racket->id, 'quantity' => 2],
                ['id' => $balls->id, 'quantity' => 1],
            ],
        ]);

        return [$club, $admin, $client, ClubInventoryIssue::first()];
    }

    public function test_returning_one_line_leaves_the_rest_outstanding(): void
    {
        [$club, $admin, , $issue] = $this->issued();
        $line = $issue->items()->where('name', 'Мячи')->first();

        $this->actingAs($admin)
            ->post(route('club.inventory.returnItem', $line))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($line->fresh()->returned_at);
        $this->assertSame($admin->id, $line->fresh()->returned_by);
        // Ракетки всё ещё на руках, выдача не закрыта.
        $this->assertSame(1, $issue->fresh()->openItems()->count());
        $this->assertFalse($issue->fresh()->isClosed());
    }

    public function test_returning_all_closes_the_issue(): void
    {
        [$club, $admin, $client, $issue] = $this->issued();

        $this->actingAs($admin)
            ->post(route('club.inventory.returnClient', $client))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $issue->fresh()->openItems()->count());
        $this->assertTrue($issue->fresh()->isClosed());
    }

    public function test_second_return_of_the_same_line_is_rejected(): void
    {
        [$club, $admin, , $issue] = $this->issued();
        $line = $issue->items()->first();

        $this->actingAs($admin)->post(route('club.inventory.returnItem', $line));
        $first = $line->fresh()->returned_at;

        $this->actingAs($admin)
            ->post(route('club.inventory.returnItem', $line->fresh()))
            ->assertSessionHas('error');

        // Время приёма не переписалось повторным нажатием.
        $this->assertEquals($first, $line->fresh()->returned_at);
    }

    public function test_return_all_without_anything_outstanding_is_rejected(): void
    {
        [$club, $admin] = $this->setupClub();
        $client = $this->makeClient($club);

        $this->actingAs($admin)
            ->post(route('club.inventory.returnClient', $client))
            ->assertSessionHas('error');
    }

    public function test_cannot_return_line_of_another_club(): void
    {
        [, $admin] = $this->setupClub();

        $other = Club::create(['name' => 'Чужой', 'address' => 'A', 'features' => ['inventory' => true]]);
        $otherAdmin = User::factory()->create(['role' => 'club_admin']);
        $otherAdmin->adminClubs()->attach($other->id);
        $item = $this->makeItem($other, 'Чужая ракетка');
        $client = $this->makeClient($other, 'Чужой клиент');

        $this->actingAs($otherAdmin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [['id' => $item->id, 'quantity' => 1]],
        ]);
        $foreignLine = ClubInventoryIssueItem::first();

        $this->actingAs($admin)
            ->post(route('club.inventory.returnItem', $foreignLine))
            ->assertForbidden();

        $this->assertNull($foreignLine->fresh()->returned_at);
    }

    // ===== Экран =====

    public function test_page_shows_holder_card_and_red_badge(): void
    {
        [$club, $admin, $client] = $this->issued();

        $this->actingAs($admin)
            ->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee('Выданный инвентарь')
            ->assertSee($client->name)
            // Бейдж на плитке считает единицы, а не строки: ракеток выдали две.
            ->assertSee('2 на руках')
            ->assertSee('1 на руках');
    }

    public function test_returned_items_disappear_from_the_page(): void
    {
        [$club, $admin, $client] = $this->issued();

        $this->actingAs($admin)->post(route('club.inventory.returnClient', $client));

        // Проверяем по карточке и по тексту бейджа: просто «на руках» встречается
        // ещё и в комментариях к стилям, они тоже уезжают в разметку.
        $this->actingAs($admin)
            ->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee('Ничего не выдано')
            ->assertDontSee($client->name)
            ->assertDontSee('2 на руках')
            ->assertDontSee('1 на руках');
    }

    public function test_age_is_shown_in_whole_units_with_russian_plural(): void
    {
        [$club, $admin] = $this->setupClub();
        $item = $this->makeItem($club, 'Аренда ракетки');

        $cases = [
            ['Иван Минутный', 40, '40 минут'],
            ['Пётр Часовой', 3 * 60, '3 часа'],
            ['Сергей Суточный', 49 * 60, '2 дня'],
            ['Олег Одиночный', 1 * 60, '1 час'],
        ];

        foreach ($cases as [$name, $minutesAgo, $expected]) {
            $client = $this->makeClient($club, $name);
            $issue = ClubInventoryIssue::create([
                'club_id' => $club->id, 'club_client_id' => $client->id, 'issued_by' => $admin->id,
            ]);
            $issue->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
            $issue->items()->create([
                'club_inventory_item_id' => $item->id, 'name' => $item->name,
                'price' => $item->price, 'quantity' => 1,
            ]);
        }

        $html = $this->actingAs($admin)->get(route('club.inventory.index'))->assertOk()->getContent();

        foreach ($cases as [$name, , $expected]) {
            $this->assertStringContainsString($expected . ' на руках', $html, "срок для «{$name}»");
        }

        // Carbon отдаёт дробные минуты — в интерфейс они попасть не должны.
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d+ (минут|часа|дня)/u', $html);
    }

    public function test_sidebar_badge_counts_units_awaiting_return(): void
    {
        [$club, $admin, $client] = $this->issued();

        // Выдали две ракетки и одни мячи — в меню должно висеть 3.
        $this->actingAs($admin)
            ->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee('<span class="unprocessed-badge">3</span>', false);

        $this->actingAs($admin)->post(route('club.inventory.returnClient', $client));

        $this->actingAs($admin)
            ->get(route('club.inventory.index'))
            ->assertOk()
            ->assertDontSee('<span class="unprocessed-badge">3</span>', false);
    }

    public function test_sidebar_badge_is_scoped_to_own_club(): void
    {
        [, $admin] = $this->setupClub();

        // В чужом клубе что-то выдали — на наш бейдж это влиять не должно.
        $other = Club::create(['name' => 'Чужой', 'address' => 'A', 'features' => ['inventory' => true]]);
        $otherAdmin = User::factory()->create(['role' => 'club_admin']);
        $otherAdmin->adminClubs()->attach($other->id);
        $item = $this->makeItem($other, 'Чужая ракетка');
        $client = $this->makeClient($other, 'Чужой клиент');

        $this->actingAs($otherAdmin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [['id' => $item->id, 'quantity' => 7]],
        ]);

        $this->actingAs($admin)
            ->get(route('club.inventory.index'))
            ->assertOk()
            ->assertDontSee('<span class="unprocessed-badge">7</span>', false);
    }

    // ===== Метка на брони корта =====

    /**
     * Корт и бронь. Бронь сама заводит карточку клиента по телефону.
     *
     * Дата — середина следующей недели намеренно. На последний день недели
     * бронь не попадает в недельную сетку под SQLite: там дата лежит строкой
     * «2026-08-16 00:00:00» и выпадает из whereBetween по «2026-08-16».
     * На проде колонка — настоящий DATE в MySQL, и такого нет.
     */
    private function bookedCourt(Club $club, User $admin, string $phone = '77770000000'): array
    {
        $court = \App\Models\Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $date = now()->startOfWeek(\Carbon\Carbon::MONDAY)->addWeek()->addDays(2)->toDateString();

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => $date,
            'start_time' => '13:00',
            'slots' => 1,
            'client_name' => 'Денис Дудников',
            'client_phone' => $phone,
            'payment_method' => 'kaspi',
            'is_paid' => 1,
            'booking_type' => 'individual',
        ])->assertRedirect();

        $client = ClubClient::where('club_id', $club->id)->where('phone', $phone)->firstOrFail();

        return [$court, $date, $client];
    }

    public function test_schedule_marks_booking_of_client_holding_inventory(): void
    {
        [$club, $admin] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки');
        [, $date, $client] = $this->bookedCourt($club, $admin);

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [['id' => $racket->id, 'quantity' => 2]],
        ]);

        // Метка есть и в дневном расписании, и в недельном.
        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('На руках инвентарь: Аренда ракетки ×2');

        $this->actingAs($admin)
            ->get(route('club.courts.scheduleWeek', ['date' => $date]))
            ->assertOk()
            ->assertSee('На руках инвентарь: Аренда ракетки ×2');
    }

    public function test_schedule_mark_disappears_after_return(): void
    {
        [$club, $admin] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки');
        [, $date, $client] = $this->bookedCourt($club, $admin);

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [['id' => $racket->id, 'quantity' => 2]],
        ]);
        $this->actingAs($admin)->post(route('club.inventory.returnClient', $client));

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertDontSee('На руках инвентарь: Аренда ракетки');
    }

    public function test_schedule_does_not_mark_bookings_of_other_clients(): void
    {
        [$club, $admin] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки');
        // Бронь на один номер, инвентарь выдан другому клиенту.
        [, $date] = $this->bookedCourt($club, $admin, '77770000000');
        $other = $this->makeClient($club, 'Другой человек');
        $other->update(['phone' => '77771111111']);

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $other->id,
            'items' => [['id' => $racket->id, 'quantity' => 1]],
        ]);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertDontSee('На руках инвентарь: Аренда ракетки');
    }

    public function test_schedule_mark_matches_phone_written_differently(): void
    {
        [$club, $admin] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки');
        [, $date, $client] = $this->bookedCourt($club, $admin, '77770000000');

        // В карточке номер записан со скобками и пробелами — метка всё равно ловится,
        // потому что сравниваем только цифры.
        $client->update(['phone' => '+7 (777) 000-00-00']);

        $this->actingAs($admin)->post(route('club.inventory.issue'), [
            'club_client_id' => $client->id,
            'items' => [['id' => $racket->id, 'quantity' => 1]],
        ]);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('На руках инвентарь: Аренда ракетки ×1');
    }

    public function test_deleting_catalogue_item_keeps_the_issued_line_readable(): void
    {
        [$club, $admin, , $issue] = $this->issued();
        $line = $issue->items()->where('name', 'Мячи')->first();

        ClubInventoryItem::where('club_id', $club->id)->where('name', 'Мячи')->delete();

        $line = $line->fresh();
        $this->assertNotNull($line, 'строка выдачи переживает удаление позиции справочника');
        $this->assertNull($line->club_inventory_item_id);
        $this->assertSame('Мячи', $line->name, 'название осталось снимком');
    }
}
