<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Вкладка «Все платежи»: касса клуба целиком из Plexy.
 *
 * Смысл вкладки в том, что она показывает не только счета из CRM: туда же
 * попадают оплаты броней и турниров из приложения и ссылки, созданные прямо
 * в кабинете Plexy. Раньше клуб видел только свои счета, и касса не сходилась.
 */
class ClubAppPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->club = Club::create([
            'name' => 'Pulse Padel Club',
            'address' => 'А',
            'city' => 'Алматы',
            'online_payment_enabled' => true,
            'plexy_api_key' => 'pr_test',
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    /** Ответ шлюза со всеми видами ссылок сразу. */
    private function fakeTransactions(array $rows): void
    {
        Http::fake([
            '*/v1/transactions*' => Http::response([
                'data' => $rows,
                'page' => 1,
                'size' => 50,
                'total' => count($rows),
            ]),
        ]);
    }

    private function tx(string $reference, int $amount = 12000, string $status = 'TRANSACTION_STATUS_CHARGED'): array
    {
        return [
            'transactionId' => 'tx_' . $reference,
            'amount' => $amount,
            'createdAt' => '2026-09-05T10:14:11.000000Z',
            'rrn' => '624857977792',
            'status' => $status,
            'orderReference' => $reference,
        ];
    }

    private function booking(): CourtBooking
    {
        $court = Court::create([
            'club_id' => $this->club->id,
            'name' => 'Корт 3',
            'open_time' => '08:00:00',
            'close_time' => '22:00:00',
            'slot_duration' => 60,
        ]);

        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => '2026-09-05',
            'start_time' => '19:00:00',
            'end_time' => '20:00:00',
            'client_name' => 'Асхат',
            'status' => 'confirmed',
            'price' => 12000,
            'booked_by' => $this->admin->id,
        ]);
    }

    public function test_показывает_оплату_брони_из_приложения(): void
    {
        $booking = $this->booking();
        $this->fakeTransactions([$this->tx('booking-' . $booking->id)]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('Бронь')
            ->assertSee('Корт 3')
            ->assertSee('Асхат')
            ->assertSee('12 000 ₸');
    }

    public function test_платёж_созданный_в_кабинете_plexy_тоже_виден(): void
    {
        // Ровно ради этого вкладка и делалась: такой ссылки в нашей базе нет.
        $this->fakeTransactions([$this->tx('PL-1788525144316', 5000)]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('Вне приложения')
            ->assertSee('PL-1788525144316');
    }

    public function test_счёт_из_crm_подписан_своим_описанием(): void
    {
        $link = PaymentLink::create([
            'club_id' => $this->club->id,
            'created_by' => $this->admin->id,
            'amount' => 22000,
            'description' => 'Аренда ракетки',
            'client_name' => 'Диана',
            'status' => PaymentLink::STATUS_PAID,
            'plexy_link_id' => 'pl_1',
            'expires_at' => now()->addDay(),
        ]);

        $this->fakeTransactions([$this->tx('paylink-' . $link->id, 22000)]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('Счёт')
            ->assertSee('Аренда ракетки')
            ->assertSee('Диана');
    }

    public function test_статусы_переводятся_на_человеческий(): void
    {
        $this->fakeTransactions([
            $this->tx('PL-1', 1000, 'TRANSACTION_STATUS_CHARGED'),
            $this->tx('PL-2', 2000, 'TRANSACTION_STATUS_REJECTED'),
        ]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('Оплачен')
            ->assertSee('Не прошёл')
            ->assertDontSee('TRANSACTION_STATUS_CHARGED');
    }

    public function test_в_сумму_идут_только_прошедшие_платежи(): void
    {
        $this->fakeTransactions([
            $this->tx('PL-1', 10000, 'TRANSACTION_STATUS_CHARGED'),
            $this->tx('PL-2', 90000, 'TRANSACTION_STATUS_REJECTED'),
        ]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('10 000 ₸')
            ->assertSee('1</b>', false);
    }

    public function test_упавший_шлюз_не_роняет_страницу(): void
    {
        Http::fake(['*/v1/transactions*' => Http::response(['message' => 'gateway down'], 500)]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('Не удалось получить платежи');
    }

    public function test_клубу_без_ключей_объясняем_что_делать(): void
    {
        $this->club->update(['plexy_api_key' => null, 'online_payment_enabled' => false]);

        $this->actingAs($this->admin)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('Онлайн-оплата не настроена');
    }

    public function test_чужой_клуб_свои_платежи_не_увидит(): void
    {
        $stranger = User::factory()->create(['role' => 'club_admin']);
        $other = Club::create(['name' => 'Другой', 'address' => 'Б']);
        $stranger->adminClubs()->attach($other->id);

        $this->fakeTransactions([$this->tx('PL-1')]);

        // Контроллер берёт клуб пользователя, а не из запроса: чужие ключи
        // подставить нечем, и страница показывает свой клуб.
        $this->actingAs($stranger)
            ->get(route('club.payments.app'))
            ->assertOk()
            ->assertSee('Другой')
            ->assertDontSee('Pulse Padel Club');
    }
}
