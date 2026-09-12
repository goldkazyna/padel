<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Очередь есть у любого турнира и она безразмерная.
 *
 * Раньше клуб задавал её длину числом, и по умолчанию стояло 0 — то есть
 * очереди не было вовсе: седьмой игрок в турнир на шесть просто уходил.
 * Плюс в платном турнире рядом с оплатой появился вход в очередь: человек
 * готов ждать место, но платить сейчас не хочет.
 */
class TournamentWaitlistFlowTest extends TestCase
{
    use RefreshDatabase;

    private Club $paidClub;
    private Club $freeClub;

    protected function setUp(): void
    {
        parent::setUp();

        $fcm = Mockery::mock(FCMNotificationService::class);
        $fcm->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $fcm);

        $this->paidClub = Club::create([
            'name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы',
            'online_payment_enabled' => true,
            'tournament_payment_enabled' => true,
            'plexy_api_key' => 'pr_test',
        ]);

        $this->freeClub = Club::create(['name' => 'Pulse', 'address' => 'Б', 'city' => 'Алматы']);
    }

    private function tournament(Club $club, array $extra = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => 'open',
            'max_participants' => 4,
            'waitlist_size' => 0,   // как у большинства турниров на проде
            'min_level' => 0, 'max_level' => 10,
            'price' => 0,
        ], $extra));
    }

    private function fill(Tournament $t, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $t->participants()->attach(
                User::factory()->create(['level' => 3.0])->id,
                ['status' => 'registered'],
            );
        }
    }

    // ===== Безразмерная очередь =====

    public function test_очередь_принимает_даже_при_нулевом_размере(): void
    {
        $t = $this->tournament($this->freeClub);
        $this->fill($t, 4);

        $user = User::factory()->create(['level' => 3.0]);
        Sanctum::actingAs($user);

        // Без подтверждения сначала спрашиваем — как и раньше.
        $this->postJson("/api/mobile/tournaments/{$t->id}/register")
            ->assertOk()
            ->assertJsonPath('requires_waitlist_confirmation', true);

        $this->postJson("/api/mobile/tournaments/{$t->id}/register", ['confirm_waitlist' => true])
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame('waiting',
            $t->participants()->where('users.id', $user->id)->first()->pivot->status);
    }

    public function test_очередь_не_упирается_в_потолок(): void
    {
        $t = $this->tournament($this->freeClub, ['waitlist_size' => 1]);
        $this->fill($t, 4);

        foreach (range(1, 5) as $i) {
            Sanctum::actingAs(User::factory()->create(['level' => 3.0]));
            $this->postJson("/api/mobile/tournaments/{$t->id}/register", ['confirm_waitlist' => true])
                ->assertOk()->assertJsonPath('success', true);
        }

        $this->assertSame(5, $t->participants()->wherePivot('status', 'waiting')->count());
    }

    // ===== Очередь как альтернатива оплате =====

    public function test_в_платном_турнире_можно_встать_в_очередь_без_оплаты(): void
    {
        $t = $this->tournament($this->paidClub, ['price' => 14000]);
        $user = User::factory()->create(['level' => 3.0]);

        $this->assertTrue($t->requiresOnlinePayment());

        Sanctum::actingAs($user);
        $this->postJson("/api/mobile/tournaments/{$t->id}/waitlist")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('waitlist_position', 1);

        // Свободные места есть, но человек сознательно встал ждать.
        $this->assertSame('waiting',
            $t->participants()->where('users.id', $user->id)->first()->pivot->status);
        $this->assertSame(0, \App\Models\TournamentPayment::count());
    }

    public function test_дважды_в_очередь_не_встать(): void
    {
        $t = $this->tournament($this->paidClub, ['price' => 14000]);
        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $this->postJson("/api/mobile/tournaments/{$t->id}/waitlist")->assertOk();
        $this->postJson("/api/mobile/tournaments/{$t->id}/waitlist")
            ->assertStatus(400)->assertJsonPath('success', false);
    }

    public function test_чужой_уровень_в_очередь_не_пускают(): void
    {
        $t = $this->tournament($this->paidClub, ['price' => 14000, 'min_level' => 4, 'max_level' => 5]);
        Sanctum::actingAs(User::factory()->create(['level' => 2.0]));

        $this->postJson("/api/mobile/tournaments/{$t->id}/waitlist")->assertStatus(400);
        $this->assertSame(0, $t->participants()->count());
    }

    public function test_в_закрытый_турнир_в_очередь_не_встать(): void
    {
        $t = $this->tournament($this->paidClub, ['price' => 14000, 'status' => 'in_progress']);
        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $this->postJson("/api/mobile/tournaments/{$t->id}/waitlist")->assertStatus(400);
    }

    // ===== Автоподъём очереди =====

    public function test_в_платном_турнире_очередь_сама_не_двигается(): void
    {
        $t = $this->tournament($this->paidClub, ['price' => 14000]);
        $this->fill($t, 4);

        $waiting = User::factory()->create(['level' => 3.0]);
        $t->participants()->attach($waiting->id, ['status' => 'waiting']);

        $leaver = $t->participants()->wherePivot('status', 'registered')->first();
        Sanctum::actingAs($leaver);
        $this->postJson("/api/mobile/tournaments/{$t->id}/cancel")->assertOk();

        $this->assertSame('waiting',
            $t->participants()->where('users.id', $waiting->id)->first()->pivot->status,
            'кого пустить на освободившееся место, решает клуб');
    }

    public function test_в_бесплатном_турнире_очередь_двигается_как_раньше(): void
    {
        $t = $this->tournament($this->freeClub);
        $this->fill($t, 4);

        $waiting = User::factory()->create(['level' => 3.0]);
        $t->participants()->attach($waiting->id, ['status' => 'waiting']);

        $leaver = $t->participants()->wherePivot('status', 'registered')->first();
        Sanctum::actingAs($leaver);
        $this->postJson("/api/mobile/tournaments/{$t->id}/cancel")->assertOk();

        $this->assertSame('pending',
            $t->participants()->where('users.id', $waiting->id)->first()->pivot->status);
    }
}
