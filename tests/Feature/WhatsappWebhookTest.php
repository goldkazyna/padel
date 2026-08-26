<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Приём входящих WhatsApp через вебхук Whapi.Cloud и их чтение в CRM.
 * Интеграция мягкая: ничего не отправляем, только принимаем и показываем.
 */
class WhatsappWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private string $secret = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        config([
            'services.whapi.webhook_secret' => $this->secret,
            'services.whapi.club_id' => $this->club->id,
        ]);
    }

    private function payload(array $override = []): array
    {
        return [
            'channel_id' => 'IRONMN-9RPDB',
            'event' => ['type' => 'messages', 'event' => 'post'],
            'messages' => [array_merge([
                'id' => 'wamid.TEST1',
                'from_me' => false,
                'type' => 'text',
                'chat_id' => '77779001122@s.whatsapp.net',
                'from' => '77779001122',
                'from_name' => 'Айдос',
                'timestamp' => 1787000000,
                'text' => ['body' => 'Здравствуйте, есть корт на вечер?'],
            ], $override)],
        ];
    }

    private function send(array $payload, ?string $secret = null)
    {
        return $this->postJson('/api/whapi/webhook/' . ($secret ?? $this->secret), $payload);
    }

    public function test_входящее_сообщение_сохраняется(): void
    {
        $this->send($this->payload())->assertOk()->assertJson(['ok' => true, 'saved' => 1]);

        $m = WhatsappMessage::first();
        $this->assertSame('77779001122', $m->phone);
        $this->assertSame('Здравствуйте, есть корт на вечер?', $m->body);
        $this->assertSame('Айдос', $m->author_name);
        $this->assertFalse($m->from_me);
        $this->assertSame($this->club->id, $m->club_id);
        $this->assertSame('IRONMN-9RPDB', $m->channel_id);
        $this->assertSame('2026-08-17', $m->sent_at->toDateString());
    }

    public function test_повторный_вебхук_не_плодит_дубли(): void
    {
        // Whapi шлёт пакет заново, если мы ответили ошибкой или медленно.
        $this->send($this->payload())->assertOk();
        $this->send($this->payload())->assertJson(['saved' => 0]);

        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_чужой_секрет_отбивается(): void
    {
        $this->send($this->payload(), 'wrong')->assertNotFound();

        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_исходящее_различается_и_берёт_номер_из_чата(): void
    {
        $this->send($this->payload([
            'id' => 'wamid.OUT1',
            'from_me' => true,
            'from' => '77066938453',            // наш номер
            'chat_id' => '77779001122@s.whatsapp.net',
            'text' => ['body' => 'Да, свободно в 19:00'],
        ]))->assertOk();

        $m = WhatsappMessage::first();
        $this->assertTrue($m->from_me);
        $this->assertSame('77779001122', $m->phone, 'у исходящего собеседник — в chat_id');
    }

    public function test_нетекстовое_сообщение_подписывается_типом(): void
    {
        $this->send($this->payload([
            'id' => 'wamid.IMG1',
            'type' => 'image',
            'text' => null,
            'image' => ['link' => 'https://example.test/a.jpg'],
        ]))->assertOk();

        $m = WhatsappMessage::first();
        $this->assertSame('image', $m->type);
        $this->assertSame('📷 фото', $m->preview());
        $this->assertNotEmpty($m->payload, 'сырой пакет должен сохраниться целиком');
    }

    public function test_подпись_берётся_из_caption_у_картинки(): void
    {
        $this->send($this->payload([
            'id' => 'wamid.IMG2',
            'type' => 'image',
            'text' => null,
            'image' => ['link' => 'https://example.test/a.jpg', 'caption' => 'Вот чек'],
        ]))->assertOk();

        $this->assertSame('Вот чек', WhatsappMessage::first()->body);
    }

    public function test_в_crm_виден_диалог_и_имя_клиента(): void
    {
        ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Айдос Жумабеков',
            'phone' => '+7 (777) 900-11-22',
        ]);
        $this->send($this->payload())->assertOk();

        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->club->id);

        $this->actingAs($admin)->get(route('club.whatsapp.index'))
            ->assertOk()
            ->assertSee('Айдос Жумабеков')          // имя из карточки, а не из WhatsApp
            ->assertSee('есть корт на вечер');

        $this->actingAs($admin)->get(route('club.whatsapp.show', '77779001122'))
            ->assertOk()
            ->assertSee('Здравствуйте, есть корт на вечер?');
    }

    public function test_сообщения_в_диалоге_разложены_по_дням(): void
    {
        $this->send($this->payload(['id' => 'a', 'timestamp' => 1787000000]))->assertOk();
        $this->send($this->payload(['id' => 'b', 'timestamp' => 1787100000]))->assertOk();

        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->club->id);

        $response = $this->actingAs($admin)->get(route('club.whatsapp.show', '77779001122'));
        // Дни местные (Алматы, +5): метка 20:53 UTC — это уже 18-е в клубе.
        $response->assertOk()
            ->assertSee('18 августа 2026')
            ->assertSee('19 августа 2026');
    }

    public function test_опрос_отдаёт_только_новые_сообщения(): void
    {
        $this->send($this->payload(['id' => 'a', 'timestamp' => 1787000000]))->assertOk();
        $first = WhatsappMessage::first();

        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->club->id);

        // Пока нового нет — вкладке отдавать нечего.
        $this->actingAs($admin)
            ->getJson(route('club.whatsapp.chat-updates', '77779001122') . '?after=' . $first->id)
            ->assertOk()->assertJsonCount(0, 'messages');

        $this->send($this->payload([
            'id' => 'b', 'timestamp' => 1787100000,
            'text' => ['body' => 'И ещё вопрос'],
        ]))->assertOk();

        $this->actingAs($admin)
            ->getJson(route('club.whatsapp.chat-updates', '77779001122') . '?after=' . $first->id)
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.text', 'И ещё вопрос')
            ->assertJsonPath('messages.0.day', '19 августа 2026')
            ->assertJsonPath('messages.0.from_me', false);
    }

    public function test_маячок_списка_отдаёт_последний_id(): void
    {
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->club->id);

        $this->actingAs($admin)->getJson(route('club.whatsapp.updates'))
            ->assertOk()->assertJson(['last_id' => 0]);

        $this->send($this->payload())->assertOk();

        $this->actingAs($admin)->getJson(route('club.whatsapp.updates'))
            ->assertOk()->assertJson(['last_id' => WhatsappMessage::first()->id]);
    }

    public function test_событие_в_конце_адреса_тоже_принимается(): void
    {
        // Whapi настроен так, что дописывает имя события в путь: .../messages.
        $this->postJson('/api/whapi/webhook/' . $this->secret . '/messages', $this->payload())
            ->assertOk()->assertJson(['saved' => 1]);

        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_статусы_доставки_ничего_не_ломают(): void
    {
        // На тот же адрес прилетают статусы «доставлено/прочитано» — сообщений в них нет.
        $this->postJson('/api/whapi/webhook/' . $this->secret . '/statuses', [
            'channel_id' => 'IRONMN-9RPDB',
            'statuses' => [['id' => 'wamid.TEST1', 'status' => 'delivered']],
        ])->assertOk()->assertJson(['saved' => 0]);
    }

    public function test_пустой_пакет_не_ломает_приём(): void
    {
        $this->send(['channel_id' => 'IRONMN-9RPDB', 'messages' => []])
            ->assertOk()->assertJson(['saved' => 0]);
    }
}
