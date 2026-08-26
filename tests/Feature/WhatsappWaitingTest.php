<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Support\WhatsappSla;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Экран «ждут ответа»: кто написал и до сих пор без ответа.
 * Время считается только в рабочие часы клуба.
 */
class WhatsappWaitingTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        config([
            'services.whapi.club_id' => $this->club->id,
            'services.whapi.work_from' => '09:00',
            'services.whapi.work_to' => '23:00',
            'services.whapi.sla_minutes' => 15,
            'app.schedule_timezone' => 'Asia/Almaty',
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    /** Сообщение в переписке. Время задаём по Алматы — так его читает человек. */
    private function message(string $phone, bool $fromMe, string $localTime, string $body = 'текст', array $extra = []): WhatsappMessage
    {
        return WhatsappMessage::create(array_merge([
            'club_id' => $this->club->id,
            'wa_message_id' => uniqid('wa', true),
            'chat_id' => $phone . '@s.whatsapp.net',
            'phone' => $phone,
            'author_name' => 'Клиент',
            'from_me' => $fromMe,
            'type' => 'text',
            'body' => $body,
            'payload' => [],
            'sent_at' => Carbon::parse($localTime, 'Asia/Almaty')->utc(),
        ], $extra));
    }

    public function test_ночное_молчание_не_считается_просрочкой(): void
    {
        // Написал в 23:40, сейчас 09:05 — клуб был закрыт, «просрочки» 5 минут.
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:05', 'Asia/Almaty')->utc());

        $minutes = WhatsappSla::businessMinutes(
            Carbon::parse('2026-08-19 23:40', 'Asia/Almaty')->utc(),
            now()
        );

        $this->assertSame(5, $minutes);
    }

    public function test_рабочее_ожидание_считается_полностью(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 14:00', 'Asia/Almaty')->utc());

        $this->assertSame(90, WhatsappSla::businessMinutes(
            Carbon::parse('2026-08-20 12:30', 'Asia/Almaty')->utc(),
            now()
        ));
    }

    public function test_ожидание_через_сутки_складывает_только_рабочие_часы(): void
    {
        // 18:00 → 10:00 следующего дня: 5 часов вечера + 1 час утра.
        Carbon::setTestNow(Carbon::parse('2026-08-21 10:00', 'Asia/Almaty')->utc());

        $this->assertSame(6 * 60, WhatsappSla::businessMinutes(
            Carbon::parse('2026-08-20 18:00', 'Asia/Almaty')->utc(),
            now()
        ));
    }

    public function test_отвеченный_диалог_в_список_не_попадает(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());

        $this->message('77770000001', false, '2026-08-20 10:00', 'есть корт?');
        $this->message('77770000001', true, '2026-08-20 10:05', 'да, есть');

        $this->assertCount(0, WhatsappSla::waitingChats($this->club->id));
    }

    public function test_серия_сообщений_подряд_это_одно_ожидание(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());

        $this->message('77770000002', true, '2026-08-20 09:30', 'доброе утро');
        $this->message('77770000002', false, '2026-08-20 10:00', 'а на вечер?');
        $this->message('77770000002', false, '2026-08-20 10:20', 'алло?');
        $this->message('77770000002', false, '2026-08-20 10:40', 'ну как?');

        $waiting = WhatsappSla::waitingChats($this->club->id);

        $this->assertCount(1, $waiting);
        $row = $waiting->first();
        $this->assertSame(3, $row['messages'], 'три сообщения подряд — одно ожидание');
        $this->assertSame(120, $row['waited'], 'отсчёт идёт от первого, а не от последнего');
        $this->assertTrue($row['overdue']);
        $this->assertTrue($row['ever_answered']);
    }

    public function test_клиенту_ни_разу_не_ответили(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());
        $this->message('77770000003', false, '2026-08-20 11:50', 'здравствуйте');

        $row = WhatsappSla::waitingChats($this->club->id)->first();

        $this->assertFalse($row['ever_answered']);
        $this->assertSame(10, $row['waited']);
        $this->assertFalse($row['overdue'], '10 минут — ещё в пределах порога');
    }

    public function test_экран_показывает_имя_из_карточки_и_сортирует_по_ожиданию(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());

        ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Айгуль Сериковна',
            'phone' => '+7 (777) 000-00-04',
        ]);
        $this->message('77770000004', false, '2026-08-20 09:10', 'давно жду');
        $this->message('77770000005', false, '2026-08-20 11:55', 'только написал');

        $response = $this->actingAs($this->admin)->get(route('club.whatsapp.waiting'));
        $response->assertOk()
            ->assertSee('Айгуль Сериковна')
            ->assertSee('давно жду')
            ->assertSee('только написал');

        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'только написал'),
            strpos($html, 'давно жду'),
            'кто ждёт дольше — тот выше'
        );
    }

    public function test_групповой_чат_не_считается_обращением(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());

        $this->message('77770000006', false, '2026-08-20 10:00', 'кто на вечер?', [
            'chat_id' => '77770000006-1600000000@g.us',
        ]);

        $this->assertCount(0, WhatsappSla::waitingChats($this->club->id),
            'в группе игроков клубу отвечать нечего');
    }

    public function test_служебное_событие_не_считается_сообщением(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());

        $this->message('77770000007', false, '2026-08-20 10:00', 'вас добавили в группу', ['type' => 'action']);

        $this->assertCount(0, WhatsappSla::waitingChats($this->club->id));
    }

    public function test_экран_по_умолчанию_показывает_последние_трое_суток(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());

        $this->message('77770000008', false, '2026-08-01 10:00', 'старое спасибо');
        $this->message('77770000009', false, '2026-08-20 10:00', 'свежий вопрос');

        $this->actingAs($this->admin)->get(route('club.whatsapp.waiting'))
            ->assertOk()
            ->assertSee('свежий вопрос')
            ->assertDontSee('старое спасибо');

        $this->actingAs($this->admin)->get(route('club.whatsapp.waiting', ['all' => 1]))
            ->assertOk()
            ->assertSee('старое спасибо');
    }

    public function test_минуты_в_человеческом_виде(): void
    {
        $this->assertSame('40 мин', WhatsappSla::humanMinutes(40));
        $this->assertSame('2 ч', WhatsappSla::humanMinutes(120));
        $this->assertSame('1 ч 5 мин', WhatsappSla::humanMinutes(65));
        $this->assertSame('2 дн 3 ч', WhatsappSla::humanMinutes((2 * 24 + 3) * 60));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
