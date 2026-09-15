<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\Shift;
use App\Models\ShiftChecklistItem;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Отчёт по оплаченным броням в Telegram после закрытия смены.
 *
 * Владельцу не нужно заходить в CRM, чтобы узнать, чем закончился день: при
 * закрытии смены бот присылает PDF за этот день. Включается галочкой в
 * настройках клуба — без неё всё как раньше.
 */
class ShiftReportTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->club = Club::create([
            'name' => 'Hills', 'address' => 'А', 'city' => 'Алматы',
            'features' => ['shifts' => true],
            'telegram_notify_enabled' => true,
            'telegram_bot_token' => '123:ABC',
            'telegram_chat_ids' => '381314146',
            'shift_report_enabled' => true,
        ]);

        $this->manager = User::factory()->create(['role' => 'club_moderator', 'name' => 'Асель М.']);
        $this->manager->moderatorClubs()->attach($this->club->id);

        $court = Court::create([
            'club_id' => $this->club->id, 'name' => 'К1',
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);

        CourtBooking::create([
            'court_id' => $court->id,
            'date' => now('Asia/Almaty')->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'Иван Петров', 'client_phone' => '77011112233',
            'price' => 12000, 'status' => 'confirmed', 'is_paid' => true,
            'payment_method' => 'cash', 'booked_by' => $this->manager->id,
        ]);
    }

    private function closeShift(): void
    {
        $shift = Shift::create([
            'club_id' => $this->club->id,
            'user_id' => $this->manager->id,
            'opened_at' => now(),
        ]);

        $item = ShiftChecklistItem::create([
            'club_id' => $this->club->id,
            'type' => 'closing',
            'title' => 'Закрыть кассу',
            'sort_order' => 1,
        ]);

        app(ShiftService::class)->close($shift, [$item->id => ['done' => true]]);
    }

    private function documentsSent(): array
    {
        $sent = [];
        Http::recorded(function (Request $request) use (&$sent) {
            if (str_contains($request->url(), '/sendDocument')) {
                $sent[] = $request;
            }

            return true;
        });

        return $sent;
    }

    public function test_после_закрытия_смены_приходит_pdf(): void
    {
        $this->closeShift();

        $documents = $this->documentsSent();
        $this->assertCount(1, $documents, 'отчёт должен уйти одному получателю');

        $body = $documents[0]->body();
        $this->assertStringContainsString('%PDF', $body, 'приложен не PDF');
        $this->assertStringContainsString('otchet_', $body, 'имя файла с датой');
        $this->assertStringContainsString('Оплаченные брони', $body, 'в подписи — что за отчёт');
        $this->assertStringContainsString('12 000', $body, 'в подписи — сумма за день');
    }

    public function test_без_галочки_отчёт_не_шлётся(): void
    {
        $this->club->update(['shift_report_enabled' => false]);

        $this->closeShift();

        $this->assertCount(0, $this->documentsSent());
        // Обычное уведомление о закрытии смены при этом остаётся.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage'));
    }

    public function test_без_настроенного_бота_закрытие_не_падает(): void
    {
        $this->club->update(['telegram_bot_token' => null]);

        $this->closeShift();

        $this->assertCount(0, $this->documentsSent());
        $this->assertNotNull(Shift::first()->closed_at, 'смена всё равно закрыта');
    }
}
