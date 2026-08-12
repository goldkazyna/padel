<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\PaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Счёт клиенту: админ выставляет произвольную сумму, клиент платит по
 * ссылке Plexy, оплата приходит вебхуком.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы',
            'online_payment_enabled' => true,
            'plexy_api_key' => 'test-key',
            'plexy_webhook_secret' => 'webhook-secret',
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function fakeCreate(string $linkId = 'pl_1', string $url = 'https://checkout/x'): void
    {
        Http::fake([
            '*/v1/payment-links' => Http::response([
                'id' => $linkId, 'url' => $url, 'status' => 'active',
            ]),
        ]);
    }

    private function service(): PaymentLinkService
    {
        return app(PaymentLinkService::class);
    }

    // ===== Создание =====

    public function test_creating_link_sends_amount_in_minor_units(): void
    {
        // В Plexy сумма уходит в тиынах: 22 000 ₸ → 2 200 000.
        $this->fakeCreate();

        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000,
            'description' => 'Клубная карта на 10 часов',
            'expires_in_hours' => 24,
        ]);

        Http::assertSent(function ($request) {
            return $request['amount'] === 2200000
                && $request['description'] === 'Клубная карта на 10 часов';
        });

        $this->assertSame('22000.00', $link->amount);
        $this->assertSame('pl_1', $link->plexy_link_id);
        $this->assertSame('https://checkout/x', $link->plexy_url);
        $this->assertSame(PaymentLink::STATUS_PENDING, $link->status);
    }

    public function test_order_reference_is_sent_after_link_gets_id(): void
    {
        // orderReference = paylink-{id}, а id появляется только после
        // сохранения — иначе вебхук не найдёт счёт.
        $this->fakeCreate();

        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 5000, 'description' => 'Ракетка', 'expires_in_hours' => 1,
        ]);

        Http::assertSent(fn ($request) => $request['orderReference'] === 'paylink-' . $link->id);
    }

    public function test_client_is_attached_when_chosen(): void
    {
        $this->fakeCreate();
        $client = ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Иван', 'phone' => '77770001122',
        ]);

        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 10000, 'description' => 'Долг', 'expires_in_hours' => 24,
            'club_client_id' => $client->id,
        ]);

        $this->assertSame($client->id, $link->club_client_id);
        $this->assertSame('Иван', $link->client_name);
        $this->assertSame('77770001122', $link->client_phone);
    }

    public function test_club_without_plexy_cannot_create(): void
    {
        $club = Club::create(['name' => 'Без оплаты', 'address' => 'А']);

        $this->expectException(\RuntimeException::class);
        $this->service()->create($club, $this->admin, [
            'amount' => 1000, 'description' => 'X', 'expires_in_hours' => 1,
        ]);
    }

    public function test_gateway_failure_leaves_no_orphan_record(): void
    {
        // Ссылку не создали — счёт висеть не должен, иначе список забьётся
        // мусором без url.
        Http::fake(['*/v1/payment-links' => Http::response(['message' => 'bad key'], 401)]);

        try {
            $this->service()->create($this->club, $this->admin, [
                'amount' => 1000, 'description' => 'X', 'expires_in_hours' => 1,
            ]);
        } catch (\RuntimeException $e) {
            // ожидаемо
        }

        $this->assertSame(0, PaymentLink::count());
    }

    // ===== Оплата через вебхук =====

    public function test_webhook_marks_link_paid(): void
    {
        $this->fakeCreate();
        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000, 'description' => 'Карта', 'expires_in_hours' => 24,
        ]);

        $this->postJson('/api/payment/webhook/plexy', [
            'name' => 'transaction.charged',
            'data' => ['merchantReference' => 'paylink-' . $link->id, 'status' => 'charged'],
        ], ['Authorization' => 'Bearer webhook-secret'])->assertOk();

        $fresh = $link->fresh();
        $this->assertTrue($fresh->isPaid());
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_webhook_with_wrong_secret_is_rejected(): void
    {
        $this->fakeCreate();
        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000, 'description' => 'Карта', 'expires_in_hours' => 24,
        ]);

        $this->postJson('/api/payment/webhook/plexy', [
            'name' => 'transaction.charged',
            'data' => ['merchantReference' => 'paylink-' . $link->id, 'status' => 'charged'],
        ], ['Authorization' => 'Bearer wrong'])->assertStatus(401);

        $this->assertFalse($link->fresh()->isPaid());
    }

    public function test_failed_transaction_does_not_mark_paid(): void
    {
        $this->fakeCreate();
        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000, 'description' => 'Карта', 'expires_in_hours' => 24,
        ]);

        $this->postJson('/api/payment/webhook/plexy', [
            'name' => 'transaction.rejected',
            'data' => ['merchantReference' => 'paylink-' . $link->id, 'status' => 'rejected'],
        ], ['Authorization' => 'Bearer webhook-secret'])->assertOk();

        $this->assertFalse($link->fresh()->isPaid());
    }

    // ===== Отмена и сверка =====

    public function test_cancel_marks_link_cancelled(): void
    {
        $this->fakeCreate();
        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000, 'description' => 'Карта', 'expires_in_hours' => 24,
        ]);

        Http::fake(['*/cancel' => Http::response(['status' => 'cancelled'])]);

        $this->service()->cancel($link);

        $this->assertSame(PaymentLink::STATUS_CANCELLED, $link->fresh()->status);
    }

    public function test_paid_link_cannot_be_cancelled(): void
    {
        $this->fakeCreate();
        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000, 'description' => 'Карта', 'expires_in_hours' => 24,
        ]);
        $link->update(['status' => PaymentLink::STATUS_PAID]);

        $this->expectException(\RuntimeException::class);
        $this->service()->cancel($link->fresh());
    }

    public function test_sync_picks_up_payment_missed_by_webhook(): void
    {
        $this->fakeCreate();
        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000, 'description' => 'Карта', 'expires_in_hours' => 24,
        ]);

        Http::fake(['*/v1/payment-links/pl_1' => Http::response(['id' => 'pl_1', 'status' => 'paid'])]);

        $this->service()->sync($link);

        $this->assertTrue($link->fresh()->isPaid());
    }

    public function test_sync_marks_expired_link(): void
    {
        $this->fakeCreate();
        $link = $this->service()->create($this->club, $this->admin, [
            'amount' => 22000, 'description' => 'Карта', 'expires_in_hours' => 24,
        ]);

        Http::fake(['*/v1/payment-links/pl_1' => Http::response(['id' => 'pl_1', 'status' => 'expired'])]);

        $this->service()->sync($link);

        $this->assertSame(PaymentLink::STATUS_EXPIRED, $link->fresh()->status);
    }

    // ===== Доступ =====

    public function test_admin_sees_payments_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('club.payments.index'))
            ->assertOk()
            ->assertSee('Счёт клиенту');
    }

    public function test_manager_can_create_link(): void
    {
        $this->fakeCreate();
        $manager = User::factory()->create(['role' => 'club_moderator']);
        $manager->moderatorClubs()->attach($this->club->id);

        $this->actingAs($manager)
            ->post(route('club.payments.store'), [
                'amount' => 15000,
                'description' => 'Тренировка',
                'expires_in_hours' => 24,
            ])
            ->assertRedirect();

        $this->assertSame(1, PaymentLink::count());
        $this->assertSame($manager->id, PaymentLink::first()->created_by);
    }

    public function test_sync_all_updates_only_pending_links(): void
    {
        // Http::fake() при повторном вызове мержит, поэтому задаём всё разом:
        // создание отдаёт разные id по очереди, статусы — по конкретным url.
        Http::fake([
            '*/v1/payment-links/pl_b' => Http::response(['id' => 'pl_b', 'status' => 'paid']),
            '*/v1/payment-links/pl_a' => Http::response([], 500),
            '*/v1/payment-links' => Http::sequence()
                ->push(['id' => 'pl_a', 'url' => 'https://checkout/a', 'status' => 'active'])
                ->push(['id' => 'pl_b', 'url' => 'https://checkout/b', 'status' => 'active']),
        ]);

        $paidAlready = $this->service()->create($this->club, $this->admin, [
            'amount' => 1000, 'description' => 'Уже оплачен', 'expires_in_hours' => 24,
        ]);
        $paidAlready->update(['status' => PaymentLink::STATUS_PAID]);

        $waiting = $this->service()->create($this->club, $this->admin, [
            'amount' => 2000, 'description' => 'Ждёт', 'expires_in_hours' => 24,
        ]);

        $this->assertSame('pl_b', $waiting->plexy_link_id, 'ссылка ждущего счёта');

        $this->actingAs($this->admin)
            ->post(route('club.payments.syncAll'))
            ->assertRedirect();

        $this->assertTrue($waiting->fresh()->isPaid());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pl_a'));
    }

    // ===== Поиск клиента для формы =====

    public function test_client_search_finds_by_name_and_phone(): void
    {
        ClubClient::create(['club_id' => $this->club->id, 'name' => 'Асель', 'phone' => '77771112233']);
        ClubClient::create(['club_id' => $this->club->id, 'name' => 'Бахыт', 'phone' => '77775554433']);

        // Регистр букв здесь не проверяем: на проде MySQL с utf8mb4_*_ci
        // ищет без учёта регистра, а SQLite в тестах — только для латиницы.
        $byName = $this->actingAs($this->admin)
            ->getJson(route('club.payments.clients', ['q' => 'Асе']))
            ->assertOk()->json();
        $this->assertCount(1, $byName);
        $this->assertSame('Асель', $byName[0]['name']);

        // Тот же инпут ищет и по цифрам — менеджеру удобнее вбить телефон.
        $byPhone = $this->actingAs($this->admin)
            ->getJson(route('club.payments.clients', ['q' => '5554']))
            ->assertOk()->json();
        $this->assertCount(1, $byPhone);
        $this->assertSame('Бахыт', $byPhone[0]['name']);
    }

    public function test_client_search_needs_three_characters(): void
    {
        ClubClient::create(['club_id' => $this->club->id, 'name' => 'Асель', 'phone' => '77771112233']);

        $this->actingAs($this->admin)
            ->getJson(route('club.payments.clients', ['q' => 'Ас']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_client_search_does_not_leak_other_clubs(): void
    {
        $other = Club::create(['name' => 'Другой', 'address' => 'Б']);
        ClubClient::create(['club_id' => $other->id, 'name' => 'Чужой Клиент', 'phone' => '77770000000']);

        $this->actingAs($this->admin)
            ->getJson(route('club.payments.clients', ['q' => 'Чужой']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_player_cannot_open_payments(): void
    {
        $player = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)->get(route('club.payments.index'))->assertForbidden();
    }

    public function test_link_of_another_club_is_not_visible(): void
    {
        $other = Club::create(['name' => 'Другой', 'address' => 'Б']);
        $foreign = PaymentLink::create([
            'club_id' => $other->id, 'amount' => 9999,
            'description' => 'Чужой счёт', 'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.index'))
            ->assertOk()
            ->assertDontSee('Чужой счёт');

        $this->actingAs($this->admin)
            ->delete(route('club.payments.cancel', $foreign))
            ->assertForbidden();
    }
}
