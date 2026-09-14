<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Возврат средств по транзакции Plexy.
 *
 * Это деньги наружу, поэтому проверок больше обычного: возврат делает только
 * администратор клуба, только по прошедшему платежу и не больше оплаченного.
 * Всё это спрашиваем у шлюза, а не верим форме.
 */
class PlexyRefundTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private CourtBooking $booking;

    private const TX = '01a09b42-0903-75b3-9007-f2ee24c2ac30';

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Pulse', 'address' => 'А', 'city' => 'Алматы',
            'online_payment_enabled' => true, 'plexy_api_key' => 'pr_test',
        ]);
        $court = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1',
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now('Asia/Almaty')->addDays(3)->format('Y-m-d'),
            'start_time' => '20:00', 'end_time' => '21:00',
            'client_name' => 'Евгений', 'client_phone' => '77771112233',
            'status' => 'confirmed', 'price' => 32000,
            'booked_by' => $this->admin->id,
            'is_paid' => true, 'payment_status' => 'paid', 'paid_at' => now(),
            'payment_method' => 'plexy',
        ]);
    }

    /** Шлюз: транзакция и успешный возврат. */
    private function fakeGateway(string $status = 'TRANSACTION_STATUS_CHARGED', float $amount = 32000): void
    {
        Http::fake([
            'api.plexypay.com/v1/transactions/*' => Http::response([
                'transactionId' => self::TX,
                'paymentId' => self::TX,
                'status' => $status,
                'amount' => $amount,
                'orderReference' => 'booking-' . $this->booking->id,
            ]),
            'api.plexypay.com/v1/payments/*/refund' => Http::response(['success' => true]),
        ]);
    }

    private function refund(array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)
            ->post("/club/payments/app/" . self::TX . "/refund",
                array_merge(['amount' => 32000], $body));
    }

    public function test_возврат_уходит_в_шлюз(): void
    {
        $this->fakeGateway();

        $this->refund()->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/refund')
            && $r->method() === 'POST'
            && $r['amount'] == 32000);
    }

    public function test_полный_возврат_снимает_оплату_с_брони(): void
    {
        $this->fakeGateway();

        $this->refund();

        $this->booking->refresh();
        $this->assertFalse((bool) $this->booking->is_paid, 'бронь больше не оплачена');
        $this->assertSame('refunded', $this->booking->payment_status);
    }

    public function test_частичный_возврат_оплату_не_снимает(): void
    {
        $this->fakeGateway();

        $this->refund(['amount' => 10000]);

        $this->assertTrue((bool) $this->booking->fresh()->is_paid,
            'часть денег клуб получил — бронь остаётся оплаченной');
    }

    public function test_больше_оплаченного_вернуть_нельзя(): void
    {
        $this->fakeGateway();

        $this->refund(['amount' => 50000])->assertSessionHas('error');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/refund') && $r->method() === 'POST');
    }

    public function test_неудавшийся_платёж_не_вернуть(): void
    {
        $this->fakeGateway('TRANSACTION_STATUS_REJECTED');

        $this->refund()->assertSessionHas('error');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/refund') && $r->method() === 'POST');
    }

    /** Холд — деньги придержаны: их не возвращают, а отпускают. */
    public function test_по_холду_снимаем_авторизацию(): void
    {
        Http::fake([
            'api.plexypay.com/v1/transactions/*' => Http::response([
                'transactionId' => self::TX, 'paymentId' => self::TX,
                // Так холд называет список транзакций — раньше мы ждали
                // только «authorized» и кнопку не показывали.
                'status' => 'TRANSACTION_STATUS_AUTHED', 'amount' => 32000,
                'orderReference' => 'booking-' . $this->booking->id,
            ]),
            'api.plexypay.com/v1/payments/*/cancel' => Http::response(['success' => true]),
            'api.plexypay.com/v1/payments/*/refund' => Http::response(['success' => true]),
        ]);

        $this->refund()->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/cancel'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/refund') && $r->method() === 'POST');
        $this->assertFalse((bool) $this->booking->fresh()->is_paid);
    }

    /**
     * Ссылка заказа в одиночной транзакции зовётся merchantReference —
     * на этом возврат падал 500-й уже после того, как деньги ушли.
     */
    public function test_ссылка_заказа_читается_из_merchantReference(): void
    {
        Http::fake([
            'api.plexypay.com/v1/transactions/*' => Http::response([
                'transactionId' => self::TX, 'paymentId' => self::TX,
                'status' => 'charged', 'amount' => 32000,
                'merchantReference' => 'booking-' . $this->booking->id,
            ]),
            'api.plexypay.com/v1/payments/*/refund' => Http::response(['success' => true]),
        ]);

        $this->refund()->assertRedirect()->assertSessionHas('success');

        $this->assertFalse((bool) $this->booking->fresh()->is_paid);
    }

    /** Деньги ушли — страница обязана открыться, чем бы ни кончились отметки. */
    public function test_возврат_без_ссылки_заказа_не_падает(): void
    {
        Http::fake([
            'api.plexypay.com/v1/transactions/*' => Http::response([
                'transactionId' => self::TX, 'paymentId' => self::TX,
                'status' => 'charged', 'amount' => 32000,
                // Ни orderReference, ни merchantReference — платёж вне приложения.
            ]),
            'api.plexypay.com/v1/payments/*/refund' => Http::response(['success' => true]),
        ]);

        $this->refund()->assertRedirect()->assertSessionHas('success');
    }

    public function test_менеджеру_возврат_недоступен(): void
    {
        $this->fakeGateway();

        $manager = User::factory()->create(['role' => 'club_moderator']);
        $manager->moderatorClubs()->attach($this->club->id);

        $this->refund([], $manager)->assertForbidden();
    }

    public function test_отказ_шлюза_показываем_человеку(): void
    {
        Http::fake([
            'api.plexypay.com/v1/transactions/*' => Http::response([
                'transactionId' => self::TX, 'paymentId' => self::TX,
                'status' => 'TRANSACTION_STATUS_CHARGED', 'amount' => 32000,
                'orderReference' => 'booking-' . $this->booking->id,
            ]),
            'api.plexypay.com/v1/payments/*/refund' => Http::response([
                'message' => 'failed to handle command: something went wrong, transaction can not be refunded: already refunded',
            ], 500),
        ]);

        $this->refund()->assertSessionHas('error', fn ($msg) => str_contains($msg, 'already refunded'));
        $this->assertTrue((bool) $this->booking->fresh()->is_paid, 'отказ — бронь не трогаем');
    }

    public function test_кнопка_видна_админу_и_скрыта_менеджеру(): void
    {
        Http::fake(['api.plexypay.com/v1/transactions*' => Http::response([
            'data' => [[
                'transactionId' => self::TX, 'amount' => 32000,
                'status' => 'TRANSACTION_STATUS_CHARGED',
                'orderReference' => 'booking-' . $this->booking->id,
                'createdAt' => now()->toIso8601String(),
            ]],
            'page' => 1, 'size' => 50, 'total' => 1,
        ])]);

        $html = $this->actingAs($this->admin)->get('/club/payments/app')->assertOk()->getContent();
        $this->assertStringContainsString('apay-refund-btn', $html, 'админ видит кнопку');
        $this->assertStringContainsString('openRefund', $html);

        $manager = User::factory()->create(['role' => 'club_moderator']);
        $manager->moderatorClubs()->attach($this->club->id);

        $html = $this->actingAs($manager)->get('/club/payments/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('apay-refund-btn', $html, 'менеджеру кнопки нет');
    }
}
