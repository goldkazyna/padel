<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Оплата участия в турнире картой.
 *
 * Правило простое: заплатил — сразу в основном списке, без модерации.
 * Пока платёж жив, место за человеком держится: иначе он вернётся с
 * оплатой на занятое место.
 */
class TournamentOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-123';

    private Club $club;
    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы',
            'online_payment_enabled' => true,
            'tournament_payment_enabled' => true,
            'plexy_api_key' => 'pr_test',
            'plexy_webhook_secret' => self::SECRET,
        ]);

        $this->player = User::factory()->create(['level' => 3.0, 'rating' => 3000]);
    }

    private function tournament(array $extra = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'club_id' => $this->club->id,
            'price' => 14000,
            'max_participants' => 8,
            'status' => 'open',
            'type' => 'americano',
        ], $extra));
    }

    /** Plexy отвечает ссылкой на оплату. */
    private function fakePlexyLink(string $id = 'pl_1'): void
    {
        Http::fake([
            'api.plexypay.com/v1/payment-links' => Http::response([
                'id' => $id, 'url' => 'https://checkout.plexypay.com/' . $id, 'status' => 'active',
            ]),
        ]);
    }

    /** Вебхук Plexy об оплате. */
    private function webhook(TournamentPayment $payment, string $secret = self::SECRET, string $event = 'transaction.charged')
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $secret])
            ->postJson('/api/payment/webhook/plexy', [
                'name' => $event,
                'data' => [
                    'id' => 'txn_1',
                    'status' => str_contains($event, 'charged') ? 'charged' : 'rejected',
                    'merchantReference' => $payment->orderReference(),
                ],
            ]);
    }

    public function test_без_галочки_клуба_оплата_не_требуется(): void
    {
        $this->club->update(['tournament_payment_enabled' => false]);
        $tournament = $this->tournament();

        $response = $this->actingAs($this->player, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$tournament->id}");

        $response->assertOk()->assertJsonPath('tournament.payment', null);

        // Обычная запись работает как раньше — через модерацию.
        $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/register")
            ->assertOk();
    }

    public function test_бесплатный_турнир_оплаты_не_требует(): void
    {
        $tournament = $this->tournament(['price' => 0]);

        $this->actingAs($this->player, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('tournament.payment', null);
    }

    public function test_с_галочкой_приложение_видит_оплату_и_обычная_запись_закрыта(): void
    {
        $tournament = $this->tournament();

        $this->actingAs($this->player, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('tournament.payment.required', true)
            ->assertJsonPath('tournament.payment.amount', 14000)
            ->assertJsonPath('tournament.payment.methods', ['card', 'apple_pay', 'google_pay']);

        // Мимо оплаты записаться нельзя.
        $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/register")
            ->assertStatus(400)
            ->assertJsonPath('payment_required', true);

        $this->assertSame(0, $tournament->fresh()->participants()->count());
    }

    public function test_оплата_сажает_в_основной_список_без_модерации(): void
    {
        $tournament = $this->tournament(['moderation_hours' => 2]);
        $this->fakePlexyLink();

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")
            ->assertOk()
            ->assertJsonPath('amount', 14000)
            ->json();

        $this->assertStringContainsString('checkout.plexypay.com', $pay['payment_url']);

        $payment = TournamentPayment::find($pay['payment_id']);
        $this->assertSame(TournamentPayment::STATUS_PENDING, $payment->status);

        // Пока платят — место занято, иначе его уведут.
        $this->assertSame(1, $tournament->fresh()->takenSlotsCount());

        $this->webhook($payment)->assertOk();

        $participant = $tournament->fresh()->participants()->where('user_id', $this->player->id)->first();
        $this->assertNotNull($participant, 'оплативший должен быть в турнире');
        $this->assertSame('registered', $participant->pivot->status, 'без модерации: деньги и есть подтверждение');
        $this->assertSame(TournamentPayment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_оплата_за_друга_считается_за_двоих(): void
    {
        $tournament = $this->tournament();
        $friend = User::factory()->create(['level' => 3.0]);
        $this->fakePlexyLink();

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay", ['friend_user_id' => $friend->id])
            ->assertOk()
            ->assertJsonPath('amount', 28000)
            ->assertJsonPath('players_count', 2)
            ->json();

        $this->assertSame(2, $tournament->fresh()->takenSlotsCount(), 'держим два места');

        $this->webhook(TournamentPayment::find($pay['payment_id']))->assertOk();

        $statuses = $tournament->fresh()->participants()->get()
            ->mapWithKeys(fn ($p) => [$p->id => $p->pivot->status]);

        $this->assertSame('registered', $statuses[$this->player->id]);
        $this->assertSame('registered', $statuses[$friend->id]);
    }

    public function test_повторный_вебхук_не_записывает_дважды(): void
    {
        $tournament = $this->tournament();
        $this->fakePlexyLink();

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")->json();
        $payment = TournamentPayment::find($pay['payment_id']);

        $this->webhook($payment)->assertOk();
        $this->webhook($payment->fresh())->assertOk();

        $this->assertSame(1, $tournament->fresh()->participants()->count());
    }

    public function test_чужой_секрет_вебхука_не_сажает_в_турнир(): void
    {
        $tournament = $this->tournament();
        $this->fakePlexyLink();

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")->json();

        $this->webhook(TournamentPayment::find($pay['payment_id']), 'чужой-секрет')
            ->assertStatus(401);

        $this->assertSame(0, $tournament->fresh()->participants()->count());
    }

    public function test_неудачная_оплата_освобождает_место(): void
    {
        $tournament = $this->tournament();
        $this->fakePlexyLink();

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")->json();
        $payment = TournamentPayment::find($pay['payment_id']);

        $this->webhook($payment, self::SECRET, 'transaction.rejected')->assertOk();

        $this->assertSame(TournamentPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(0, $tournament->fresh()->takenSlotsCount(), 'место снова свободно');
        $this->assertSame(0, $tournament->fresh()->participants()->count());
    }

    public function test_протухший_платёж_место_не_держит(): void
    {
        $tournament = $this->tournament();
        $this->fakePlexyLink();

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")->json();

        TournamentPayment::where('id', $pay['payment_id'])->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(0, $tournament->fresh()->takenSlotsCount());
    }

    public function test_мест_нет_ссылку_не_даём(): void
    {
        $tournament = $this->tournament(['max_participants' => 1]);
        $other = User::factory()->create(['level' => 3.0]);
        $tournament->participants()->attach($other->id, ['status' => 'registered']);

        $this->fakePlexyLink();

        $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Все места заняты');
    }

    public function test_приложение_узнаёт_об_оплате_даже_без_вебхука(): void
    {
        $tournament = $this->tournament();

        Http::fake([
            'api.plexypay.com/v1/payment-links' => Http::response([
                'id' => 'pl_9', 'url' => 'https://checkout.plexypay.com/pl_9', 'status' => 'active',
            ]),
            'api.plexypay.com/v1/payment-links/pl_9' => Http::response(['id' => 'pl_9', 'status' => 'paid']),
        ]);

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")->json();

        $this->actingAs($this->player, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$tournament->id}/payment-status?payment_id={$pay['payment_id']}")
            ->assertOk()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('registered', true);

        $participant = $tournament->fresh()->participants()->first();
        $this->assertSame('registered', $participant->pivot->status);
    }

    public function test_чужой_платёж_по_id_не_подсмотреть(): void
    {
        $tournament = $this->tournament();
        $this->fakePlexyLink();

        $pay = $this->actingAs($this->player, 'sanctum')
            ->postJson("/api/mobile/tournaments/{$tournament->id}/pay")->json();

        $stranger = User::factory()->create(['level' => 3.0]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$tournament->id}/payment-status?payment_id={$pay['payment_id']}")
            ->assertStatus(404);
    }
}
