<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Платежи клуба из приложения: счёт, касса, возврат.
 *
 * Всё то же, что в веб-кабинете: администратор на корте не должен бежать
 * к компьютеру, чтобы выставить счёт или проверить, дошли ли деньги.
 */
class MobileAdminPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Pulse', 'address' => 'А', 'city' => 'Алматы',
            'online_payment_enabled' => true, 'plexy_api_key' => 'pr_test',
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->manager = User::factory()->create(['role' => 'club_moderator']);
        $this->manager->moderatorClubs()->attach($this->club->id);
    }

    private function fakeLinkCreated(): void
    {
        Http::fake([
            'api.plexypay.com/v1/payment-links' => Http::response([
                'id' => 'pl_test1',
                'url' => 'https://checkout.plexypay.com/pl_test1',
                'status' => 'active',
            ]),
        ]);
    }

    public function test_счёт_выставляется_с_телефона(): void
    {
        $this->fakeLinkCreated();
        Sanctum::actingAs($this->admin);

        $link = $this->postJson('/api/mobile/admin/payments', [
            'amount' => 12000,
            'description' => 'Аренда корта',
            'client_name' => 'Иван Петров',
            'client_phone' => '77771112233',
        ])->assertOk()->json('link');

        $this->assertSame('https://checkout.plexypay.com/pl_test1', $link['url']);
        $this->assertEquals(12000, $link['amount']);
        $this->assertSame('pending', $link['status']);
        $this->assertSame(1, PaymentLink::count());
    }

    public function test_менеджер_тоже_может_выставить_счёт(): void
    {
        $this->fakeLinkCreated();
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/mobile/admin/payments', [
            'amount' => 5000,
            'description' => 'Ракетка напрокат',
        ])->assertOk();

        $this->assertSame(1, PaymentLink::count());
    }

    public function test_список_счетов_со_сводкой(): void
    {
        PaymentLink::create([
            'club_id' => $this->club->id, 'created_by' => $this->admin->id,
            'amount' => 10000, 'description' => 'Оплачен', 'status' => 'paid',
            'paid_at' => now(),
        ]);
        PaymentLink::create([
            'club_id' => $this->club->id, 'created_by' => $this->admin->id,
            'amount' => 3000, 'description' => 'Ждёт', 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->admin);
        $body = $this->getJson('/api/mobile/admin/payments')->assertOk()->json();

        $this->assertCount(2, $body['links']);
        $this->assertEquals(10000, $body['summary']['paid_sum']);
        $this->assertSame(1, $body['summary']['pending_count']);
        $this->assertTrue($body['can_refund'], 'админ видит кнопку возврата');
    }

    public function test_менеджеру_возврат_недоступен(): void
    {
        Sanctum::actingAs($this->manager);

        $this->assertFalse(
            $this->getJson('/api/mobile/admin/payments')->assertOk()->json('can_refund')
        );

        $this->postJson('/api/mobile/admin/payments/transactions/tx-1/refund', ['amount' => 100])
            ->assertStatus(403);
    }

    public function test_счёт_отменяется(): void
    {
        Http::fake(['api.plexypay.com/*' => Http::response(['status' => 'cancelled'])]);

        $link = PaymentLink::create([
            'club_id' => $this->club->id, 'created_by' => $this->admin->id,
            'amount' => 3000, 'description' => 'Ошибка', 'status' => 'pending',
            'plexy_link_id' => 'pl_test1',
        ]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/mobile/admin/payments/{$link->id}")->assertOk();

        $this->assertSame('cancelled', $link->fresh()->status);
    }

    public function test_чужой_счёт_не_тронуть(): void
    {
        $other = Club::create(['name' => 'Другой', 'address' => 'Б', 'city' => 'Алматы']);
        $link = PaymentLink::create([
            'club_id' => $other->id, 'created_by' => $this->admin->id,
            'amount' => 1000, 'description' => 'Чужой', 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/mobile/admin/payments/{$link->id}")->assertForbidden();
    }

    public function test_касса_показывает_платежи(): void
    {
        Http::fake(['api.plexypay.com/v1/transactions*' => Http::response([
            'data' => [[
                'transactionId' => '01a0-tx', 'amount' => 26000,
                'status' => 'TRANSACTION_STATUS_CHARGED', 'rrn' => '625421045265',
                'orderReference' => 'paylink-1', 'createdAt' => now()->toIso8601String(),
            ]],
            'page' => 1, 'size' => 50, 'total' => 1,
        ])]);

        Sanctum::actingAs($this->admin);
        $rows = $this->getJson('/api/mobile/admin/payments/transactions')
            ->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertEquals(26000, $rows[0]['amount']);
        $this->assertSame('paid', $rows[0]['status']);
    }

    public function test_касса_не_падает_когда_шлюз_молчит(): void
    {
        Http::fake(['api.plexypay.com/*' => Http::response('', 502)]);

        Sanctum::actingAs($this->admin);
        $body = $this->getJson('/api/mobile/admin/payments/transactions')->assertOk()->json();

        $this->assertSame([], $body['rows']);
        $this->assertStringContainsString('Шлюз', $body['message']);
    }

    public function test_возврат_уходит_в_шлюз(): void
    {
        Http::fake([
            'api.plexypay.com/v1/transactions/*' => Http::response([
                'transactionId' => 'tx-1', 'paymentId' => 'tx-1',
                'status' => 'charged', 'amount' => 26000,
                'merchantReference' => 'paylink-1',
            ]),
            'api.plexypay.com/v1/payments/*/refund' => Http::response(['success' => true]),
        ]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/mobile/admin/payments/transactions/tx-1/refund', ['amount' => 26000])
            ->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/refund') && $r->method() === 'POST');
    }

    public function test_подсказка_клиентов(): void
    {
        ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Ерлан Ким', 'phone' => '77010001122',
        ]);

        Sanctum::actingAs($this->admin);

        $short = $this->getJson('/api/mobile/admin/payments/clients?q=Ер')->assertOk()->json('clients');
        $this->assertCount(0, $short, 'короче трёх символов не ищем');

        $found = $this->getJson('/api/mobile/admin/payments/clients?q=Ерл')->assertOk()->json('clients');
        $this->assertCount(1, $found);
        $this->assertSame('Ерлан Ким', $found[0]['name']);
    }
}
