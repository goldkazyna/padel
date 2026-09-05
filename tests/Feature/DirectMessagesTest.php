<?php

namespace Tests\Feature;

use App\Models\ContentReport;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Личная переписка, блокировка и жалоба.
 *
 * Писать можно любому игроку — это решение продукта, а не недосмотр. Поэтому
 * здесь же проверяется всё, что делает открытую переписку жизнеспособной:
 * лимиты, блокировка и жалоба.
 */
class DirectMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);

        $this->me = User::factory()->create(['name' => 'Денис']);
        $this->other = User::factory()->create(['name' => 'Асхат', 'level' => 3.25, 'rating' => 3240]);
    }

    private function send(User $to, string $text)
    {
        return $this->postJson("/api/mobile/messages/{$to->id}", ['text' => $text]);
    }

    public function test_можно_написать_любому_игроку(): void
    {
        Sanctum::actingAs($this->me);

        $this->send($this->other, 'Привет! Сыграем завтра?')
            ->assertOk()
            ->assertJsonPath('message.is_mine', true)
            ->assertJsonPath('message.text', 'Привет! Сыграем завтра?');

        $this->assertSame(1, ConversationMessage::count());
    }

    public function test_диалог_один_с_какой_бы_стороны_ни_открыли(): void
    {
        Sanctum::actingAs($this->me);
        $this->send($this->other, 'Привет')->assertOk();

        Sanctum::actingAs($this->other);
        $this->send($this->me, 'Привет-привет')->assertOk();

        $this->assertSame(1, Conversation::count(), 'пара всегда одна');
        $this->assertSame(2, ConversationMessage::count());
    }

    public function test_получателю_приходит_уведомление(): void
    {
        Sanctum::actingAs($this->me);
        $this->send($this->other, 'Заберёшь ракетку?')->assertOk();

        $notification = Notification::where('user_id', $this->other->id)->latest('id')->first();
        $this->assertSame('direct_message', $notification->type);
        $this->assertSame('Денис', $notification->title);
        $this->assertStringContainsString('ракетку', $notification->body);
    }

    public function test_переписка_отдаётся_по_страницам(): void
    {
        Sanctum::actingAs($this->me);
        foreach (range(1, 5) as $i) {
            $this->send($this->other, "Сообщение {$i}")->assertOk();
        }

        $all = $this->getJson("/api/mobile/messages/{$this->other->id}")->assertOk()->json('messages');
        $this->assertCount(5, $all);

        // Только новее третьего — так экран догружает свежие.
        $after = $this->getJson("/api/mobile/messages/{$this->other->id}?after_id={$all[2]['id']}")
            ->assertOk()->json('messages');
        $this->assertCount(2, $after);
    }

    public function test_повтор_того_же_текста_подряд_не_проходит(): void
    {
        Sanctum::actingAs($this->me);

        $this->send($this->other, 'Ок')->assertOk();
        $this->send($this->other, 'Ок')->assertStatus(422);

        $this->assertSame(1, ConversationMessage::count());
    }

    public function test_правила_показываются_только_в_пустой_переписке(): void
    {
        Sanctum::actingAs($this->me);

        $this->getJson("/api/mobile/messages/{$this->other->id}")
            ->assertOk()
            ->assertJsonPath('show_rules', true);

        $this->send($this->other, 'Привет')->assertOk();

        $this->getJson("/api/mobile/messages/{$this->other->id}")
            ->assertOk()
            ->assertJsonPath('show_rules', false);
    }

    public function test_непрочитанные_считаются_и_сбрасываются(): void
    {
        Sanctum::actingAs($this->other);
        $this->send($this->me, 'Раз')->assertOk();
        $this->send($this->me, 'Два')->assertOk();

        Sanctum::actingAs($this->me);
        $this->getJson('/api/mobile/messages/unread-count')->assertOk()->assertJsonPath('count', 2);
        $this->getJson('/api/mobile/messages')->assertOk()->assertJsonPath('conversations.0.unread', 2);

        $this->postJson("/api/mobile/messages/{$this->other->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_total', 0);

        $this->getJson('/api/mobile/messages/unread-count')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_список_диалогов_ставит_непрочитанные_наверх(): void
    {
        $quiet = User::factory()->create(['name' => 'Тихий']);

        Sanctum::actingAs($this->me);
        $this->send($quiet, 'Давно не виделись')->assertOk();

        Sanctum::actingAs($this->other);
        $this->send($this->me, 'Ты на корте?')->assertOk();

        Sanctum::actingAs($this->me);
        $rows = $this->getJson('/api/mobile/messages')->assertOk()->json('conversations');

        $this->assertSame('Асхат', $rows[0]['player']['name'], 'непрочитанное выше');
        $this->assertSame(1, $rows[0]['unread']);
        $this->assertSame(0, $rows[1]['unread']);
    }

    public function test_заблокированному_написать_нельзя(): void
    {
        UserBlock::create([
            'user_id' => $this->other->id,
            'blocked_user_id' => $this->me->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->me);

        $this->send($this->other, 'Ну пожалуйста')->assertStatus(403);
        $this->assertSame(0, ConversationMessage::count());
    }

    public function test_блокировка_видна_в_переписке_обеим_сторонам(): void
    {
        Sanctum::actingAs($this->me);
        $this->postJson("/api/mobile/users/{$this->other->id}/block")->assertOk();

        $this->getJson("/api/mobile/messages/{$this->other->id}")
            ->assertOk()
            ->assertJsonPath('blocked_by_me', true)
            ->assertJsonPath('blocked_me', false);

        Sanctum::actingAs($this->other);
        $this->getJson("/api/mobile/messages/{$this->me->id}")
            ->assertOk()
            ->assertJsonPath('blocked_by_me', false)
            ->assertJsonPath('blocked_me', true);
    }

    public function test_заблокированный_диалог_исчезает_из_списка(): void
    {
        Sanctum::actingAs($this->other);
        $this->send($this->me, 'Купи у меня ракетку')->assertOk();

        Sanctum::actingAs($this->me);
        $this->postJson("/api/mobile/users/{$this->other->id}/block")->assertOk();

        $this->getJson('/api/mobile/messages')->assertOk()->assertJsonPath('conversations', []);
    }

    public function test_разблокировка_возвращает_переписку(): void
    {
        Sanctum::actingAs($this->me);
        $this->postJson("/api/mobile/users/{$this->other->id}/block")->assertOk();
        $this->deleteJson("/api/mobile/users/{$this->other->id}/block")->assertOk();

        $this->send($this->other, 'Мир?')->assertOk();
        $this->getJson('/api/mobile/blocks')->assertOk()->assertJsonPath('blocked', []);
    }

    public function test_своё_сообщение_удаляется_чужое_нет(): void
    {
        Sanctum::actingAs($this->me);
        $this->send($this->other, 'Опечатка')->assertOk();
        $message = ConversationMessage::latest('id')->first();

        Sanctum::actingAs($this->other);
        $this->deleteJson("/api/mobile/messages/message/{$message->id}")->assertStatus(403);

        Sanctum::actingAs($this->me);
        $this->deleteJson("/api/mobile/messages/message/{$message->id}")->assertOk();

        $this->assertSame(0, ConversationMessage::count());
    }

    public function test_лимит_на_первые_сообщения_незнакомым(): void
    {
        Sanctum::actingAs($this->me);

        // Пять «холодных» диалогов — предел за час.
        foreach (range(1, 5) as $i) {
            $stranger = User::factory()->create(['name' => "Незнакомец {$i}"]);
            $this->send($stranger, 'Привет, я тренер, беру учеников')->assertOk();
        }

        $sixth = User::factory()->create(['name' => 'Шестой']);
        $this->send($sixth, 'Привет, я тренер, беру учеников')
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    public function test_ответ_в_начатую_переписку_лимитом_не_режется(): void
    {
        // Пять холодных стартов исчерпаны...
        Sanctum::actingAs($this->me);
        foreach (range(1, 5) as $i) {
            $stranger = User::factory()->create(['name' => "Незнакомец {$i}"]);
            $this->send($stranger, 'Привет')->assertOk();
        }

        // ...но ответ там, где собеседник уже написал сам, проходит.
        Sanctum::actingAs($this->other);
        $this->send($this->me, 'Слушай, сыграем?')->assertOk();

        Sanctum::actingAs($this->me);
        $this->send($this->other, 'Давай в четверг')->assertOk();
    }

    public function test_жалоба_сохраняется(): void
    {
        Sanctum::actingAs($this->me);

        $this->postJson('/api/mobile/reports', [
            'user_id' => $this->other->id,
            'reason' => 'spam',
            'comment' => 'Рассылает рекламу тренировок',
        ])->assertOk();

        $report = ContentReport::firstOrFail();
        $this->assertSame($this->me->id, $report->reporter_id);
        $this->assertSame((string) $this->other->id, (string) $report->reportable_id);
        $this->assertSame('spam', $report->reason);
        $this->assertSame(ContentReport::STATUS_NEW, $report->status);
    }

    public function test_жалоба_с_чужой_причиной_не_принимается(): void
    {
        Sanctum::actingAs($this->me);

        $this->postJson('/api/mobile/reports', [
            'user_id' => $this->other->id,
            'reason' => 'не нравится',
        ])->assertStatus(422);

        $this->assertSame(0, ContentReport::count());
    }

    public function test_пустое_сообщение_не_проходит(): void
    {
        Sanctum::actingAs($this->me);

        $this->send($this->other, '   ')->assertStatus(422);
        $this->assertSame(0, ConversationMessage::count());
    }

    public function test_себе_не_пишем(): void
    {
        Sanctum::actingAs($this->me);

        $this->send($this->me, 'Заметка себе')->assertStatus(422);
    }

    public function test_выключенный_тумблер_убирает_пуш_но_не_уведомление(): void
    {
        $this->other->update(['notify_messages' => false]);

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldNotReceive('sendToUser');
        $this->instance(FCMNotificationService::class, $mock);

        Sanctum::actingAs($this->me);
        $this->send($this->other, 'Тихое сообщение')->assertOk();

        // В колокольчике оно всё равно есть — человек выключил звук, а не почту.
        $this->assertSame(1, Notification::where('user_id', $this->other->id)->count());
    }
}
