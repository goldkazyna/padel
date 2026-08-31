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
 * Экран WhatsApp: список диалогов слева и переписка справа.
 *
 * Проверяем то, ради чего экран открывают: кто ждёт ответа, как отсекаются
 * лишние диалоги вкладками и что переписка приходит отдельным запросом.
 */
class WhatsappChatsTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00', 'Asia/Almaty')->utc());
    }

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

    public function test_в_списке_видно_сколько_ждут_ответа(): void
    {
        ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Айгуль Сериковна',
            'phone' => '+7 (777) 000-00-01',
        ]);
        $this->message('77770000001', false, '2026-08-20 10:00', 'есть корт вечером?');

        $this->actingAs($this->admin)->get(route('club.whatsapp.index'))
            ->assertOk()
            ->assertSee('Айгуль Сериковна')
            ->assertSee('есть корт вечером?')
            ->assertSee('ждёт 2 ч');
    }

    public function test_последнее_слово_клуба_помечено_ответили(): void
    {
        $this->message('77770000002', false, '2026-08-20 10:00', 'а свободно?');
        $this->message('77770000002', true, '2026-08-20 10:04', 'да, приходите');

        $this->actingAs($this->admin)->get(route('club.whatsapp.index'))
            ->assertOk()
            ->assertSee('ответили')
            ->assertSee('Вы:');
    }

    public function test_вкладка_ждут_ответа_убирает_закрытые_диалоги(): void
    {
        $this->message('77770000003', false, '2026-08-20 10:00', 'вопрос без ответа');
        $this->message('77770000004', false, '2026-08-20 10:00', 'закрытый вопрос');
        $this->message('77770000004', true, '2026-08-20 10:02', 'ответили сразу');

        $this->actingAs($this->admin)->get(route('club.whatsapp.index', ['filter' => 'waiting']))
            ->assertOk()
            ->assertSee('вопрос без ответа')
            ->assertDontSee('закрытый вопрос');
    }

    public function test_вкладка_новые_люди_это_те_кому_ни_разу_не_отвечали(): void
    {
        $this->message('77770000005', false, '2026-08-20 11:00', 'первый раз пишу');
        $this->message('77770000006', true, '2026-08-19 11:00', 'здравствуйте');
        $this->message('77770000006', false, '2026-08-20 11:00', 'снова я');

        $this->actingAs($this->admin)->get(route('club.whatsapp.index', ['filter' => 'new']))
            ->assertOk()
            ->assertSee('первый раз пишу')
            ->assertDontSee('снова я');
    }

    public function test_переписка_приходит_отдельным_запросом(): void
    {
        $this->message('77770000007', false, '2026-08-19 10:00', 'здравствуйте');
        $this->message('77770000007', true, '2026-08-19 10:30', 'добрый день');
        $this->message('77770000007', false, '2026-08-20 11:00', 'а на завтра?');

        $response = $this->actingAs($this->admin)
            ->get(route('club.whatsapp.panel', '77770000007'));

        $response->assertOk()
            ->assertSee('здравствуйте')
            ->assertSee('добрый день')
            ->assertSee('а на завтра?')
            ->assertSee('19 августа 2026')
            ->assertSee('wa.me/77770000007', false)
            // Панель — кусок разметки для правой колонки, не целая страница.
            ->assertDontSee('<body', false);
    }

    public function test_панель_неизвестного_номера_отвечает_404(): void
    {
        $this->actingAs($this->admin)
            ->get(route('club.whatsapp.panel', '77779999999'))
            ->assertNotFound();
    }

    public function test_служебное_событие_в_списке_названо_по_человечески(): void
    {
        $this->message('77770000008', false, '2026-08-20 10:00', '', ['type' => 'action']);

        $this->actingAs($this->admin)->get(route('club.whatsapp.index'))
            ->assertOk()
            ->assertSee('служебное событие');
    }

    public function test_спасибо_после_ответа_закрывает_диалог(): void
    {
        $this->message('77770000009', false, '2026-08-20 10:00', 'а корт свободен?');
        $this->message('77770000009', true, '2026-08-20 10:05', 'да, свободен');
        $this->message('77770000009', false, '2026-08-20 10:07', 'Спасибо!');

        $this->assertCount(0, WhatsappSla::waitingChats($this->club->id),
            'благодарность — точка в разговоре, а не новый вопрос');

        $this->actingAs($this->admin)->get(route('club.whatsapp.index', ['filter' => 'waiting']))
            ->assertOk()
            ->assertDontSee('Спасибо!');
    }

    public function test_благодарность_с_вопросом_остаётся_в_ожидании(): void
    {
        $this->message('77770000010', false, '2026-08-20 10:00', 'а корт свободен?');
        $this->message('77770000010', true, '2026-08-20 10:05', 'да, свободен');
        $this->message('77770000010', false, '2026-08-20 10:07', 'Спасибо, а на завтра есть?');

        $this->assertCount(1, WhatsappSla::waitingChats($this->club->id));
    }

    public function test_благодарность_без_единого_ответа_остаётся(): void
    {
        // Клубу написали «спасибо» первым же сообщением и никто не ответил:
        // человека всё равно нельзя терять из виду.
        $this->message('77770000011', false, '2026-08-20 10:00', 'спасибо');

        $this->assertCount(1, WhatsappSla::waitingChats($this->club->id));
    }

    public function test_что_считается_точкой_в_разговоре(): void
    {
        foreach (['Спасибо!', 'спс', 'Ок 👌', 'Хорошо, благодарю', 'Great.', 'thank you very much',
                  '👍', 'Понял, спасибо', 'ok', 'А, принято', 'До встречи!', 'Супер, напишу, спасибо 🙏',
                  'как скажете)', 'Благодарим вас за обращение🙏.'] as $body) {
            $this->assertTrue(WhatsappSla::isClosing($body), "«{$body}» — это точка");
        }

        foreach (['Спасибо, а во сколько?', 'ок, тогда бронируйте на 19:00', 'Great, can I book it?',
                  'спасибо за вчера, хочу записаться ещё раз на среду', '', 'да?'] as $body) {
            $this->assertFalse(WhatsappSla::isClosing($body), "«{$body}» — не точка");
        }
    }

    public function test_в_превью_видно_последнее_живое_сообщение(): void
    {
        $this->message('77770000012', false, '2026-08-20 10:00', 'а можно ракетку?');
        $this->message('77770000012', false, '2026-08-20 10:01', '', ['type' => 'action']);

        $this->actingAs($this->admin)->get(route('club.whatsapp.index'))
            ->assertOk()
            ->assertSee('а можно ракетку?');
    }
    public function test_меню_бизнес_бота_не_считается_обращением(): void
    {
        // Автоответчик магазина шлёт кнопки «Был ли решён ваш вопрос?» —
        // отвечать там некому.
        $this->message('77770000013', true, '2026-08-20 09:30', 'как сделать заказ?');
        $this->message('77770000013', false, '2026-08-20 09:31', 'Выберите раздел', ['type' => 'list']);
        $this->message('77770000013', false, '2026-08-20 09:31', 'Был ли решён ваш вопрос?', ['type' => 'buttons']);

        $this->assertCount(0, WhatsappSla::waitingChats($this->club->id));
    }
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
