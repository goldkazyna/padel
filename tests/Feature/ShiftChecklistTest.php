<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Shift;
use App\Models\ShiftChecklistItem;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Чек-листы смены: менеджер отмечает пункты при открытии и закрытии,
 * админ задаёт сам список пунктов.
 */
class ShiftChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ShiftService
    {
        return app(ShiftService::class);
    }

    private function makeClub(): Club
    {
        return Club::create([
            'name' => 'Клуб',
            'address' => 'Адрес',
            'city' => 'Алматы',
            'features' => ['shifts' => true],
        ]);
    }

    /** Клуб с настроенным ботом — иначе уведомления молча не уходят. */
    private function makeClubWithBot(): Club
    {
        $club = $this->makeClub();
        $club->update([
            'telegram_notify_enabled' => true,
            'telegram_bot_token' => 'test-token',
            'telegram_chat_ids' => '-100500',
        ]);

        return $club->fresh();
    }

    private function makeManager(Club $club): User
    {
        $user = User::factory()->create(['role' => 'club_moderator']);
        $user->moderatorClubs()->attach($club->id);

        return $user;
    }

    /** @return array<int, ShiftChecklistItem> */
    private function makeItems(Club $club, string $type, array $titles): array
    {
        $items = [];
        foreach ($titles as $i => $title) {
            $items[] = ShiftChecklistItem::create([
                'club_id' => $club->id,
                'type' => $type,
                'title' => $title,
                'sort_order' => $i,
            ]);
        }

        return $items;
    }

    // ===== Открытие смены =====

    public function test_opening_shift_saves_marks_and_comments(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        [$first, $second] = $this->makeItems($club, 'opening', [
            'Проверить корты',
            'Пересчитать кассу',
        ]);

        $shift = $this->service()->open($club, $manager, [
            $first->id => ['done' => true, 'comment' => 'сетка на корте 3 порвана'],
            $second->id => ['done' => true, 'comment' => ''],
        ]);

        $this->assertTrue($shift->isOpen());
        $this->assertSame(2, $shift->results()->count());

        $saved = $shift->results()->where('item_id', $first->id)->first();
        $this->assertTrue($saved->is_done);
        $this->assertSame('сетка на корте 3 порвана', $saved->comment);
        $this->assertSame('Проверить корты', $saved->title_snapshot);
    }

    public function test_shift_does_not_open_with_unchecked_items(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        [$first, $second] = $this->makeItems($club, 'opening', ['Корты', 'Касса']);

        $this->expectException(RuntimeException::class);
        $this->service()->open($club, $manager, [
            $first->id => ['done' => true, 'comment' => ''],
            $second->id => ['done' => false, 'comment' => ''],
        ]);
    }

    public function test_second_shift_does_not_open_while_first_is_running(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        [$item] = $this->makeItems($club, 'opening', ['Корты']);

        $this->service()->open($club, $manager, [$item->id => ['done' => true]]);

        $this->expectException(RuntimeException::class);
        $this->service()->open($club, $manager, [$item->id => ['done' => true]]);
    }

    // ===== Закрытие смены =====

    public function test_closing_shift_writes_time_and_results(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        [$open] = $this->makeItems($club, 'opening', ['Корты']);
        [$close] = $this->makeItems($club, 'closing', ['Выключить свет']);

        $shift = $this->service()->open($club, $manager, [$open->id => ['done' => true]]);
        $this->service()->close($shift, [$close->id => ['done' => true, 'comment' => 'всё ок']]);

        $shift->refresh();
        $this->assertFalse($shift->isOpen());
        $this->assertNotNull($shift->closed_at);
        $this->assertSame(1, $shift->results()->where('type', 'closing')->count());
    }

    public function test_closed_shift_cannot_be_closed_twice(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        [$open] = $this->makeItems($club, 'opening', ['Корты']);

        $shift = $this->service()->open($club, $manager, [$open->id => ['done' => true]]);
        $this->service()->close($shift, []);

        $this->expectException(RuntimeException::class);
        $this->service()->close($shift->fresh(), []);
    }

    // ===== Уведомления в Telegram =====

    public function test_opening_shift_notifies_telegram_with_comments(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $club = $this->makeClubWithBot();
        $manager = $this->makeManager($club);
        $manager->update(['name' => 'Марьяна']);
        [$water, $courts] = $this->makeItems($club, 'opening', ['Проверить воду', 'Осмотреть корты']);

        $this->service()->open($club, $manager, [
            $water->id => ['done' => true, 'comment' => 'осталось 12 бутылок'],
            $courts->id => ['done' => true, 'comment' => ''],
        ]);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($request->url(), 'sendMessage')
                && str_contains($text, 'Смена открыта')
                && str_contains($text, 'Марьяна')
                // Замечание — главное, ради чего админ читает уведомление.
                && str_contains($text, 'осталось 12 бутылок')
                && str_contains($text, 'Проверить воду');
        });
    }

    public function test_closing_shift_notifies_telegram_with_duration(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $club = $this->makeClubWithBot();
        $manager = $this->makeManager($club);
        [$open] = $this->makeItems($club, 'opening', ['Корты']);
        [$close] = $this->makeItems($club, 'closing', ['Выключить свет']);

        $shift = $this->service()->open($club, $manager, [$open->id => ['done' => true]]);
        $shift->update(['opened_at' => now()->subHours(3)->subMinutes(20)]);

        $this->service()->close($shift->fresh(), [$close->id => ['done' => true]]);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($text, 'Смена закрыта')
                && str_contains($text, '3 ч 20 мин');
        });
    }

    public function test_club_without_bot_sends_nothing(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        [$item] = $this->makeItems($club, 'opening', ['Корты']);

        $this->service()->open($club, $manager, [$item->id => ['done' => true]]);

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    // ===== Состояние менеджера =====

    public function test_current_shift_finds_only_own_open_shift(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        $colleague = $this->makeManager($club);
        [$item] = $this->makeItems($club, 'opening', ['Корты']);

        $this->service()->open($club, $colleague, [$item->id => ['done' => true]]);

        // Смена коллеги не считается своей: смены персональные.
        $this->assertNull($this->service()->currentShift($club, $manager));
        $this->assertNotNull($this->service()->currentShift($club, $colleague));
    }

    public function test_night_shift_is_not_treated_as_forgotten(): void
    {
        // Смена, открытая ночью по Алматы, в UTC попадает на вчерашнюю дату.
        // Считать её забытой нельзя — менеджер только что вышел на работу.
        $club = $this->makeClub();
        $manager = $this->makeManager($club);

        $this->travelTo(now()->setDate(2026, 8, 11)->setTime(5, 0)); // 10:00 в Алматы

        $shift = Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => '2026-08-10 21:00:00', // 02:00 11 августа по Алматы
        ]);

        $this->assertFalse($shift->isStale(), 'ночная смена того же дня забытой не считается');
    }

    public function test_stale_shift_from_yesterday_is_detected(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);

        $shift = Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => now()->subDay()->setTime(9, 0),
        ]);

        $found = $this->service()->currentShift($club, $manager);
        $this->assertNotNull($found);
        $this->assertTrue($found->isStale(), 'вчерашняя смена должна опознаваться как забытая');
        $this->assertSame($shift->id, $found->id);
    }

    // ===== История не переписывается =====

    public function test_renaming_item_keeps_history_intact(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        [$item] = $this->makeItems($club, 'opening', ['Проверить корты']);

        $shift = $this->service()->open($club, $manager, [$item->id => ['done' => true]]);

        $item->update(['title' => 'Проверить корты и сетки']);

        $saved = $shift->results()->first();
        $this->assertSame('Проверить корты', $saved->title_snapshot,
            'старая смена показывает формулировку на момент прохождения');
    }

    public function test_disabled_items_are_not_offered(): void
    {
        $club = $this->makeClub();
        [$active, $disabled] = $this->makeItems($club, 'opening', ['Корты', 'Старый пункт']);
        $disabled->update(['is_active' => false]);

        $items = ShiftChecklistItem::forChecklist($club->id, 'opening')->get();

        $this->assertCount(1, $items);
        $this->assertSame($active->id, $items->first()->id);
    }
}
