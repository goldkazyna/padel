<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use App\Models\WhatsappAnalysis;
use App\Models\WhatsappMessage;
use App\Services\WhatsappAnalysisService;
use App\Support\WhatsappDayReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Разбор дня переписки: цифры считает система, объяснение — Claude.
 * В тестах API замокан: нам важно, что мы отправляем и как разбираем ответ.
 */
class WhatsappAnalysisTest extends TestCase
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
            'services.whapi.sla_minutes' => 5,
            'services.anthropic.key' => 'test-key',
            'services.whapi.analysis_model' => 'claude-sonnet-5',
            'app.schedule_timezone' => 'Asia/Almaty',
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function message(string $phone, bool $fromMe, string $localTime, string $body): WhatsappMessage
    {
        return WhatsappMessage::create([
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
        ]);
    }

    private function fakeClaude(array $report): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($report, JSON_UNESCAPED_UNICODE)]],
            ]),
        ]);
    }

    public function test_время_ответа_считается_по_первому_сообщению_серии(): void
    {
        $this->message('77770000001', false, '2026-08-20 10:00', 'есть корт на вечер?');
        $this->message('77770000001', false, '2026-08-20 10:03', 'алло');
        $this->message('77770000001', true, '2026-08-20 10:20', 'да, есть в 19:00');

        $report = WhatsappDayReport::build($this->club->id, Carbon::parse('2026-08-20', 'Asia/Almaty'));

        $this->assertSame(1, $report['metrics']['dialogs']);
        $this->assertSame(1, $report['metrics']['requests']);
        $this->assertSame(20, $report['metrics']['worst'], 'отсчёт от первого сообщения серии');
        $this->assertSame(1, $report['metrics']['slow'], 'дольше 5 минут — уже медленно');
        $this->assertSame(0, $report['metrics']['unanswered']);
    }

    public function test_два_обращения_за_день_считаются_отдельно(): void
    {
        $this->message('77770000002', false, '2026-08-20 10:00', 'сколько стоит час?');
        $this->message('77770000002', true, '2026-08-20 10:02', '12 000');
        $this->message('77770000002', false, '2026-08-20 15:00', 'а в выходные?');
        $this->message('77770000002', true, '2026-08-20 15:30', '15 000');

        $report = WhatsappDayReport::build($this->club->id, Carbon::parse('2026-08-20', 'Asia/Almaty'));

        $this->assertSame(2, $report['metrics']['requests']);
        $this->assertSame(2, $report['metrics']['answered']);
        $this->assertSame(16, $report['metrics']['median'], 'медиана из 2 и 30 минут');
    }

    public function test_вечернее_сообщение_с_ответом_утром_не_считается_молчанием(): void
    {
        $this->message('77770000003', false, '2026-08-20 22:50', 'бронь на завтра?');
        $this->message('77770000003', true, '2026-08-21 09:05', 'доброе утро, забронировал');

        $report = WhatsappDayReport::build($this->club->id, Carbon::parse('2026-08-20', 'Asia/Almaty'));

        $this->assertSame(0, $report['metrics']['unanswered'], 'ответ пришёл на открытии');
        $this->assertSame(15, $report['metrics']['worst'], '10 минут вечером + 5 утром');
    }

    public function test_обращение_без_ответа_видно_в_цифрах(): void
    {
        $this->message('77770000004', false, '2026-08-20 12:00', 'здравствуйте, есть места?');

        $report = WhatsappDayReport::build($this->club->id, Carbon::parse('2026-08-20', 'Asia/Almaty'));

        $this->assertSame(1, $report['metrics']['unanswered']);
        $this->assertSame(0, $report['metrics']['answered']);
        $this->assertTrue($report['dialogs'][0]['is_new'], 'написал впервые');
    }

    public function test_разбор_сохраняется_и_повторно_модель_не_дёргается(): void
    {
        $this->message('77770000005', false, '2026-08-20 12:00', 'а корт свободен?');
        $this->fakeClaude([
            'verdict' => 'День слабый: одно обращение осталось без ответа.',
            'lost_sales' => [['phone' => '0005', 'what' => 'корт на вечер', 'why' => 'не ответили', 'quote' => 'а корт свободен?']],
            'slow' => [],
            'quality' => [],
            'good' => [],
            'actions' => ['Отвечать в течение 5 минут'],
        ]);

        $service = app(WhatsappAnalysisService::class);
        $day = Carbon::parse('2026-08-20', 'Asia/Almaty');

        $first = $service->analyze($this->club->id, $day, false, $this->admin->id);
        $this->assertStringContainsString('без ответа', $first->report['verdict']);
        $this->assertSame('корт на вечер', $first->report['lost_sales'][0]['what']);
        $this->assertSame(1, $first->metrics['unanswered']);
        Http::assertSentCount(1);

        // Повторный вызов берёт готовое: запрос к Claude стоит денег.
        $service->analyze($this->club->id, $day, false, $this->admin->id);
        Http::assertSentCount(1);

        // А принудительный — пересобирает.
        $service->analyze($this->club->id, $day, true, $this->admin->id);
        Http::assertSentCount(2);
        $this->assertSame(1, WhatsappAnalysis::count(), 'на день — один разбор');
    }

    public function test_день_без_переписки_не_уходит_в_модель(): void
    {
        Http::fake();

        $this->expectExceptionMessage('разбирать нечего');
        app(WhatsappAnalysisService::class)
            ->analyze($this->club->id, Carbon::parse('2026-08-20', 'Asia/Almaty'));
    }

    public function test_экран_показывает_цифры_и_готовый_разбор(): void
    {
        $this->message('77770000006', false, '2026-08-20 12:00', 'сколько стоит аренда?');
        $this->message('77770000006', true, '2026-08-20 12:40', '12 000 в час');

        $this->fakeClaude([
            'verdict' => 'Отвечали медленно, но продали.',
            'lost_sales' => [],
            'slow' => [['phone' => '0006', 'waited' => '40 минут', 'what' => 'цена аренды']],
            'quality' => [['issue' => 'Сухой ответ ценой', 'example' => '12 000 в час', 'fix' => 'Предложить свободное время']],
            'good' => [],
            'actions' => ['Не отвечать одной цифрой'],
        ]);

        $this->actingAs($this->admin)->post(route('club.whatsapp.analysis.run'), [
            'date' => '2026-08-20',
        ])->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('club.whatsapp.analysis', ['date' => '2026-08-20']))
            ->assertOk()
            ->assertSee('Отвечали медленно, но продали.')
            ->assertSee('Сухой ответ ценой')
            ->assertSee('Не отвечать одной цифрой')
            ->assertSee('40 мин');            // наша цифра, не модельная
    }

    public function test_ошибка_модели_показывается_человеку(): void
    {
        $this->message('77770000007', false, '2026-08-20 12:00', 'привет');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->actingAs($this->admin)->post(route('club.whatsapp.analysis.run'), [
            'date' => '2026-08-20',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, WhatsappAnalysis::count());
    }

    public function test_в_запрос_уходят_диалоги_и_наши_цифры(): void
    {
        $this->message('77770000008', false, '2026-08-20 12:00', 'нужен корт в субботу');
        $this->fakeClaude(['verdict' => 'ок', 'lost_sales' => [], 'slow' => [], 'quality' => [], 'good' => [], 'actions' => []]);

        app(WhatsappAnalysisService::class)
            ->analyze($this->club->id, Carbon::parse('2026-08-20', 'Asia/Almaty'));

        Http::assertSent(function ($request) {
            $body = $request->data();
            $content = $body['messages'][0]['content'];

            return str_contains($content, 'нужен корт в субботу')
                && str_contains($content, 'остался без ответа')
                && str_contains($content, '"unanswered": 1')
                && $body['model'] === 'claude-sonnet-5';
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
